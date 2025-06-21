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

use Ilovepdf\Ilovepdf;
use Intervention\Image\Facades\Image;

class VendorDocsController extends Controller
{
    private $nylasService;
    use ProcessesVendorDocs;

    public function __construct(NylasService $nylasService)
    {
        $this->nylasService = $nylasService;
    }

    public function fetchMessagesFromInsuranceMailbox()
    {
        // Fetch grant ID from environment variable
        $grantId = env('HIVE_INSURANCE_GRANT_ID');

        // Define query parameters for the Nylas API
        $queryParams = [
            'limit' => 10, // Fetch up to 100 messages
            'in' => 'inbox', // Specify the inbox folder
        ];

        // Fetch messages using the NylasService
        $messages = $this->nylasService->getMessages($queryParams, $grantId);

        // Filter messages with attachments
        foreach ($messages['data'] as $message) {
            if (!empty($message['attachments'])) {
                $attachments = array_filter($message['attachments'], function ($attachment) {
                    return $attachment['is_inline'] === false;
                });

                // Process attachments
                foreach ($attachments as $attachment) {
                    $messageId = $message['id']; // Ensure you fetch the corresponding message ID
                    $attachmentContent = $this->nylasService->downloadAttachment($attachment['id'], $grantId, $messageId);
                    $docType = pathinfo($attachment['filename'], PATHINFO_EXTENSION);

                    $tempFilePath = "_temp_vendor_docs/attachment_{$attachment['id']}.{$docType}";

                    // Store the file temporarily
                    Storage::disk('files')->put($tempFilePath, $attachmentContent);
                    $tempFilePath = 'files/'.$tempFilePath;
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
    }

    public function moveEmailBasedOnMatchingResults($messageId, $grantId, $matchedVendorId, $matchedBelongsToVendorId)
    {
        $manualAddFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG9ITvDAAA=';
        $processedFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG2_4hNAAA=';
        $failedFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG9ITvBAAA=';

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
                Log::channel('vendor_docs')->error('Failed to move email to any folder', [
                    'message_id' => $messageId,
                    'original_error' => $e->getMessage(),
                    'failed_move_error' => $failedMoveException->getMessage()
                ]);
                return;
            }

            Log::channel('vendor_docs')->error('Failed to move email to intended folder, moved to failed folder instead', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function audit_docs_pdf($files)
    {
        $filename = 'audit-'.auth()->user()->vendor->id.'-'.date('Y-m-d-h-m-s');

        //10-15-2023 Create cover page
        ///////cover page here/// use audit view? csv? table?

        $ilovepdf = new Ilovepdf(env('I_LOVE_PDF_PUBLIC'), env('I_LOVE_PDF_SECRET'));
        // Create a new task
        $myTaskMerge = $ilovepdf->newTask('merge');

        // Add files to task for upload
        foreach ($files as $key => $file) {
            ${'merged_'.$key} = $myTaskMerge->addFile($file);
        }

        // dd($myTaskMerge);
        // $file1 = $myTaskMerge->addFile('/home/vagrant/web/gs/storage/files/vendor_docs/elm_r3.pdf');
        // $file2 = $myTaskMerge->addFile('/home/vagrant/web/gs/storage/files/vendor_docs/elm_r3.pdf');
        // Execute the task
        $myTaskMerge->setOutputFilename($filename);
        $myTaskMerge->execute();
        // $myTaskMerge->download();
        // Download the package files
        $myTaskMerge->download(storage_path('files/vendor_docs/'));

        // //stream/download
        $path = storage_path('files/vendor_docs/'.$filename.'.pdf');
        // $response = Response::make(file_get_contents($path), 200, [
        //     'Content-Type' => 'application/pdf'
        // ]);

        // $response;
        return response()->download($path);
    }

    //1-18-2023 combine the next 2 functions into one. Pass type = original or temp
    //Show full-size receipt to anyone with a link
    // No Middleware or Policies
    //PUBLIC AS FUCK! BE CAREFUL!
    //Also on ReceiptController->original_receipt
    public function document($filename)
    {
        $path = storage_path('files/vendor_docs/'.$filename);

        if (strtolower(File::extension($filename)) === 'pdf') {
            $response = Response::make(file_get_contents($path), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        } else {
            $response = Image::make($path)->response();
        }

        return $response;
    }
}
