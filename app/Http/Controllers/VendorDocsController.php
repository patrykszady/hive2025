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
                                null,
                                null,
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
                    null, // Placeholder for $vendorId
                    null, // Placeholder for $belongsToVendorId
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

        // Try multiple case variants to cope with mixed-case extensions or names
        $candidates = [
            $filename,
            strtolower($filename),
            strtoupper($filename),
        ];

        $resolvedPath = null;
        foreach ($candidates as $name) {
            $try = storage_path('files/vendor_docs/'.$name);
            if (file_exists($try)) {
                $resolvedPath = $try;
                $filename = $name; // normalize for extension check
                break;
            }
        }

        if (! $resolvedPath) {
            return response('File not found', 404);
        }

        $ext = strtolower(File::extension($filename));
        if ($ext === 'pdf') {
            return Response::make(file_get_contents($resolvedPath), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        }

        return Image::make($resolvedPath)->response();
    }
}
