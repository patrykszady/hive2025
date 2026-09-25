<?php

namespace App\Http\Controllers;

use App\Services\NylasService;

use App\Traits\ProcessesVendorDocs;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

use File;
use Response;
use Exception;
use App\Support\ApiErrorFormatter;
use App\Support\StoredFile;

use Ilovepdf\Ilovepdf;
use Intervention\Image\Facades\Image;

class VendorDocsController extends Controller
{
    private $nylasService;
    use ProcessesVendorDocs;

    public function __construct(NylasService $nylasService)
    {
        $this->nylasService = $nylasService;

    // Ensure vendor docs streaming is only accessible to authenticated users with vendor access
    $this->middleware(['auth', 'vendor.access'])->only('document');
    }

    public function fetchMessagesFromInsuranceMailbox()
    {
        $grantId = config('nylas.certificates_grant_id');

        // Define query parameters for the Nylas API
        $queryParams = [
            'limit' => 10, // Fetch up to 100 messages
            'in' => config('nylas.certificates_inbox_folder_id'), // Specify the inbox folder
        ];

        // Fetch messages using the NylasService
        $messages = $this->nylasService->getMessages($grantId, $queryParams);
    
        // move messages without usable attachments
        $deletedFolderId = config('nylas.certificates_deleted_folder_id');

        // Loop messages and process/move accordingly
        foreach (($messages['data'] ?? []) as $message) {
            $messageId = $message['id'] ?? null;
            if (! $messageId) {
                continue;
            }

            $rawAttachments = $message['attachments'] ?? [];

            // Keep only non-inline attachments
            $attachments = array_values(array_filter($rawAttachments, function ($attachment) {
                return ($attachment['is_inline'] ?? false) === false;
            }));

            // If no non-inline attachments, move to the deleted (or error) folder
            if (empty($attachments)) {
                try {
                    $this->nylasService->moveEmailToFolder($messageId, $deletedFolderId, $grantId);
                } catch (\Exception $e) {
                    Log::channel('vendor_docs')->error('Failed to move attachment-less insurance email', ApiErrorFormatter::format($e, [
                        'message_id' => $messageId,
                        'target_folder_id' => $deletedFolderId,
                    ]));
                }
                continue;
            }

            // Who the message says this is for — a reply to our request, the
            // vendor named in the subject, the vendor CC'd — decided before
            // the certificate is read. See InsuranceReplyContext.
            $context = app(\App\Services\InsuranceReplyContext::class)->resolve($message);

            if ($context !== null) {
                Log::channel('vendor_docs')->info('Insurance mailbox: vendor from message context', [
                    'message_id' => $messageId,
                    'subject' => $message['subject'] ?? null,
                ] + $context);
            }

            // Process each non-inline attachment
            foreach ($attachments as $attachment) {
                $attachmentId = $attachment['id'] ?? null;
                if (! $attachmentId) {
                    continue;
                }

                $attachmentContent = $this->nylasService->downloadAttachment($attachmentId, $grantId, $messageId);
                $docType = pathinfo($attachment['filename'] ?? ('attachment_'.$attachmentId), PATHINFO_EXTENSION) ?: 'pdf';

                $tempFilePath = "_temp_vendor_docs/attachment_{$attachmentId}.{$docType}";

                // Store the file temporarily
                Storage::disk('files')->put($tempFilePath, $attachmentContent);
                $tempFilePath = 'files/'.$tempFilePath;

                if ($docType === 'pdf') {
                    $splitPaths = $this->splitPdfIntoPages(ltrim($tempFilePath, 'files/'), $attachmentId);
                    if (!empty($splitPaths)) {
                        foreach ($splitPaths as $index => $splitPath) {
                            $pageLabel = 'page-'.((int) $index + 1);
                            $this->handleVendorDocProcessing(
                                'files/'.$splitPath,
                                $docType,
                                $context['vendor_id'] ?? null,
                                $context['belongs_to_vendor_id'] ?? null,
                                $messageId,
                                $grantId,
                                $pageLabel
                            );
                        }
                        continue;
                    }
                }

                // Process the document
                $this->handleVendorDocProcessing(
                    $tempFilePath,
                    $docType,
                    $context['vendor_id'] ?? null,
                    $context['belongs_to_vendor_id'] ?? null,
                    $messageId,
                    $grantId
                );
            }
        }
    }

    private function splitPdfIntoPages(string $relativePath, string $attachmentId): array
    {
        $publicKey = env('I_LOVE_PDF_PUBLIC');
        $secretKey = env('I_LOVE_PDF_SECRET');

        if (empty($publicKey) || empty($secretKey)) {
            Log::channel('vendor_docs')->warning('Missing iLovePDF credentials - skipping split', [
                'file' => $relativePath,
            ]);
            return [];
        }

        $absolutePath = Storage::disk('files')->path($relativePath);
        if (!file_exists($absolutePath)) {
            Log::channel('vendor_docs')->warning('Split source file missing', [
                'file' => $relativePath,
            ]);
            return [];
        }

        $splitDir = "_temp_vendor_docs/split_{$attachmentId}";
        Storage::disk('files')->makeDirectory($splitDir);
        $splitAbsDir = Storage::disk('files')->path($splitDir);

        try {
            $ilovepdf = new Ilovepdf($publicKey, $secretKey);
            $task = $ilovepdf->newTask('split');
            $task->addFile($absolutePath);
            $task->setFixedRange(1);
            $task->setPackagedFilename('split_'.$attachmentId);
            $task->setOutputFilename('page');
            $task->execute();

            $zipContent = $task->blob();
            $zipPath = $splitAbsDir.'/split.zip';
            file_put_contents($zipPath, $zipContent);

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($splitAbsDir);
                $zip->close();
            } else {
                Log::channel('vendor_docs')->warning('Failed to open split zip', [
                    'file' => $relativePath,
                    'zip' => $zipPath,
                ]);
                return [];
            }

            $files = collect(File::files($splitAbsDir))
                ->filter(function ($file) {
                    return strtolower($file->getExtension()) === 'pdf';
                })
                ->sortBy(fn ($file) => $file->getFilename())
                ->values();

            $diskRoot = Storage::disk('files')->path('');
            return $files->map(function ($file) use ($diskRoot): string {
                $relative = str_replace($diskRoot, '', $file->getPathname());
                return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/');
            })->all();
        } catch (Exception $e) {
            Log::channel('vendor_docs')->error('Failed to split PDF', ApiErrorFormatter::format($e, [
                'file' => $relativePath,
            ]));
        }

