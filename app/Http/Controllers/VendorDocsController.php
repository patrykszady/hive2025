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

            // Only the documents: certificate PDFs and scans or photos of
            // certificates. A forward from Outlook carries ATT0002-style
            // fragments, icons and signature logos that are not certificates.
            $documents = $this->certificateDocuments(
                $attachments,
                fn (string $attachmentId) => $this->nylasService->downloadAttachment($attachmentId, $grantId, $messageId),
            );

            // No usable document: move to the deleted (or error) folder
            if (empty($documents)) {
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

            // Each document is read; the message is filed once, after all of
            // them, by their outcome. Filed per document, the last attachment
            // decided: on 2026-10-14 M Power's certificate was saved and the
            // message still went to Error because of three ATT000x fragments.
            $results = [];
            $this->deferMailFiling = true;
            try {
                foreach ($documents as $document) {
                    $docType = $document['type'];
                    $tempFilePath = "_temp_vendor_docs/attachment_{$document['id']}.{$docType}";

                    // Store the file temporarily
                    Storage::disk('files')->put($tempFilePath, $document['content']);
                    $tempFilePath = 'files/'.$tempFilePath;

                    if ($docType === 'pdf') {
                        $splitPaths = $this->splitPdfIntoPages(ltrim($tempFilePath, 'files/'), $document['id']);
                        if (!empty($splitPaths)) {
                            foreach ($splitPaths as $index => $splitPath) {
                                $pageLabel = 'page-'.((int) $index + 1);
                                $results[] = $this->handleVendorDocProcessing(
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
                    $results[] = $this->handleVendorDocProcessing(
                        $tempFilePath,
                        $docType,
                        $context['vendor_id'] ?? null,
                        $context['belongs_to_vendor_id'] ?? null,
                        $messageId,
                        $grantId
                    );
                }
            } finally {
                $this->deferMailFiling = false;
            }

            // true: saved; 'duplicate': already on file; 'updated': a state license refreshed.
            $this->fileMessage($messageId, $grantId, collect($results)->every(fn ($result) => in_array($result, [true, 'duplicate', 'updated'], true)));
        }
    }

    /** While a message's documents are read, the message is filed once at the end (fileMessage), not by each one. */
    protected bool $deferMailFiling = false;

    /** The smallest image read as a certificate scan or photo; signature logos (M Power's: 51 KB) and icons are smaller. */
    public const MIN_IMAGE_BYTES = 100_000;

    /** A scan or photo of a page: its shorter side at least this many pixels… */
    public const MIN_IMAGE_SIDE = 600;

    /** …and its longer side at most this many times the shorter (a letter page is 1.29, a phone photo 1.33; banners are wider). */
    public const MAX_IMAGE_RATIO = 2.0;

    /**
     * The attachments that are certificates: every PDF — named .pdf, sent as
     * application/pdf, or an unnamed file whose content is a PDF — and every
     * image that is a scan or photo of a page (isCertificateScan), with or
     * without a PDF beside it. Everything else is skipped and logged:
     * Outlook's ATT0002 fragments, signature logos, 20x13 icons, calendar
     * files.
     *
     * @param  list<array<string, mixed>>  $attachments  Nylas attachments (id, filename, content_type, size)
     * @param  callable(string): string  $download  the attachment's bytes by id
     * @return list<array{id: string, type: string, content: string}>
     */
    public function certificateDocuments(array $attachments, callable $download): array
    {
        $documents = [];

        foreach ($attachments as $attachment) {
            $id = (string) ($attachment['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = (string) ($attachment['filename'] ?? '');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $contentType = strtolower((string) ($attachment['content_type'] ?? ''));
            $size = (int) ($attachment['size'] ?? 0);

            if ($contentType === 'application/pdf' || $extension === 'pdf') {
                $documents[] = ['id' => $id, 'type' => 'pdf', 'content' => (string) $download($id)];

                continue;
            }

            if (str_starts_with($contentType, 'image/') || in_array($extension, ['png', 'jpg', 'jpeg', 'heic', 'webp', 'tif', 'tiff'], true)) {
                // Too small to be a page: skipped without downloading it.
                if ($size > 0 && $size < self::MIN_IMAGE_BYTES) {
                    Log::channel('vendor_docs')->info('Insurance mailbox: image skipped, not a certificate scan', ['filename' => $name, 'bytes' => $size]);

                    continue;
                }
                $content = (string) $download($id);
                if (! $this->isCertificateScan($content, $size ?: strlen($content))) {
                    Log::channel('vendor_docs')->info('Insurance mailbox: image skipped, not a certificate scan', ['filename' => $name, 'bytes' => $size ?: strlen($content)]);

                    continue;
                }
                $type = $extension !== '' ? $extension : match ($contentType) {
                    'image/png' => 'png',
                    'image/heic' => 'heic',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $documents[] = ['id' => $id, 'type' => $type, 'content' => $content];

                continue;
            }

            // A file with no extension may still be a PDF; anything else is not a certificate.
            if ($extension === '') {
                $content = (string) $download($id);
                if (str_starts_with(ltrim($content), '%PDF')) {
                    $documents[] = ['id' => $id, 'type' => 'pdf', 'content' => $content];

                    continue;
                }
            }

            Log::channel('vendor_docs')->info('Insurance mailbox: attachment skipped, not a certificate', ['filename' => $name, 'content_type' => $contentType]);
        }

        return $documents;
    }

    /**
     * An image is read as a certificate when it is a scan or photo of a page:
     * large enough, its shorter side long enough and its shape page-like.
     * Logos, signature banners, headshots and icons fail one of the three.
     * An image whose size cannot be read (HEIC, TIFF) is judged by its bytes.
     */
    public function isCertificateScan(string $content, int $bytes): bool
    {
        if ($bytes < self::MIN_IMAGE_BYTES) {
            return false;
        }

        $info = @getimagesizefromstring($content);
        if (! is_array($info) || empty($info[0]) || empty($info[1])) {
            return true;
        }

        $short = min($info[0], $info[1]);
        $long = max($info[0], $info[1]);

        return $short >= self::MIN_IMAGE_SIDE && $long / $short <= self::MAX_IMAGE_RATIO;
    }

    /** File a message once its documents are read: saved when every one was, else to the error folder for a person. */
    protected function fileMessage(string $messageId, string $grantId, bool $allSaved): void
    {
        $folderId = $allSaved ? config('nylas.certificates_saved_folder_id') : config('nylas.certificates_error_folder_id');

        try {
            $this->nylasService->moveEmailToFolder($messageId, $folderId, $grantId);
        } catch (\Exception $e) {
            Log::channel('vendor_docs')->error('Failed to file insurance email', ApiErrorFormatter::format($e, [
                'message_id' => $messageId,
                'all_saved' => $allSaved,
            ]));
        }
    }

    protected function splitPdfIntoPages(string $relativePath, string $attachmentId): array
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
        // Reading a message's documents: it is filed once, after all of them.
        if ($this->deferMailFiling) {
            return;
        }

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