        return [];
    }

    public function moveEmailBasedOnMatchingResults($messageId, $grantId, $matchedVendorId, $matchedBelongsToVendorId)
    {
        $manualAddFolderId = config('nylas.certificates_error_folder_id');
        $processedFolderId = config('nylas.certificates_saved_folder_id');
        $failedFolderId = config('nylas.certificates_error_folder_id');

        try {
            if (is_null($matchedVendorId) || is_null($matchedBelongsToVendorId)) {
                // Move to manual add folder when vendor matching fails
                $this->nylasService->moveEmailToFolder($messageId, $manualAddFolderId, $grantId);
            } else {
                // Move to processed folder when vendor matching succeeds
                $this->nylasService->moveEmailToFolder($messageId, $processedFolderId, $grantId);
            }
        } catch (\Exception $e) {
            // If moving fails, try to move to failed folder
            try {
                $this->nylasService->moveEmailToFolder($messageId, $failedFolderId, $grantId);
            } catch (\Exception $failedMoveException) {
                Log::channel('vendor_docs')->error('Failed to move email to any folder', ApiErrorFormatter::format($failedMoveException, [
                    'message_id' => $messageId,
                    'original_error' => $e->getMessage(),
                ]));
                return;
            }

            Log::channel('vendor_docs')->error('Failed to move email to intended folder, moved to failed folder instead', ApiErrorFormatter::format($e, [
                'message_id' => $messageId,
            ]));
        }
    }

    // Stream a vendor document (pdf/image) with case-insensitive lookup.
    // Access restricted via auth + vendor.access middleware (controller-level and route-level).
    public function document($filename)
    {
        // Extra guard (middleware already enforces this)
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $resolvedPath = StoredFile::resolve(storage_path('files/vendor_docs'), (string) $filename);

        if (! $resolvedPath) {
            return response('File not found', 404);
        }

        $filename = $resolvedPath;

        $ext = strtolower(File::extension($filename));
        if ($ext === 'pdf') {
            return Response::make(file_get_contents($resolvedPath), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        }

        return Image::make($resolvedPath)->response();
    }

    /**
     * Public (signed) version of smsMedia for sharing via SMS link.
     * No auth required; URL must carry a valid Laravel signature.
     */
    public function smsMediaPublic(\Illuminate\Http\Request $request, $filename)
    {
        if (! $request->hasValidSignature()) {
            return response('Link expired or invalid', 403);
        }

        return $this->streamSmsMedia($filename);
    }

    /**
     * Stream an SMS media file (video, audio, image).
     * Access restricted to authenticated users.
     */
    public function smsMedia($filename)
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        return $this->streamSmsMedia($filename);
    }

    /**
     * Shared streaming logic for SMS media (auth + public signed routes).
     */
    protected function streamSmsMedia($filename)
    {
        // A name may carry its folder (sms-attachments/… or sms-media/…);
        // otherwise it lives in sms-media/. Nothing outside those two folders
        // is ever served, whatever the name says.
        $relative = ltrim(str_replace('\\', '/', (string) $filename), '/');
        $folder = 'sms-media';

        foreach (['sms-attachments', 'sms-media'] as $known) {
            if (str_starts_with($relative, $known.'/')) {
                $folder = $known;
                $relative = substr($relative, strlen($known) + 1);
                break;
            }
        }

        $resolvedPath = null;

        foreach ([storage_path('files/'.$folder), storage_path('app/public/'.$folder)] as $baseDir) {
            $resolvedPath = StoredFile::resolve($baseDir, $relative, allowSubdirectories: true);

            if ($resolvedPath) {
                break;
            }
        }

        $resolvedFilename = $resolvedPath;

        if (! $resolvedPath) {
            return response('File not found', 404);
        }

        $filename = $resolvedFilename ?? $filename;
        $ext = strtolower(File::extension($filename));
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            '3gp' => 'video/3gpp',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'ogv' => 'video/ogg',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'amr' => 'audio/amr',
        ];

        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'], true)) {
            // Grids ask for ?thumb=1 and get a small copy built once and kept.
            if (request()->boolean('thumb')) {
                $thumb = \App\Support\ImageThumbs::path(
                    $resolvedPath.':'.filesize($resolvedPath).':'.filemtime($resolvedPath),
                    fn () => $resolvedPath,
                );

                if ($thumb) {
                    return response()->file($thumb, \App\Support\ImageThumbs::headers());
                }
            }

            // Otherwise the file as it is. It used to be decoded and re-encoded
            // through Intervention on EVERY request — no resize, no format
            // change, just cost — and answered without a cache header, so every
            // view paid it again. Send the bytes and let the browser keep them.
            return response()->file($resolvedPath, [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=604800, immutable',
            ]);
        }

        // For video/audio/pdf, stream with proper headers and range request support
        $size = filesize($resolvedPath);

        return response()->stream(function () use ($resolvedPath) {
            $stream = fopen($resolvedPath, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => $size,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
