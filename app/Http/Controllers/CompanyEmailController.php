<?php

namespace App\Http\Controllers;

use App\Models\CompanyEmail;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\Vendor;

use App\Services\NylasService;
use App\Services\AzureDocumentService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

use Carbon\Carbon;
use Spatie\Browsershot\Browsershot;
use Intervention\Image\Facades\Image;

use Exception;
use App\Support\ApiErrorFormatter;
 
class CompanyEmailController extends Controller
{
    private $nylasService;
    protected $azureDocumentService;

    /**
     * Inject the NylasService into the controller.
     */
    public function __construct(NylasService $nylasService, AzureDocumentService $azureDocumentService)
    {
        $this->nylasService = $nylasService;
        $this->azureDocumentService = $azureDocumentService;
    }

    /**
     * Redirect the user to the Nylas authentication page.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function nylasLogin()
    {
        try {
            $authUrl = $this->nylasService->getAuthUrl();
            return redirect($authUrl['authentication_url']);
        } catch (Exception $e) {
            Log::channel('nylas')->error('Failed to retrieve Nylas authentication URL', ApiErrorFormatter::format($e));
            return redirect()->back()->withErrors(['error' => 'Unable to initiate authentication with Nylas.']);
        }
    }

    /**
     * Handle the authentication response from Nylas.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function nylasAuthResponse(Request $request)
    {
        // Decode state to check if popup mode
        $isPopup = false;
        if ($request->has('state')) {
            $stateJson = base64_decode($request->query('state'));
            $state = json_decode($stateJson, true);
            $isPopup = $state['popup'] ?? false;
        }
        
        if ($request->has('error')) {
            Log::channel('nylas')->error(["Failed to nylasAuthResponse: ", $request->all()]);
            
            // If opened in popup, show error and allow manual close
            if ($isPopup) {
                return view('nylas.auth-error', [
                    'error' => $request->query('error'),
                ]);
            }
            
            return redirect()->back()->withErrors(['error' => $request->query('error')]);
        }

        $code = $request->query('code');

        try {
            // Exchange auth code for a token
            $nylasAccount = $this->nylasService->exchangeAuthCodeForToken($code);

            if (isset($nylasAccount['email'])) {
                // Save the account to the database
                $this->saveAccountToDatabase($nylasAccount);

                // Single HIVE RECEIPTS folder is created during company email setup
                // Folder ID is stored in api_json['HIVE_RECEIPTS_FOLDER'] for reference

                // If opened in popup, notify parent and auto-close
                if ($isPopup) {
                    return view('nylas.auth-success', [
                        'email' => $nylasAccount['email'],
                    ]);
                }
                
                return redirect(route('company_emails.index'))->with('success', 'Nylas account connected successfully.');
            } else {
                // If opened in popup, show error
                if ($isPopup) {
                    return view('nylas.auth-error', [
                        'error' => 'Failed to retrieve account details from Nylas.',
                    ]);
                }
                
                return redirect()->back()->withErrors(['error' => 'Failed to retrieve account details from Nylas.']);
            }
        } catch (\Exception $e) {
            Log::channel('nylas')->error('Failed to handle Nylas authentication response', ApiErrorFormatter::format($e, [
                'code' => $code,
            ]));
            
            // If opened in popup, show error
            if ($isPopup) {
                return view('nylas.auth-error', [
                    'error' => 'An error occurred during Nylas authentication.',
                ]);
            }
            
            return redirect()->back()->withErrors(['error' => 'An error occurred during Nylas authentication.']);
        }
    }

    /**
     * Save the Nylas account details to the database.
     *
     * @param array $nylasAccount
     * @return void
     */
    private function saveAccountToDatabase(array $nylasAccount)
    {
        // Check if the account already exists in the database
        $existingCompanyEmail = CompanyEmail::withoutGlobalScopes()
            ->where('email', $nylasAccount['email'])
            ->first();

        if ($existingCompanyEmail) {
            //redirect to company_emails.index
            //4-2-2025 this error only if email in vendor->compnay_emails, otherwise "cannot add email"
            // ->with('error', 'Email already exists in the database.');
            // Handle the case where the email already exists
            Log::warning(["Nylas account already exists:", $nylasAccount]);
            return redirect(route('company_emails.index'));
        } else {
            // Create the CompanyEmail record
            $companyEmail = CompanyEmail::create([
                'email' => $nylasAccount['email'],
                'grant_id' => $nylasAccount['grant_id'],
                'vendor_id' => auth()->user()->vendor->id, // Associate with the authenticated user's vendor
            ]);

            // Create the "HIVE RECEIPTS" folder and store its ID in api_json
            $this->createHiveReceiptsFolder($companyEmail);
            
            // Sync all existing client contacts to this new grant
            $this->syncContactsForNewGrant($companyEmail);
        }
    }
    
    /**
     * Sync all existing vendor clients to a newly created company email grant
     */
    private function syncContactsForNewGrant(CompanyEmail $companyEmail): void
    {
        try {
            $vendor = $companyEmail->vendor;
            $contactSyncService = app(\App\Services\NylasContactSyncService::class);
            
            // Get all clients for this vendor
            $clients = $vendor->clients;
            
            if ($clients->isEmpty()) {
                Log::channel('nylas')->info('No clients to sync for new grant', [
                    'grant_id' => $companyEmail->grant_id,
                    'vendor_id' => $vendor->id,
                ]);
                return;
            }
            
            Log::channel('nylas')->info('Syncing existing clients to new grant', [
                'grant_id' => $companyEmail->grant_id,
                'vendor_id' => $vendor->id,
                'client_count' => $clients->count(),
            ]);
            
            // Sync each client's users to the new grant
            foreach ($clients as $client) {
                $contactSyncService->syncUserContactsForClient($client);
            }
            
            Log::channel('nylas')->info('Completed syncing clients to new grant', [
                'grant_id' => $companyEmail->grant_id,
                'vendor_id' => $vendor->id,
            ]);
        } catch (\Exception $e) {
            Log::channel('nylas')->error('Failed to sync contacts for new grant', [
                'grant_id' => $companyEmail->grant_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create or find the "HIVE RECEIPTS" folder for a company email and store the folder ID
     */
    private function createHiveReceiptsFolder(CompanyEmail $companyEmail): void
    {
        $folderId = null;
        $inboxFolderId = null;
        
        try {
            Log::channel('nylas')->info('Starting HIVE RECEIPTS folder setup', [
                'company_email_id' => $companyEmail->id,
                'grant_id' => $companyEmail->grant_id,
                'email' => $companyEmail->email,
            ]);
            
            // First, try to find existing "HIVE RECEIPTS" folder
            $foldersResult = $this->nylasService->getFolders($companyEmail->grant_id);
            
            Log::channel('nylas')->info('getFolders result', [
                'company_email_id' => $companyEmail->id,
                'grant_id' => $companyEmail->grant_id,
                'status' => $foldersResult['status'] ?? 'unknown',
                'has_data' => isset($foldersResult['data']),
            ]);
            
            // Only proceed if we successfully got folders (200 status)
            if ($foldersResult['status'] === 200 && isset($foldersResult['data']['data'])) {
                // Search for existing "HIVE RECEIPTS" folder and Inbox folder
                foreach ($foldersResult['data']['data'] as $folder) {
                    if (isset($folder['name']) && $folder['name'] === 'HIVE RECEIPTS') {
                        $folderId = $folder['id'];
                        Log::channel('nylas')->info('Found existing HIVE RECEIPTS folder', [
                            'company_email_id' => $companyEmail->id,
                            'grant_id' => $companyEmail->grant_id,
                            'folder_id' => $folderId,
                        ]);
                    }
                    
                    if (isset($folder['name']) && strtolower($folder['name']) === 'inbox') {
                        $inboxFolderId = $folder['id'];
                        Log::channel('nylas')->info('Found Inbox folder', [
                            'company_email_id' => $companyEmail->id,
                            'grant_id' => $companyEmail->grant_id,
                            'inbox_folder_id' => $inboxFolderId,
                        ]);
                    }
                    
                    // Break early if we found both
                    if ($folderId && $inboxFolderId) {
                        break;
                    }
                }
                
                // If folder doesn't exist, create it
                if (!$folderId) {
                    Log::channel('nylas')->info('Creating new HIVE RECEIPTS folder', [
                        'company_email_id' => $companyEmail->id,
                        'grant_id' => $companyEmail->grant_id,
                    ]);
                    
                    $folderResult = $this->nylasService->createFolder($companyEmail->grant_id, 'HIVE RECEIPTS');
                    
                    Log::channel('nylas')->info('createFolder result', [
                        'company_email_id' => $companyEmail->id,
                        'grant_id' => $companyEmail->grant_id,
                        'status' => $folderResult['status'] ?? 'unknown',
                        'has_data' => isset($folderResult['data']),
                    ]);
                    
                    if ($folderResult['status'] === 200 || $folderResult['status'] === 201) {
                        $folderId = $folderResult['data']['id'] ?? null;
                        
                        if ($folderId) {
                            Log::channel('nylas')->info('HIVE RECEIPTS folder created successfully', [
                                'company_email_id' => $companyEmail->id,
                                'grant_id' => $companyEmail->grant_id,
                                'folder_id' => $folderId,
                            ]);
                        } else {
                            Log::channel('nylas')->error('HIVE RECEIPTS folder created but no folder ID returned', [
                                'company_email_id' => $companyEmail->id,
                                'grant_id' => $companyEmail->grant_id,
                                'folder_result' => $folderResult,
                            ]);
                        }
                    } elseif ($folderResult['status'] === 409) {
                        // Folder already exists (conflict) - try to find it again
                        Log::channel('nylas')->warning('Folder already exists (409 Conflict) - searching again', [
                            'company_email_id' => $companyEmail->id,
                            'grant_id' => $companyEmail->grant_id,
                        ]);
                        
                        // Refresh folder list and search again
                        $retryFoldersResult = $this->nylasService->getFolders($companyEmail->grant_id);
                        
                        if ($retryFoldersResult['status'] === 200 && isset($retryFoldersResult['data']['data'])) {
                            foreach ($retryFoldersResult['data']['data'] as $folder) {
                                if (isset($folder['name']) && $folder['name'] === 'HIVE RECEIPTS') {
                                    $folderId = $folder['id'];
                                    Log::channel('nylas')->info('Found HIVE RECEIPTS folder after 409 conflict', [
                                        'company_email_id' => $companyEmail->id,
                                        'grant_id' => $companyEmail->grant_id,
                                        'folder_id' => $folderId,
                                    ]);
                                    break;
                                }
                            }
                        }
                        
                        if (!$folderId) {
                            Log::channel('nylas')->error('Could not find HIVE RECEIPTS folder even after 409 conflict', [
                                'company_email_id' => $companyEmail->id,
                                'grant_id' => $companyEmail->grant_id,
                            ]);
                        }
                    } else {
                        Log::channel('nylas')->warning('Failed to create HIVE RECEIPTS folder - will retry later', [
                            'company_email_id' => $companyEmail->id,
                            'grant_id' => $companyEmail->grant_id,
                            'status' => $folderResult['status'],
                            'error' => $folderResult['error'] ?? 'Unknown error',
                        ]);
                    }
                }
            } else {
                // Log warning but don't fail - folder can be created later
                Log::channel('nylas')->warning('Unable to fetch folders during setup - HIVE RECEIPTS folder will be created on first use', [
                    'company_email_id' => $companyEmail->id,
                    'grant_id' => $companyEmail->grant_id,
                    'status' => $foldersResult['status'] ?? 'unknown',
                    'error' => $foldersResult['error'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            // Log but don't throw - folder creation can be retried later
            Log::channel('nylas')->error('Exception during HIVE RECEIPTS folder setup', ApiErrorFormatter::format($e, [
                'company_email_id' => $companyEmail->id,
                'grant_id' => $companyEmail->grant_id,
                'folder_id_found' => $folderId,
            ]));
        }
        
        // Store folder IDs in api_json if we have them - do this OUTSIDE the try-catch
        // to ensure it happens even if there was an error earlier
        if ($folderId || $inboxFolderId) {
            try {
                $apiJson = $companyEmail->api_json ?? [];
                
                if ($folderId) {
                    $apiJson['HIVE_RECEIPTS_FOLDER'] = $folderId;
                }
                
                if ($inboxFolderId) {
                    $apiJson['INBOX_FOLDER'] = $inboxFolderId;
                }
                
                $updated = $companyEmail->update(['api_json' => $apiJson]);
                
                Log::channel('nylas')->info('Updated api_json with folder IDs', [
                    'company_email_id' => $companyEmail->id,
                    'hive_receipts_folder_id' => $folderId,
                    'inbox_folder_id' => $inboxFolderId,
                    'update_success' => $updated,
                ]);
            } catch (\Exception $e) {
                Log::channel('nylas')->error('Failed to update api_json with folder IDs', ApiErrorFormatter::format($e, [
                    'company_email_id' => $companyEmail->id,
                    'folder_id' => $folderId,
                ]));
            }
        } else {
            Log::channel('nylas')->warning('No folder ID to save in api_json', [
                'company_email_id' => $companyEmail->id,
                'grant_id' => $companyEmail->grant_id,
            ]);
        }
    }

    /**
     * Fetch consolidated orders for all emails with grant_id.
     */
    // public function fetchConsolidatedOrders()
    // {
    //     dd('fetchConsolidatedOrders');
    //     // Fetch all CompanyEmail records with a grant_id
    //     $companyEmails = CompanyEmail::withoutGlobalScopes()->whereNotNull('grant_id')->get();

    //     $results = []; // Array to store responses

    //     foreach ($companyEmails as $companyEmail) {
    //         $grantId = $companyEmail->grant_id; // Extract the grant_id

    //         // Call the NylasService's method for each grant_id
    //         $consolidatedOrder = $this->nylasService->getConsolidatedOrder($grantId);

    //         dd($consolidatedOrder); // Debugging: dump the consolidated order
    //         // Append the result
    //         $results[] = [
    //             'email_id' => $companyEmail->id,
    //             'grant_id' => $grantId,
    //             'consolidated_order' => $consolidatedOrder,
    //         ];
    //     }

    //     // Return results as a JSON response
    //     return response()->json([
    //         'success' => true,
    //         'data' => $results,
    //     ]);
    // }

    public function fetchReceiptMessages()
    {
        $grantId = config('nylas.receipts_grant_id');

        // Use the configured inbox folder ID (production) or test folder (non-production)
        $folder = env('APP_ENV') === 'production'
            ? config('nylas.receipts_inbox_folder_id')
            : (config('nylas.hive_receipts_test_folder_id') ?: config('nylas.receipts_inbox_folder_id'));

        $allMessages = $this->nylasService->fetchFolderMessages($grantId, $folder, [
            'full_fetch' => true,
            'include_headers' => true,
        ]);

        foreach($allMessages as $message) {
            // Display message structure without rendering HTML body
            $messageDisplay = $message;
            if (isset($messageDisplay['body'])) {
                $messageDisplay['body'] = '[HTML CONTENT - ' . strlen($message['body']) . ' chars]';
            }
            $messageId = $message['id'];

            // ALL messages in receipts inbox must have X-Hive-Metadata header (from forwarding system)
            // Messages without this header should not be here and will be moved to error folder
            $hiveMeta = null;
            if (isset($message['headers']) && is_array($message['headers'])) {
                foreach ($message['headers'] as $hdr) {
                    if (isset($hdr['name']) && strcasecmp($hdr['name'], 'X-Hive-Metadata') === 0) {
                        $decoded = json_decode($hdr['value'] ?? '', true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $hiveMeta = $decoded;
                        }
                        break;
                    }
                }
            }

            if (!$hiveMeta) {
                // hiveMeta expected; move to Error folder and skip
                $errorFolderId = config('nylas.hive_receipts_error_folder_id');
                if ($errorFolderId) {
                    try {
                        $this->nylasService->moveEmailToFolder($messageId, $errorFolderId, $grantId, null);
                    } catch (\Throwable $e) {
                        Log::channel('nylas')->error('Failed moving message without X-Hive-Metadata to error folder', [
                            'grant_id' => $grantId,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::channel('nylas')->warning('Missing error folder config for message lacking X-Hive-Metadata header', [
                        'grant_id' => $grantId,
                        'message_id' => $messageId,
                    ]);
                }
                continue;
            }

            // Use hiveMeta directly (guaranteed present if we reach here)
            $fromEmail = $hiveMeta['from_email'];
            $toEmail = $hiveMeta['to_email'];
            $subject = $hiveMeta['subject'];           
            $dateEmail = Carbon::createFromTimestamp((int) $hiveMeta['unix_date'])
                ->setTimezone('America/Chicago')
                ->format('Y-m-d');

            $companyEmail = CompanyEmail::withoutGlobalScopes()
                ->where('email', $toEmail)
                ->first();

            if (!$companyEmail) {
                Log::channel('nylas')->error('Unable to resolve CompanyEmail for receipt message', [
                    'grant_id' => $grantId,
                    'to_email' => $toEmail,
                    'message_id' => $messageId,
                ]);
                continue;
            }

            $folderMap = [
                'Error' => data_get($companyEmail->api_json, 'folders.Error') ?? config('nylas.hive_receipts_error_folder_id'),
                'Duplicate' => data_get($companyEmail->api_json, 'folders.Duplicates') ?? config('nylas.hive_receipts_duplicate_folder_id'),
                'Add' => data_get($companyEmail->api_json, 'folders.Add') ?? config('nylas.hive_receipts_need_to_add_folder_id'),
                'Saved' => data_get($companyEmail->api_json, 'folders.Saved') ?? config('nylas.hive_receipts_saved_folder_id'),
            ];

            // Find Receipt using intelligent matching:
            // 1. from_address can be wildcard like "@stripe.com" (matches any email ending with @stripe.com)
            // 2. from_subject can be partial match (e.g., "AT&T payment processed" matches "AT&T payment processed for account ending in 1733")
            $receipt = $this->findMatchingReceipt($fromEmail, $subject);

            // If no matching receipt found, move to add_manually folder
            if (!$receipt) {
                $this->nylasService->moveEmailToFolder($messageId, $folderMap['Add'], $grantId, $companyEmail->id);
                    Log::channel('nylas')->info('No matching receipt found - moved to add_manually folder', [
                        'message_id' => $messageId,
                        'from_email' => $fromEmail,
                        'subject' => $subject,
                    ]);
                continue;
            } elseif ($receipt) {
                $string = $message['body'];

                // Check if the body contains HTML
                $bodyType = strip_tags($string) !== $string ? 'html' : 'text';

                // Handle images
                $image_email_url = null;
                if (isset($receipt->options['receipt_image_regex'])) {
                    // Fix JSON-loaded regex by evaluating it properly
                    $pattern = stripslashes($receipt->options['receipt_image_regex']);
                    preg_match($pattern, $string, $matches);
                    $image_email_url = isset($matches[1]) ? html_entity_decode($matches[1]) : null;
                } else {
                    $string = preg_replace("/<img[^>]+\>/i", '', $string);
                }

                // Determine receipt start
                $receipt_start = 0;
                $receipt_start_text = '';
                if (!empty($receipt->options['receipt_start'])) {
                    $starts = is_array($receipt->options['receipt_start'])
                        ? $receipt->options['receipt_start']
                        : [$receipt->options['receipt_start']];

                    foreach ($starts as $start_text) {
                        $pos = strpos($string, $start_text);
                        if (is_numeric($pos)) {
                            // Include the "receipt_start" text or start after it, based on offset
                            $receipt_start = $pos + (isset($receipt->options['receipt_start_offset'])
                                ? intval($receipt->options['receipt_start_offset']) + strlen($start_text)
                                : strlen($start_text));
                            $receipt_start_text = $start_text; // Store the matched text for clarity
                            break; // Exit the loop once a match is found
                        }
                    }
                }

                // Determine receipt end
                $receipt_end = strlen($string);
                if (!empty($receipt->options['receipt_end'])) {
                    $ends = is_array($receipt->options['receipt_end']) ? $receipt->options['receipt_end'] : [$receipt->options['receipt_end']];
                    foreach ($ends as $end_text) {
                        if (is_numeric($pos = strpos($string, $end_text, $receipt_start))) {
                            $receipt_end = $pos;
                            // $receipt_end_text = $end_text; // Store the matched text for clarity
                            break;
                        }
                    }
                }

                // Extract main receipt content
                $receipt_html_main = substr($string, $receipt_start, $receipt_end - $receipt_start);

                // Remove middle text if specified
                if (!empty($receipt->options['receipt_middle_text'])) {
                    preg_match($receipt->options['receipt_middle_text'], $string, $matches);
                    if (!empty($matches[1])) {
                        $receipt_html_main = str_replace($matches[1], '', $receipt_html_main);
                    }
                }

                //PREVIEWS HTML RECEIPT
                // print_r($receipt_html_main);
                // dd();

                // Set defaults at the top
                $doc_type = 'pdf'; // Default to PDF for most cases
                $ocr_filename = date('Y-m-d-H-i-s') . '-' . rand(10, 99);

                if (!isset($receipt->options['receipt_image_regex']) && !isset($receipt->options['pdf_html'])) {
                    // HTML to PDF conversion
                    $ocr_filename .= '.' . $doc_type;
                    $view = view('misc.create_pdf_receipt', [
                        'receipt_html_main' => $receipt_html_main,
                        'message_type' => $bodyType
                    ])->render();

                    $ocr_path = '_temp_ocr/' . $ocr_filename;
                    
                    // Ensure the _temp_ocr directory exists
                    if (!Storage::disk('files')->exists('_temp_ocr')) {
                        Storage::disk('files')->makeDirectory('_temp_ocr');
                    }
                    
                    $location = Storage::disk('files')->path($ocr_path);

                    Browsershot::html($view)
                        ->newHeadless()
                        ->addChromiumArguments([
                            '--no-sandbox',
                            '--disable-setuid-sandbox',
                            '--disable-dev-shm-usage',
                            '--disable-gpu',
                            '--single-process',
                        ])
                        ->format('A4')
                        ->margins(20, 0, 20, 20)
                        ->save($location);
                } elseif (isset($receipt->options['pdf_html'])) {
                    // PDF attachment download
                    $doc_type = 'pdf';
                    $ocr_filename .= '.' . $doc_type;

                    if (!empty($message['attachments'])) {
                        $attachment = $message['attachments'][0];
                        $attachmentContent = $this->nylasService->downloadAttachment($attachment['id'], $grantId, $messageId);

                        $ocr_path = '_temp_ocr/' . $ocr_filename;
                        Storage::disk('files')->put($ocr_path, $attachmentContent);
                    } else {
                        // No attachments found
                        if (!empty($folderMap['Error'])) {
                            $this->nylasService->moveEmailToFolder($messageId, $folderMap['Error'], $grantId, $companyEmail->id);
                        } else {
                            Log::channel('nylas')->warning('Missing Error folder configuration when handling PDF-less message', [
                                'grant_id' => $grantId,
                                'company_email_id' => $companyEmail->id,
                                'message_id' => $messageId,
                            ]);
                        }
                        continue;
                    }
                } else {
                    // Image processing - override the default $doc_type
                    $doc_type = 'jpg';
                    $ocr_filename .= '.' . $doc_type;
                    $ocr_path = '_temp_ocr/' . $ocr_filename;
                    
                    // Ensure the _temp_ocr directory exists
                    if (!Storage::disk('files')->exists('_temp_ocr')) {
                        Storage::disk('files')->makeDirectory('_temp_ocr');
                    }
                    
                    $location = Storage::disk('files')->path($ocr_path);
                    
                    // Validate image URL before processing
                    if (empty($image_email_url)) {
                        // Log error and skip image processing
                        Log::error("Empty image URL for receipt", [
                            'receipt_id' => $receipt->id,
                            'message_id' => $messageId ?? null
                        ]);
                        // Move to error folder or handle appropriately
                        if (!empty($folderMap['Error'])) {
                            $this->nylasService->moveEmailToFolder($messageId, $folderMap['Error'], $grantId, $companyEmail->id);
                        } else {
                            Log::channel('nylas')->warning('Missing Error folder configuration for empty image URL', [
                                'grant_id' => $grantId,
                                'company_email_id' => $companyEmail->id,
                                'message_id' => $messageId,
                            ]);
                        }
                        continue;
                    }
                    
                    try {
                        Image::make($image_email_url)->save($location);
                    } catch (\Exception $e) {
                        Log::error('Failed to process image', ApiErrorFormatter::format($e, [
                            'image_url' => $image_email_url,
                            'receipt_id' => $receipt->id,
                        ]));
                        // Move to error folder or handle appropriately
                        if (!empty($folderMap['Error'])) {
                            $this->nylasService->moveEmailToFolder($messageId, $folderMap['Error'], $grantId, $companyEmail->id);
                        } else {
                            Log::channel('nylas')->warning('Missing Error folder configuration after image processing failure', [
                                'grant_id' => $grantId,
                                'company_email_id' => $companyEmail->id,
                                'message_id' => $messageId,
                            ]);
                        }
                        continue;
                    }
                }

                $document_model = $receipt->options['document_model'];

                //ocr the file
                $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)->azure_receipts($ocr_path, $doc_type, $document_model);

                //pass receipt info to ocr_extract method
                $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->ocr_extract($ocr_receipt_extracted, null, 'email', $receipt);

                // Some OCR providers / failure modes can return a partial payload without a "fields" key.
                // This method is executed by the scheduler; avoid crashing the whole run.
                if (! is_array($ocr_receipt_data)) {
                    Log::channel('nylas')->warning('OCR extract returned non-array payload', [
                        'receipt_id' => $receipt->id,
                        'company_email_id' => $companyEmail->id,
                        'message_id' => $messageId,
                    ]);
                    continue;
                }

                if (! isset($ocr_receipt_data['fields']) || ! is_array($ocr_receipt_data['fields'])) {
                    $ocr_receipt_data['fields'] = [];
                }

                if (! isset($ocr_receipt_data['content']) || ! is_string($ocr_receipt_data['content'])) {
                    $ocr_receipt_data['content'] = '';
                }
             
                $receipt_account = ReceiptAccount::withoutGlobalScopes()
                    ->where('belongs_to_vendor_id', $companyEmail->vendor_id)
                    ->where('vendor_id', $receipt->vendor_id)
                    ->first();
        
                // Missing receipt_account.. receipt and company email exist but this pairing does not
                if (is_null($receipt_account)) {
                    // Clean up temp OCR file if it exists
                    if (!empty($ocr_filename)) {
                        $sourcePath = '_temp_ocr/' . $ocr_filename;
                        Storage::disk('files')->delete($sourcePath);
                    }

                    // Move this email to the "Add" folder and skip to next message
                    if (!empty($folderMap['Add'])) {
                        $this->nylasService->moveEmailToFolder(
                            $messageId,
                            $folderMap['Add'],
                            $grantId,
                            $companyEmail->id
                        );
                    } else {
                        Log::channel('nylas')->warning('Missing Add folder configuration when receipt account lookup fails', [
                            'grant_id' => $grantId,
                            'company_email_id' => $companyEmail->id,
                            'message_id' => $messageId,
                        ]);
                    }
                    continue;
                }

                //01-26-2023 pass rest of receipt info to ocr_extract method
                $transactionDate = $ocr_receipt_data['fields']['transaction_date'] ?? null;
                $date = ! empty($transactionDate) ? $transactionDate : $dateEmail;

                //8-18-23 we can remove this?!
                $total = $ocr_receipt_data['fields']['total'] ?? null;
                if ($total === null || $total === '') {
                    Log::channel('nylas')->warning('OCR extract missing total field', [
                        'receipt_id' => $receipt->id,
                        'company_email_id' => $companyEmail->id,
                        'message_id' => $messageId,
                    ]);
                    $total = '0';
                }

                if (isset($receipt->options['refund'])) {
                    $amount = '-'.$total;
                } else {
                    $amount = $total;
                }

                // receipt number / invoice
                if (isset($receipt->options['invoice_regex'])) {
                    $re = $receipt->options['invoice_regex'];
                    $str = $ocr_receipt_data['content'];
                    preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);

                    if (empty($matches)) {
                        $invoice = null;
                    } else {
                        // $receipt_number = str_replace(' ', '', $matches[count($matches) - 1][0]);
                        $invoice = trim($matches[count($matches) - 1][0]);
                        $ocr_receipt_data['fields']['invoice_number'] = $invoice;
                    }
                } elseif (isset($ocr_receipt_data['fields']['invoice_number'])) {
                    $invoice = $ocr_receipt_data['fields']['invoice_number'];
                } else {
                    $invoice = null;
                }

                // receipt po / purchase order
                if (isset($receipt->options['po_regex'])) {
                    $re = $receipt->options['po_regex'];
                    $str = $ocr_receipt_data['content'];
                    preg_match($re, $str, $matches);

                    if (empty($matches)) {
                        $purchase_order = null;
                    } else {
                        $purchase_order = trim($matches[1]);
                    }
                } elseif (isset($ocr_receipt_data['fields']['purchase_order'])) {
                    $purchase_order = $ocr_receipt_data['fields']['purchase_order'];
                } else {
                    $purchase_order = null;
                }

                $ocr_receipt_data['fields']['purchase_order'] = $purchase_order;

                //FIND duplicates
                //confirm expense does not yet exist
                //1-18-2023 | 9/30/2023 NEED TO ACCOUNT FOR SAME VENDOR, AMOUNT, AND DATE being saved multiple of times
                //maybe by adding date_TIME to 'date'? or checking time in the expense_receipt_data json?

                // Match on invoice number + amount + date range to avoid false positives
                // (invoice numbers can repeat across different transactions at same vendor)
                $invoice = isset($invoice) ? trim((string) $invoice) : '';
                
                // Use ±5 day window for date matching to handle slight variations
                $duplicate_start_date = Carbon::parse($date)->subDays(5)->format('Y-m-d');
                $duplicate_end_date = Carbon::parse($date)->addDays(5)->format('Y-m-d');
                
                if ($invoice !== '') {
                    // When invoice exists, require invoice + amount + date range match
                    $duplicates = Expense::with('receipts')
                        ->where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                        ->where('vendor_id', $receipt->vendor_id)
                        ->where('invoice', $invoice)
                        ->where('amount', $amount)
                        ->whereBetween('date', [$duplicate_start_date, $duplicate_end_date])
                        ->whereNull('deleted_at')
                        ->get();
                    
                    // Apply same intelligent filtering as non-invoice path
                    if ($duplicates->isNotEmpty()) {
                        $currentItemsSig = $this->buildItemsSignature($ocr_receipt_data['fields'] ?? []);
                        $currentTime = $this->extractReceiptTime($ocr_receipt_data['content'] ?? '', $date);
                        
                        $filteredDuplicates = collect();
                        foreach ($duplicates as $candidate) {
                            $receiptRecord = $candidate->receipts()->latest('id')->first();
                            
                            // If no receipt exists, treat as duplicate
                            if (!$receiptRecord) {
                                $filteredDuplicates->push($candidate);
                                continue;
                            }
                            
                            // Check items and time similarity
                            $storedFields = json_decode(json_encode($receiptRecord->receipt_items), true) ?? [];
                            $storedItemsSig = $this->buildItemsSignature($storedFields ?? []);
                            $itemsOverlap = $this->itemsOverlap($currentItemsSig, $storedItemsSig);
                            
                            if (!$itemsOverlap) {
                                continue;
                            }
                            
                            // Time equality check
                            $candidateDate = $candidate->date instanceof \Carbon\Carbon
                                ? $candidate->date->toDateString()
                                : (is_string($candidate->date) ? $candidate->date : null);
                            
                            $storedTime = $this->extractReceiptTime($receiptRecord->receipt_html ?? '', $candidateDate);
                            
                            $timeEqual = false;
                            if ($currentTime && $storedTime) {
                                $timeEqual = $currentTime->equalTo($storedTime) || $currentTime->format('H:i') === $storedTime->format('H:i');
                                if (!$timeEqual) {
                                    continue;
                                }
                            }
                            
                            if ($itemsOverlap || $timeEqual) {
                                $filteredDuplicates->push($candidate);
                            }
                        }
                        
                        $duplicates = $filteredDuplicates;
                    }
                } else {
                    // Candidate pool by amount + date (eager-load receipts to avoid N+1)
                    $candidates = Expense::with('receipts')
                        ->where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                        ->where('vendor_id', $receipt->vendor_id)
                        ->whereNull('deleted_at')
                        ->where('amount', $amount)
                        ->whereBetween('date', [$duplicate_start_date, $duplicate_end_date])
                        ->get();

                    $duplicates = collect();
                    if ($candidates->isNotEmpty()) {
                        // Build current receipt signals
                        $currentItemsSig = $this->buildItemsSignature($ocr_receipt_data['fields'] ?? []);
                        $currentTime = $this->extractReceiptTime($ocr_receipt_data['content'] ?? '', $date);

                        foreach ($candidates as $candidate) {
                            // Prefer skipping if candidate has a different known invoice
                            if (!empty($candidate->invoice)) {
                                // If candidate has invoice but current doesn't, don't auto-mark duplicate
                                // unless items overlap or time is near-identical.
                            }

                            $receiptRecord = $candidate->receipts()->latest('id')->first();
                            
                            // If no receipt exists on the candidate, treat it as a duplicate match
                            // (same vendor, amount, date - very likely the same expense)
                            if (!$receiptRecord) {
                                $duplicates->push($candidate);
                                continue;
                            }

                            // receipt_items is cast to object on ExpenseReceipts; convert to array
                            $storedFields = json_decode(json_encode($receiptRecord->receipt_items), true) ?? [];
                            $storedItemsSig = $this->buildItemsSignature($storedFields ?? []);
                            $itemsOverlap = $this->itemsOverlap($currentItemsSig, $storedItemsSig);

                            // If product codes/descriptions don't overlap, assume not duplicate.
                            if (!$itemsOverlap) {
                                continue;
                            }

                            // Time equality gating: if both have time, they must be identical to be duplicate.
                            $candidateDate = $candidate->date instanceof \Carbon\Carbon
                                ? $candidate->date->toDateString()
                                : (is_string($candidate->date) ? $candidate->date : null);

                            $storedTime = $this->extractReceiptTime($receiptRecord->receipt_html ?? '', $candidateDate);

                            $timeEqual = false;
                            if ($currentTime && $storedTime) {
                                // times must match exactly to the minute
                                $timeEqual = $currentTime->equalTo($storedTime) || $currentTime->format('H:i') === $storedTime->format('H:i');
                                if (! $timeEqual) {
                                    // Different times -> not a duplicate
                                    continue;
                                }
                            }

                            // If items overlap OR time is identical, consider it a duplicate.
                            if ($itemsOverlap || $timeEqual) {
                                $duplicates->push($candidate);
                            }
                        }
                    }
                }

                if ($duplicates->isNotEmpty()) {
                    // Choose the best matching duplicate using consistent logic
                    // Prefer time-matched duplicates when time is available
                    $chosen = $duplicates;
                    
                    if (isset($currentTime) && $currentTime instanceof \Carbon\Carbon) {
                        $withTimeEqual = $duplicates->filter(function ($candidate) use ($currentTime) {
                            $receiptRecord = $candidate->receipts()->latest('id')->first();
                            if (!$receiptRecord) {
                                return false;
                            }

                            $candidateDate = $candidate->date instanceof \Carbon\Carbon
                                ? $candidate->date->toDateString()
                                : (is_string($candidate->date) ? $candidate->date : null);

                            $storedTime = $this->extractReceiptTime($receiptRecord->receipt_html ?? '', $candidateDate);
                            if (!$storedTime) {
                                return false;
                            }

                            return $currentTime->format('H:i') === $storedTime->format('H:i');
                        });

                        if ($withTimeEqual->isNotEmpty()) {
                            $chosen = $withTimeEqual;
                        }
                    }

                    // Pick the one with the closest date as final tie-breaker
                    $duplicate_expense = $chosen->sortBy(function ($d) use ($date) {
                        return abs(Carbon::parse($d->date)->diffInDays(Carbon::parse($date)));
                    })->first();

                    //ATTACHMENTS
                    $this->saveExpenseReceipt($duplicate_expense->id, $ocr_receipt_data, $ocr_filename, $message);

                    //add po and add invoice from ocr
                    // $duplicate_expense->invoice = $invoice;
                    // $duplicate_expense->date = $date;
                    // $duplicate_expense->save();

                    //move email receipt to Duplicate folder
                    if (!empty($folderMap['Duplicate'])) {
                        $this->nylasService->moveEmailToFolder($messageId, $folderMap['Duplicate'], $grantId, $companyEmail->id);
                    } else {
                        Log::channel('nylas')->warning('Missing Duplicate folder configuration when handling duplicate receipt', [
                            'grant_id' => $grantId,
                            'company_email_id' => $companyEmail->id,
                            'message_id' => $messageId,
                        ]);
                    }
                }else{
                    // Before creating a new expense, check if this receipt is a duplicate across all expenses
                    $existingExpenseWithDuplicate = $this->findExpenseWithDuplicateReceipt(
                        $receipt_account->belongs_to_vendor_id,
                        $receipt->vendor_id,
                        $amount,
                        $date,
                        $ocr_receipt_data['content'],
                        $ocr_receipt_data['fields']
                    );

                    if ($existingExpenseWithDuplicate) {
                        // Found a duplicate - attach receipt to existing expense and move to Duplicate folder
                        $this->saveExpenseReceipt($existingExpenseWithDuplicate->id, $ocr_receipt_data, $ocr_filename, $message);
                        
                        if (!empty($folderMap['Duplicate'])) {
                            $this->nylasService->moveEmailToFolder($messageId, $folderMap['Duplicate'], $grantId, $companyEmail->id);
                        } else {
                            Log::channel('nylas')->warning('Missing Duplicate folder configuration after detecting duplicate receipt', [
                                'grant_id' => $grantId,
                                'company_email_id' => $companyEmail->id,
                                'message_id' => $messageId,
                                'existing_expense_id' => $existingExpenseWithDuplicate->id,
                            ]);
                        }
                    } else {
                        // Check if there are partial expenses that sum to this receipt total
                        $partialExpenses = $this->findPartialExpensesToConsolidate(
                            $receipt_account->belongs_to_vendor_id,
                            $receipt->vendor_id,
                            $amount,
                            $date,
                            $invoice
                        );

                        if ($partialExpenses->isNotEmpty()) {
                            // Found partial expenses that sum to receipt total - consolidate them
                            Log::channel('nylas')->info('Found partial expenses to consolidate', [
                                'partial_expense_ids' => $partialExpenses->pluck('id')->toArray(),
                                'partial_amounts' => $partialExpenses->pluck('amount')->toArray(),
                                'receipt_total' => $amount,
                                'vendor_id' => $receipt->vendor_id,
                            ]);

                            // Create new expense with receipt
                            $expense = new Expense;
                            $expense->amount = $amount;
                            $expense->reimbursment = null;
                            $expense->project_id = $receipt_account->project_id;
                            $expense->distribution_id = $receipt_account->distribution_id;
                            $expense->created_by_user_id = 0; //automated
                            $expense->date = $date;
                            $expense->invoice = $invoice;
                            $expense->vendor_id = $receipt->vendor_id;
                            $expense->note = null;
                            $expense->belongs_to_vendor_id = $receipt_account->belongs_to_vendor_id;
                            $expense->save();

                            //ATTACHMENTS
                            $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename, $message);

                            // Transfer transactions and checks from partial expenses using shared method
                            $this->consolidatePartialExpenses($expense, $partialExpenses);

                            // Move to Saved folder
                            if (!empty($folderMap['Saved'])) {
                                $this->nylasService->moveEmailToFolder($messageId, $folderMap['Saved'], $grantId, $companyEmail->id);
                            } else {
                                Log::channel('nylas')->warning('Missing Saved folder configuration after consolidating expenses', [
                                    'grant_id' => $grantId,
                                    'company_email_id' => $companyEmail->id,
                                    'message_id' => $messageId,
                                ]);
                            }
                        } else {
                            // No duplicate and no partial expenses found - create new expense
                            $expense = new Expense;
                            $expense->amount = $amount;
                            $expense->reimbursment = null;
                            $expense->project_id = $receipt_account->project_id;
                            $expense->distribution_id = $receipt_account->distribution_id;
                            $expense->created_by_user_id = 0; //automated
                            $expense->date = $date;
                            $expense->invoice = $invoice;
                            $expense->vendor_id = $receipt->vendor_id; //Vendor_id of vendor being Queued
                            $expense->note = null;
                            $expense->belongs_to_vendor_id = $receipt_account->belongs_to_vendor_id;
                            $expense->save();

                            //ATTACHMENTS
                            $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename, $message);

                            // Move to Saved folder
                            if (!empty($folderMap['Saved'])) {
                                $this->nylasService->moveEmailToFolder($messageId, $folderMap['Saved'], $grantId, $companyEmail->id);
                            } else {
                                Log::channel('nylas')->warning('Missing Saved folder configuration after processing receipt', [
                                    'grant_id' => $grantId,
                                    'company_email_id' => $companyEmail->id,
                                    'message_id' => $messageId,
                                ]);
                            }
                        }
                    }
                }
            } else{
                continue;
            }
        }        
    }

    public function fetchAutoReceipts()
    {
        // Fetch company emails with the specified conditions.
        $company_emails = CompanyEmail::withoutGlobalScopes()
            ->whereNotNull('grant_id')
            ->get();

        foreach ($company_emails as $company_email) {
            $grantId = $company_email->grant_id;
            $email_vendor = $company_email->vendor;
            $email_vendor_bank_account_ids = $email_vendor->bank_accounts->pluck('id');

            // Get inbox folder ID from company email's api_json
            $inboxFolderId = $company_email->api_json['INBOX_FOLDER'] ?? null;
            
            if (!$inboxFolderId) {
                Log::channel('nylas')->warning('Skipping fetchAutoReceipts - no INBOX_FOLDER configured', [
                    'company_email_id' => $company_email->id,
                    'grant_id' => $grantId,
                ]);
                continue;
            }

            // Fetch messages from the inbox using the incremental sync helper
            $syncResult = $this->nylasService->syncMessages([$inboxFolderId], $company_email);
            $messages = collect($syncResult['messages'] ?? [])
                ->filter(fn($m) => isset($m['from'][0]['email'], $m['subject']) &&
                    strcasecmp($m['from'][0]['email'], 'noreply@print.epsonconnect.com') === 0 &&
                    stripos($m['subject'], 'Receipt Scans') !== false)
                ->values()
                ->all();

            // NylasService::syncMessages already persists cursors into CompanyEmail->api_json when changed.
            // No need to mutate api_json here.
         
            foreach ($messages as $message) {
                $messageId = $message['id'];

                if (!empty($message['attachments'])) {
                    foreach ($message['attachments'] as $attachment_key => $attachment) {
                        $doc_type = 'pdf';

                        // Generate a unique filename and create the temporary file path.
                        $ocr_filename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '.pdf';
                        $ocr_path = '_temp_ocr/' . $ocr_filename;

                        // Download the attachment content.
                        $attachmentContent = $this->nylasService->downloadAttachment(
                            $attachment['id'],
                            $grantId,
                            $messageId
                        );

                        // Save the attachment to the 'files' disk under _temp_ocr.
                        Storage::disk('files')->put($ocr_path, $attachmentContent);

                        // Get the document model from AzureDocumentService.
                        $document_model = $this->azureDocumentService->getDocumentModel($ocr_path, $doc_type);

                        // Process the attachment using ReceiptController.
                        $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)->azure_receipts($ocr_path, $doc_type, $document_model);
                        $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->ocr_extract($ocr_receipt_extracted, null, true);

                        if (isset($ocr_receipt_data['error']) && $ocr_receipt_data['error'] === true)
                        {
                            // Log the failed receipt processing with debug filepath
                            Log::channel('receipt_processing')->error('OCR processing failed', [
                                'company_email_id' => $company_email->id,
                                'attachment_filename' => $attachment['filename'] ?? 'unknown',
                                'failed_file_path' => 'auto_receipts_failed/' . $company_email->vendor_id . '-' . $ocr_filename,
                                'ocr_error_data' => $ocr_receipt_data,
                                'timestamp' => now()->toISOString()
                            ]);

                            // Move this single attachment to a folder for debug
                            Storage::disk('files')->put('auto_receipts_failed/'. $company_email->vendor_id . '-' .$ocr_filename, $attachmentContent);
                            Storage::disk('files')->delete($ocr_path);

                            if ($attachment_key === array_key_last($message['attachments'])) {
                                $this->nylasService->moveOriginalMessageToHiveFolder($grantId, $messageId, $company_email->id);
                            }

                            continue;
                        }

                        // Set up the transaction date range.
                        $start_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])
                            ->subDays(4)
                            ->format('Y-m-d');
                        $end_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])
                            ->addDays(5)
                            ->format('Y-m-d');

                        // Find matching transactions.
                        $transactions = Transaction::whereIn('bank_account_id', $email_vendor_bank_account_ids)
                            ->whereNull('expense_id')
                            ->whereNull('check_number')
                            ->whereNull('deposit')
                            ->where('amount', $ocr_receipt_data['fields']['total'])
                            ->whereBetween('transaction_date', [$start_date, $end_date])
                            ->get();

                        if ($transactions->count() === 1) {
                            $transaction = $transactions->first();
                        } elseif ($transactions->count() > 1) {
                            // For ambiguous cases with multiple transactions.
                            $transaction = null;
                        } else {
                            $transaction = null;
                        }

                        // Duplicate expense checking.
                        // Use a symmetric ±5 day window to better match transactions posted a few days before/after
                        $duplicate_start_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])
                            ->subDays(5)
                            ->format('Y-m-d');
                        $duplicate_end_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])
                            ->addDays(5)
                            ->format('Y-m-d');

                        $duplicates = Expense::where('belongs_to_vendor_id', $email_vendor->id)
                            ->with('receipts')
                            ->whereNull('deleted_at')
                            ->where('amount', $ocr_receipt_data['fields']['total'])
                            ->where('amount', '!=', '0.00')
                            ->whereBetween('date', [$duplicate_start_date, $duplicate_end_date])
                            ->get();

                        $didAttachReceipt = false;

                        if ($duplicates->count() >= 1) {
                            foreach ($duplicates as $duplicate) {
                                $duplicate->date_diff = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])
                                    ->floatDiffInDays($duplicate->date);
                            }
                            $expense_duplicate = $duplicates->sortBy('date_diff')->first();

                            // If the latest receipt HTML is different, update; otherwise, skip.
                            if (isset($expense_duplicate->receipts()->latest()->first()->receipt_html)) {
                                if ($expense_duplicate->receipts()->latest()->first()->receipt_html != $ocr_receipt_data['content']) {
                                    // Update existing expense fields from OCR data before attaching receipt
                                    $expense = $expense_duplicate;
                                    // Remove temporary date_diff property to avoid database save error
                                    unset($expense->date_diff);
                                    $newDate = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])->toDateString();
                                    if ($expense->date !== $newDate) {
                                        $expense->date = $newDate;
                                    }
                                    $incomingInvoice = trim((string)($ocr_receipt_data['fields']['invoice_number'] ?? ''));
                                    if ($incomingInvoice !== '') {
                                        // Only set invoice if it's empty to avoid clobbering a known value
                                        if (empty($expense->invoice)) {
                                            $expense->invoice = $incomingInvoice;
                                        }
                                    }
                                    $expense->save();
                                    // Link a single matched transaction to this expense if found earlier
                                    if (isset($transaction) && $transaction && is_null($transaction->expense_id)) {
                                        $transaction->expense_id = $expense->id;
                                        $transaction->save();
                                    }
                                } else {
                                    // Clean up the temporary OCR file before skipping
                                    Storage::disk('files')->delete($ocr_path);
                                    continue; // Skip if the receipt is an exact duplicate.
                                }
                            } else {
                                // No previous receipts recorded; update and use this expense
                                $expense = $expense_duplicate;
                                // Remove temporary date_diff property to avoid database save error
                                unset($expense->date_diff);
                                $newDate = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])->toDateString();
                                if ($expense->date !== $newDate) {
                                    $expense->date = $newDate;
                                }
                                $incomingInvoice = trim((string)($ocr_receipt_data['fields']['invoice_number'] ?? ''));
                                if ($incomingInvoice !== '') {
                                    if (empty($expense->invoice)) {
                                        $expense->invoice = $incomingInvoice;
                                    }
                                }
                                $expense->save();
                                if (isset($transaction) && $transaction && is_null($transaction->expense_id)) {
                                    $transaction->expense_id = $expense->id;
                                    $transaction->save();
                                }
                            }

                            // Attach the currently processed receipt to the chosen expense.
                            // (saveExpenseReceipt moves the temp file into receipts/ and persists the receipt record)
                            if (isset($expense) && $expense instanceof Expense) {
                                $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename, null, true);
                                $didAttachReceipt = true;
                            }
                        } elseif ($duplicates->isEmpty()) {
                            // Check if there are partial expenses that sum to this receipt total
                            $partialExpenses = $this->findPartialExpensesToConsolidate(
                                $email_vendor->id,
                                $expense_vendor_id ?? 0,
                                $ocr_receipt_data['fields']['total'],
                                $ocr_receipt_data['fields']['transaction_date'],
                                $ocr_receipt_data['fields']['invoice_number'] ?? null
                            );

                            if ($partialExpenses->isNotEmpty()) {
                                // Found partial expenses that sum to receipt total - consolidate them
                                Log::channel('nylas')->info('AutoReceipts: Found partial expenses to consolidate', [
                                    'partial_expense_ids' => $partialExpenses->pluck('id')->toArray(),
                                    'partial_amounts' => $partialExpenses->pluck('amount')->toArray(),
                                    'receipt_total' => $ocr_receipt_data['fields']['total'],
                                    'vendor_id' => $expense_vendor_id,
                                ]);

                                // Use fuzzy matching to determine vendor if needed
                                $transaction_vendor_id = $transaction ? ($transaction->vendor_id ?? null) : null;
                                $ocrVendorName = $ocr_receipt_data['fields']['merchant_name'];
                                $vendors = Vendor::withoutGlobalScopes()->get();
                                $matchedVendor = $this->fuzzyMatchVendor($ocrVendorName, $vendors, 70.0);
                                $fuzzyVendorId = $matchedVendor ? $matchedVendor->id : 0;
                                $expense_vendor_id = $transaction_vendor_id ?? $fuzzyVendorId;

                                // Create new expense with receipt
                                $expense = Expense::create([
                                    'amount'               => $ocr_receipt_data['fields']['total'],
                                    'date'                 => $ocr_receipt_data['fields']['transaction_date'],
                                    'project_id'           => null,
                                    'distribution_id'      => null,
                                    'vendor_id'            => $expense_vendor_id,
                                    'check_id'             => null,
                                    'paid_by'              => null,
                                    'belongs_to_vendor_id' => $email_vendor->id,
                                    'created_by_user_id'   => 0,
                                    'invoice'              => $ocr_receipt_data['fields']['invoice_number'] ?: null,
                                ]);

                                // Transfer transactions and checks from partial expenses using shared method
                                $this->consolidatePartialExpenses($expense, $partialExpenses, 'AutoReceipts:');
                            } else {
                                // No duplicate and no partial expenses found - create new expense
                                // Use fuzzy matching to determine vendor in the "no duplicate" branch.
                                $transaction_vendor_id = $transaction ? ($transaction->vendor_id ?? null) : null;

                                $ocrVendorName = $ocr_receipt_data['fields']['merchant_name'];
                                $vendors = Vendor::withoutGlobalScopes()->get();
                                $matchedVendor = $this->fuzzyMatchVendor($ocrVendorName, $vendors, 70.0);
                                $fuzzyVendorId = $matchedVendor ? $matchedVendor->id : 0;

                                // Use the transaction vendor if available, otherwise use the fuzzy match.
                                $expense_vendor_id = $transaction_vendor_id ?? $fuzzyVendorId;

                                $expense = Expense::create([
                                    'amount'               => $ocr_receipt_data['fields']['total'],
                                    'date'                 => $ocr_receipt_data['fields']['transaction_date'],
                                    'project_id'           => null,
                                    'distribution_id'      => null,
                                    'vendor_id'            => $expense_vendor_id,
                                    'check_id'             => null,
                                    'paid_by'              => null,
                                    'belongs_to_vendor_id' => $email_vendor->id,
                                    'created_by_user_id'   => 0,
                                    'invoice'              => $ocr_receipt_data['fields']['invoice_number'] ?: null,
                                ]);

                                // If exactly one bank transaction matched earlier, link it now
                                if (isset($transaction) && $transaction && is_null($transaction->expense_id)) {
                                    $transaction->expense_id = $expense->id;
                                    $transaction->save();
                                }
                            }
                        } else {
                            // Fallback branch for ambiguous situations.
                            $transaction = null;
                            $ocrVendorName = $ocr_receipt_data['fields']['merchant_name'];
                            $vendors = Vendor::withoutGlobalScopes()->get();
                            $matchedVendor = $this->fuzzyMatchVendor($ocrVendorName, $vendors, 70.0);

                            // Optionally fallback to a plain LIKE if fuzzy matching fails.
                            if (!$matchedVendor) {
                                $matchedVendor = Vendor::withoutGlobalScopes()
                                    ->where('business_type', 'Retail')
                                    ->where('business_name', 'LIKE', $ocrVendorName)
                                    ->first();
                            }

                            $vendorId = $matchedVendor ? $matchedVendor->id : 0;

                            $expense = Expense::create([
                                'amount'               => $ocr_receipt_data['fields']['total'],
                                'date'                 => $ocr_receipt_data['fields']['transaction_date'],
                                'project_id'           => null,
                                'distribution_id'      => null,
                                'vendor_id'            => $vendorId,
                                'check_id'             => null,
                                'paid_by'              => null,
                                'belongs_to_vendor_id' => $email_vendor->id,
                                'created_by_user_id'   => 0,
                                'invoice'              => $ocr_receipt_data['fields']['invoice_number'] ?: null,
                            ]);
                        }

                        // Finally, save the expense receipt (this method moves the file from _temp_ocr to receipts).
                        if (! $didAttachReceipt) {
                            $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename);
                        }
                    } // end foreach attachment

                    // After processing all attachments for the message, move the email to the HIVE folder.
                    $this->nylasService->moveOriginalMessageToHiveFolder(
                        $grantId,
                        $messageId,
                        $company_email->id
                    );
                    continue;
                }
            }
        }
    }

    public function saveExpenseReceipt($expense_id, $ocr_receipt_data, $ocr_filename, $message = NULL, bool $skipDuplicateCheck = false)
    {
        if ($message) {
            if (!empty($message['attachments'])) {
                //TEMP HARD CODED FOR HOME DEPOT. REPLACE
                // Filter to find eReceipt.pdf attachments
                $eReceiptAttachments = array_filter($message['attachments'], function($attachment) {
                    return $attachment['filename'] === 'eReceipt.pdf' && (!isset($attachment['is_inline']) || $attachment['is_inline'] === false);
                });

                if(!empty($eReceiptAttachments) && !strpos($ocr_receipt_data['content'], 'RECALL AMOUNT')){
                    $nonInlineAttachments = $eReceiptAttachments;
                }else{
                    // Filter out inline attachments, keep only non-inline ones
                    $nonInlineAttachments = array_filter($message['attachments'], function($attachment) {
                        return !isset($attachment['is_inline']) || $attachment['is_inline'] === false;
                    });
                }

                // Process all non-inline attachments
                if (!empty($nonInlineAttachments)) {
                    // First clean up existing temp file if passed in
                    if ($ocr_filename) {
                        $sourcePath = '_temp_ocr/' . $ocr_filename;
                        Storage::disk('files')->delete($sourcePath);
                    }
                    
                    // Track all processed attachments for this expense
                    $processedFiles = [];
                    
                    foreach ($nonInlineAttachments as $attachmentIndex => $attachment) {
                        // Generate unique filename for this attachment
                        $currentFilename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '-' . $attachmentIndex . '.pdf';
                        $ocr_path = '_temp_ocr/' . $currentFilename;
                        
                        // Download attachment
                        $attachmentContent = $this->nylasService->downloadAttachment(
                            $attachment['id'], 
                            $message['grant_id'], 
                            $message['id']
                        );
                        
                        Storage::disk('files')->put($ocr_path, $attachmentContent);
                        
                        // Determine doc type (defaulting to PDF)
                        $doc_type = 'pdf';
                        
                        // Get document model based on width (like in auto receipts)
                        $document_model = $this->azureDocumentService->getDocumentModel($ocr_path, $doc_type);
                        
                        // OCR the file
                        $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)
                            ->azure_receipts($ocr_path, $doc_type, $document_model);
                        
                        // Process OCR results
                        $current_ocr_data = app(\App\Http\Controllers\ReceiptController::class)
                            ->ocr_extract($ocr_receipt_extracted, null, 'email');
                        
                        // Save this attachment as an expense receipt
                        $targetFilename = $expense_id . '-' . $currentFilename;
                        $destinationPath = 'receipts/' . $targetFilename;
                        
                        // Determine receipt content and items for duplicate checking
                        $receipt_html = ($attachmentIndex === array_key_first($nonInlineAttachments) && isset($ocr_receipt_data['content'])) 
                            ? $ocr_receipt_data['content'] 
                            : $current_ocr_data['content'];
                        $receipt_items = ($attachmentIndex === array_key_first($nonInlineAttachments) && isset($ocr_receipt_data['content'])) 
                            ? ($ocr_receipt_data['fields'] ?? $current_ocr_data['fields']) 
                            : $current_ocr_data['fields'];
                        
                        // Check for duplicate receipts based on content and invoice number
                        $isDuplicate = $skipDuplicateCheck ? false : $this->isDuplicateReceipt($expense_id, $receipt_html, $receipt_items);
                        
                        if ($isDuplicate) {
                            // Skip saving this duplicate receipt and clean up temp file
                            Storage::disk('files')->delete($ocr_path);
                            continue;
                        }
                        
                        // Create receipt record in database
                        $expense_receipt = new ExpenseReceipts;
                        $expense_receipt->expense_id = $expense_id;
                        $expense_receipt->receipt_filename = $targetFilename;
                        $expense_receipt->receipt_html = $receipt_html;
                        $expense_receipt->receipt_items = $receipt_items;
                        
                        $expense_receipt->save();
                        
                        // Move the file to permanent storage
                        if (Storage::disk('files')->move($ocr_path, $destinationPath)) {
                            // Success case
                        } else {
                            if (Storage::disk('files')->copy($ocr_path, $destinationPath)) {
                                Storage::disk('files')->delete($ocr_path);
                            }
                        }
                        
                        // Track processed files
                        $processedFiles[] = $targetFilename;
                    }
                    
                    // Return early since we've processed all attachments
                    return $processedFiles;
                }
            }
        }

        // Original functionality for handling single files (for backward compatibility)
        $filename = $expense_id . '-' . $ocr_filename;
        $sourcePath = '_temp_ocr/' . $ocr_filename;
        $destinationPath = 'receipts/' . $filename;

        // Check for duplicate receipts based on content and invoice number
        $isDuplicate = $skipDuplicateCheck ? false : $this->isDuplicateReceipt($expense_id, $ocr_receipt_data['content'], $ocr_receipt_data['fields']);
        
        if ($isDuplicate) {
            // Skip saving this duplicate receipt and clean up temp file
            Storage::disk('files')->delete($sourcePath);
            return [];
        }

        // Save expense receipt data to the database
        $expense_receipt = new ExpenseReceipts;
        $expense_receipt->expense_id = $expense_id;
        $expense_receipt->receipt_filename = $filename;
        $expense_receipt->receipt_html = $ocr_receipt_data['content'];
        $expense_receipt->receipt_items = $ocr_receipt_data['fields'];
        $expense_receipt->save();

        // Perform the move operation with fallback to copy-delete
        if (Storage::disk('files')->move($sourcePath, $destinationPath)) {
            // Success case
        } else {
            if (Storage::disk('files')->copy($sourcePath, $destinationPath)) {
                Storage::disk('files')->delete($sourcePath);
            }
        }
        
        return [$filename];
    }

    public function fuzzyMatchVendor($ocrName, $vendors, $threshold = 75.0)
    {
        // Unicode-safe normalize: lowercase, remove punctuation/symbols, collapse whitespace
        $normalize = static function (?string $s): string {
            if ($s === null) {
                return '';
            }
            $s = mb_strtolower($s, 'UTF-8');
            // Remove all punctuation and symbols but keep letters, numbers and spaces
            $s = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s) ?? '';
            // Collapse whitespace
            $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
            return $s;
        };

        $ocrNormalized = $normalize($ocrName);          // e.g. "the home depot"
        $ocrCompact    = str_replace(' ', '', $ocrNormalized); // e.g. "thehomedepot"
        $ocrTokens     = $ocrNormalized !== '' ? explode(' ', $ocrNormalized) : [];

        // STEP 1: Check for EXACT or VERY HIGH similarity matches with Vendor.business_name
        // This is the primary, most authoritative matching mechanism
        foreach ($vendors as $vendor) {
            $vendorName = (string) $vendor->business_name;
            $vendorNorm = $normalize($vendorName);
            $vendorCompact = str_replace(' ', '', $vendorNorm);
            
            // Exact match (normalized)
            if ($ocrNormalized !== '' && $ocrNormalized === $vendorNorm) {
                return $vendor;
            }
            
            // Exact match (compact - no spaces)
            if ($ocrCompact !== '' && $ocrCompact === $vendorCompact) {
                return $vendor;
            }
            
            // Very high similarity (95%+) on normalized names
            if ($ocrNormalized !== '' && $vendorNorm !== '') {
                $percentA = 0.0;
                similar_text($ocrNormalized, $vendorNorm, $percentA);
                if ($percentA >= 95.0) {
                    return $vendor;
                }
            }
            
            // Very high similarity (95%+) on compact names
            if ($ocrCompact !== '' && $vendorCompact !== '') {
                $percentB = 0.0;
                similar_text($ocrCompact, $vendorCompact, $percentB);
                if ($percentB >= 95.0) {
                    return $vendor;
                }
            }
        }

        // Build vendor_transactions map for BOTH direct pattern matching AND fuzzy learning
        // Cache per unique set of vendor IDs for this request to avoid repeat queries.
        $vendorList = is_array($vendors) ? $vendors : (is_iterable($vendors) ? $vendors : []);
        $vendorIds = [];
        foreach ($vendorList as $v) {
            if (is_object($v) && isset($v->id)) {
                $vendorIds[] = (int) $v->id;
            }
        }
        sort($vendorIds);
        $aliasCacheKey = implode(',', $vendorIds);
        static $aliasCache = [];
        static $rawAliasCache = []; // Cache raw patterns with options for direct matching
        
        if (!isset($aliasCache[$aliasCacheKey])) {
            $aliasRows = [];
            if (!empty($vendorIds)) {
                try {
                    $aliasRows = DB::table('vendor_transactions')
                        ->select(['vendor_id', DB::raw('`desc` as pattern'), 'options'])
                        ->whereIn('vendor_id', $vendorIds)
                        ->get();
                } catch (\Throwable $e) {
                    // If the table is missing or any error occurs, fall back to no aliases.
                    $aliasRows = collect();
                }
            }

            $map = [];
            $rawMap = [];
            foreach ($aliasRows as $row) {
                $vid = (int) $row->vendor_id;
                $pattern = (string) ($row->pattern ?? '');
                
                if ($pattern === '') {
                    continue;
                }
                
                // Store raw pattern for direct matching
                $rawMap[$vid][] = [
                    'pattern' => $pattern,
                    'options' => $row->options ?? null,
                ];
                
                // Store normalized pattern for fuzzy matching
                $pat = $normalize($pattern);
                $map[$vid][] = [
                    'norm' => $pat,
                    'compact' => str_replace(' ', '', $pat),
                ];
            }
            $aliasCache[$aliasCacheKey] = $map;
            $rawAliasCache[$aliasCacheKey] = $rawMap;
        }
        $aliasMap = $aliasCache[$aliasCacheKey];
        $rawAliasMap = $rawAliasCache[$aliasCacheKey];

        // STEP 2: Check for pattern matches using vendor_transactions.desc
        // This handles transaction-specific patterns like "MNRD-MOUN" → Menards
        foreach ($vendors as $vendor) {
            $vid = (int) ($vendor->id ?? 0);
            if (!$vid || !isset($rawAliasMap[$vid])) {
                continue;
            }
            
            foreach ($rawAliasMap[$vid] as $aliasData) {
                $pattern = $aliasData['pattern'];
                $options = $aliasData['options'];
                
                // Parse regex options (e.g., "/i" for case-insensitive)
                $modifiers = 'i'; // Default to case-insensitive
                if ($options && is_string($options)) {
                    // Remove quotes and extract modifiers
                    $cleaned = trim($options, '"\'');
                    if (preg_match('/^\/([a-z]*)$/i', $cleaned, $m)) {
                        $modifiers = $m[1];
                    }
                }
                
                // The pattern might already be a regex or a plain string
                // Try to detect if it's already a regex pattern by checking for regex meta-characters
                $hasRegexChars = preg_match('/[\\\\.*+?^${}()|[\]]/', $pattern);
                
                if ($hasRegexChars) {
                    // Pattern contains regex chars - use as-is (it's already a regex pattern)
                    // Just wrap in delimiters and add modifiers
                    $regex = '/' . $pattern . '/' . $modifiers;
                } else {
                    // Plain string pattern - escape it for safe regex use
                    $regex = '/' . preg_quote($pattern, '/') . '/' . $modifiers;
                }
                
                // Check if OCR text matches this pattern
                if (@preg_match($regex, $ocrName)) {
                    return $vendor;
                }
            }
        }
        
        // STEP 3: Fall back to intelligent fuzzy matching if no direct match found

        $bestVendor = null;
        $bestScore = -INF;

        foreach ($vendors as $vendor) {
            $vendorName = (string) $vendor->business_name;
            $vendorNorm = $normalize($vendorName);
            $vendorCompact = str_replace(' ', '', $vendorNorm);
            $vendorTokens = $vendorNorm !== '' ? explode(' ', $vendorNorm) : [];

            // Base similarity (spaced and compact forms)
            $percentA = 0.0; $percentB = 0.0;
            if ($ocrNormalized !== '' && $vendorNorm !== '') {
                similar_text($ocrNormalized, $vendorNorm, $percentA);
            }
            if ($ocrCompact !== '' && $vendorCompact !== '') {
                similar_text($ocrCompact, $vendorCompact, $percentB);
            }
            $score = max($percentA, $percentB);

            // Token overlap analysis: detect distinguishing differences
            if (!empty($ocrTokens) && !empty($vendorTokens)) {
                $overlap = array_intersect($ocrTokens, $vendorTokens);
                $ocrUnique = array_diff($ocrTokens, $vendorTokens);
                $vendorUnique = array_diff($vendorTokens, $ocrTokens);
                
                // Build dynamic filler words from vendor_transactions patterns
                // This helps us understand which parts of names are generic vs specific
                $fillerWords = ['the', 'inc', 'llc', 'corp', 'ltd', 'co', 'company', 'and', 'of', 'a', 'an'];
                
                // If we have vendor_transactions for this vendor, use them to identify filler words
                $vid = (int) ($vendor->id ?? 0);
                if ($vid && isset($aliasMap[$vid])) {
                    // Extract all words from vendor_transactions patterns for this vendor
                    $aliasWords = [];
                    foreach ($aliasMap[$vid] as $alias) {
                        $words = explode(' ', $alias['norm']);
                        foreach ($words as $word) {
                            if (strlen($word) > 1) {
                                $aliasWords[] = $word;
                            }
                        }
                    }
                    
                    // Find common words that appear in multiple aliases - these are likely filler/generic
                    $aliasCounts = array_count_values($aliasWords);
                    $vendorNameWords = explode(' ', $vendorNorm);
                    
                    // If a word appears in aliases but not in the main vendor name,
                    // or appears very frequently across aliases, it's likely filler
                    foreach ($aliasCounts as $word => $count) {
                        if (!in_array($word, $vendorNameWords) || $count > 1) {
                            $fillerWords[] = $word;
                        }
                    }
                }
                
                $fillerWords = array_unique($fillerWords);
                
                // Remove filler words from unique sets
                $ocrSignificant = array_diff($ocrUnique, $fillerWords);
                $vendorSignificant = array_diff($vendorUnique, $fillerWords);
                
                // Calculate overlap ratio - what percentage of unique significant words match
                // Use the smaller set as the denominator to be more strict
                $minTokens = min(count($ocrTokens), count($vendorTokens));
                $overlapRatio = $minTokens > 0 ? count($overlap) / $minTokens : 0;
                
                // Strong boost for exact token matches - each matching token is highly valuable
                // This helps "Village Of Mount" prefer "Village Of Mount Prospect" (3 matches)
                // over "Village Of Rosemont" (2 matches, even though "mount" is substring of "rosemont")
                $tokenMatchBoost = count($overlap) * 8; // Increased from 5 to 8 per match
                $score += min(50, $tokenMatchBoost); // Cap at 50 instead of 20
                
                // If both have significant unique words AND low overlap ratio, it's likely different businesses
                // This catches "Breckenridge Market" vs "Breckenridge Resort" dynamically
                // "Chicago Pizza Kitchen" vs "Chicago Pizza Delivery" - both have unique identifiers
                if (!empty($ocrSignificant) && !empty($vendorSignificant) && $overlapRatio < 0.75) {
                    // Both have distinguishing words that don't match, and overall similarity is low
                    // This indicates different businesses sharing common words
                    $score -= 40;
                }
            }

            // Dynamic alias boost based on vendor_transactions normalized patterns (substring matching)
            $vid = (int) ($vendor->id ?? 0);
            if ($vid && isset($aliasMap[$vid])) {
                foreach ($aliasMap[$vid] as $alias) {
                    $aNorm = $alias['norm'];
                    $aComp = $alias['compact'];
                    if (($aNorm !== '' && str_contains($ocrNormalized, $aNorm)) ||
                        ($aComp !== '' && str_contains($ocrCompact, $aComp))) {
                        // Strong boost if an alias appears in OCR text
                        $score += 35;
                        break; // one hit is enough
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestVendor = $vendor;
            }
        }

        return ($bestVendor && $bestScore >= $threshold) ? $bestVendor : null;
    }

    /**
     * Build a simple signature of line items using product codes and descriptions.
     *
     * @param array $fields
     * @return array{codes: string[], descriptions: string[]}
     */
    protected function buildItemsSignature(array $fields): array
    {
        $items = $fields['items'] ?? [];
        $codes = [];
        $descriptions = [];

        foreach ($items as $item) {
            if (!empty($item['ProductCode'])) {
                $codes[] = strtolower(trim((string) $item['ProductCode']));
            }
            if (!empty($item['Description'])) {
                // Normalize and strip extra whitespace
                $descriptions[] = preg_replace('/\s+/', ' ', strtolower(trim((string) $item['Description'])));
            }
        }

        return [
            'codes' => array_values(array_unique($codes)),
            'descriptions' => array_values(array_unique($descriptions)),
        ];
    }

    /**
     * Determine if two item signatures overlap by code or description.
     */
    protected function itemsOverlap(array $sigA, array $sigB): bool
    {
        // Code intersection
        $codeOverlap = count(array_intersect($sigA['codes'] ?? [], $sigB['codes'] ?? [])) > 0;

        if ($codeOverlap) {
            return true;
        }

        // Fallback: description similarity
        foreach ($sigA['descriptions'] ?? [] as $descA) {
            foreach ($sigB['descriptions'] ?? [] as $descB) {
                // Quick exact match
                if ($descA === $descB) {
                    return true;
                }

                // Loose similarity check
                similar_text($descA, $descB, $pct);
                if ($pct >= 90) { // very close
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract a Carbon time from receipt content. Falls back to null.
     * Accepts content and the date (Y-m-d) to bind time onto.
     */
    protected function extractReceiptTime(?string $content, ?string $date): ?\Carbon\Carbon
    {
        if (empty($content) || empty($date)) {
            return null;
        }

        // Match formats like "11:31 AM" or "12:26 PM"
        if (preg_match('/\b(1[0-2]|0?[1-9]):([0-5][0-9])\s?(AM|PM)\b/i', $content, $m)) {
            $timeStr = sprintf('%02d:%02d %s', (int) $m[1], (int) $m[2], strtoupper($m[3]));
            try {
                return \Carbon\Carbon::parse(trim($date) . ' ' . $timeStr);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Check if a receipt is a duplicate based on content, invoice number, and line items.
     */
    protected function isDuplicateReceipt(int $expense_id, string $receipt_html, array $receipt_items): bool
    {
        // No conversion needed - receipt_items is now always an array
        
        // Extract invoice number from receipt items
        $new_invoice_number = null;
        if (is_array($receipt_items) && isset($receipt_items['invoice_number'])) {
            $new_invoice_number = $receipt_items['invoice_number'];
        }
        
        // Build signature for new receipt
        $newItemsSignature = $this->buildItemsSignature($receipt_items ?? []);
        
        // Get existing receipts for this expense
        $existing_receipts = ExpenseReceipts::where('expense_id', $expense_id)->get();
        
        foreach ($existing_receipts as $existing_receipt) {
            // Check for exact HTML content match
            if ($existing_receipt->receipt_html === $receipt_html) {
                return true;
            }
            
            // Check for invoice number match if both have invoice numbers
            if ($new_invoice_number && $existing_receipt->receipt_items) {
                if (isset($existing_receipt->receipt_items['invoice_number']) && 
                    $existing_receipt->receipt_items['invoice_number'] === $new_invoice_number) {
                    return true;
                }
            }
            
            // Check for line items similarity - this is the key improvement
            if ($existing_receipt->receipt_items) {
                $existingItemsSignature = $this->buildItemsSignature($existing_receipt->receipt_items);
                
                // If line items overlap significantly, consider it a duplicate
                if ($this->itemsOverlap($newItemsSignature, $existingItemsSignature)) {
                    // Additional check: if both have the same transaction total, it's very likely a duplicate
                    $newTotal = $receipt_items['total'] ?? null;
                    $existingTotal = $existing_receipt->receipt_items['total'] ?? null;
                    
                    if ($newTotal && $existingTotal && abs((float)$newTotal - (float)$existingTotal) < 0.01) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Build receipt criteria from company email receipts
     */
    protected function buildReceiptCriteria($receipts): array
    {
        $receiptCriteria = []; // address => [subjects]
        
        foreach ($receipts as $receipt) {
            $address = strtolower(trim((string) $receipt->from_address));
            $subject = strtolower(trim((string) $receipt->from_subject ?? ''));
            
            // Only process valid addresses (domain patterns start with @, emails contain @)
            if (str_starts_with($address, '@') || str_contains($address, '@')) {
                if (!isset($receiptCriteria[$address])) {
                    $receiptCriteria[$address] = [];
                }
                if ($subject !== '' && !in_array($subject, $receiptCriteria[$address])) {
                    $receiptCriteria[$address][] = $subject;
                }
            }
        }
        
        return $receiptCriteria;
    }

    /**
     * Forward (copy) recent receipt candidate emails from each connected grant/mailbox
    * into the central receipts mailbox (NYLAS_HIVE_RECEIPTS_GRANT_ID / NYLAS_HIVE_RECEIPTS_EMAIL).
     * This gives us a single place to process and control receipts instead of per-user folders.
     *
     * Strategy:
     *  - Iterate all CompanyEmail records with a grant_id.
     *  - For each, fetch recent messages (limit + last X days defined by nylas config).
     *  - Filter to likely receipt emails (simple heuristic: has attachments OR subject contains keywords).
     *  - Call NylasService::forwardMessage for each candidate.
     *  - Log successes/failures; skip duplicates within this run.
     *
     * NOTE: This does NOT mark or move the original message; it only forwards a copy.
     * Idempotency heuristic: keep an in-memory set of forwarded message IDs per execution.
     */
    public function forwardRecentReceiptEmailsToCentral()
    {
        $companyEmails = CompanyEmail::withoutGlobalScopes()
            ->with(['receipts' => function($query) {
                // Only load receipts we'll actually use
                $query->whereNotNull('from_address')->where('from_address', '!=', '');
            }, 'vendor'])
            ->whereNotNull('grant_id')
            ->get();

        if ($companyEmails->isEmpty()) {
            return;
        }

        // Pre-calculate global date filter once
        $messageLimitDate = Carbon::now()->subDays(config('nylas.message_limit_days', 30));
        foreach ($companyEmails as $companyEmail) {
            $this->processCompanyEmailForwarding($companyEmail, $messageLimitDate);
        }
    }

    /**
     * Process forwarding for a single company email
     */
    protected function processCompanyEmailForwarding(CompanyEmail $companyEmail, Carbon $messageLimitDate): void
    {
        if ($companyEmail->receipts->isEmpty()) {
            return;
        }

        $grantId = $companyEmail->grant_id;

        // Build receipt criteria for filtering
        $receiptCriteria = $this->buildReceiptCriteria($companyEmail->receipts);
        if (empty($receiptCriteria)) {
            return;
        }

        // Calculate received_after date for this company email
        $receivedAfter = $this->calculateReceivedAfterDate($companyEmail, $messageLimitDate);

        // If received_after is in the future (shouldn't happen), skip
        // Fetch and filter messages using NylasService
        $matchingMessages = $this->nylasService->getMessagesMatchingCriteria(
            $grantId, 
            $receiptCriteria, 
            $receivedAfter,
            $companyEmail
        );

        // Forward each matching message
        foreach ($matchingMessages as $message) {
            $this->nylasService->sendForwardCopy(
                $grantId,
                $message['id'],
                true,
                $companyEmail->id
            );
        }
    }

    /**
     * Calculate the received_after date for message filtering
     */
    protected function calculateReceivedAfterDate(CompanyEmail $companyEmail, Carbon $messageLimitDate): Carbon
    {
        $vendorRegistrationDate = $companyEmail->vendor->registrationDate;
        
        // Maximum lookback is 7 days to prevent API timeouts
        $maxLookback = Carbon::now()->subDays(7);
        
        if ($vendorRegistrationDate) {
            // Use the most recent of: message limit, registration date, or max lookback
            $calculatedDate = $messageLimitDate->greaterThan($vendorRegistrationDate) 
                ? $messageLimitDate 
                : $vendorRegistrationDate;
                
            // Ensure we don't go back more than 7 days to prevent timeouts
            return $calculatedDate->greaterThan($maxLookback) 
                ? $calculatedDate 
                : $maxLookback;
        }
        
        // Ensure message limit date isn't older than max lookback
        return $messageLimitDate->greaterThan($maxLookback) 
            ? $messageLimitDate 
            : $maxLookback;
    }

    /**
     * Find matching receipt using intelligent matching rules:
     * 1. from_address can be wildcard like "@stripe.com" (matches any email ending with domain)
     * 2. from_subject can be partial match (receipt subject must be contained in message subject)
     * 
     * @param string $messageFromEmail The email address from the incoming message
     * @param string $messageSubject The subject line from the incoming message
     * @return Receipt|null The matching receipt or null if none found
     */
    protected function findMatchingReceipt(string $messageFromEmail, string $messageSubject): ?Receipt
    {
        // Get all receipts with non-null receipt_type and non-null from_address
        $receipts = Receipt::whereNotNull('receipt_type')
            ->where('receipt_type', '!=', 0)
            ->whereNotNull('from_address')
            ->where('from_address', '!=', '')
            ->get();
        
        foreach ($receipts as $receipt) {
            $receiptFromAddress = $receipt->from_address ?? '';
            $receiptFromSubject = $receipt->from_subject ?? '';
            
            // Skip if receipt has no from_address
            if (empty($receiptFromAddress)) {
                continue;
            }
            
            // Check from_address matching
            $emailMatches = false;
            
            if (str_starts_with($receiptFromAddress, '@')) {
                // Wildcard domain match (e.g., "@stripe.com" matches "invoice@stripe.com")
                $domain = $receiptFromAddress; // includes the @
                $emailMatches = str_ends_with($messageFromEmail, $domain);
            } else {
                // Exact email match (case-insensitive)
                $emailMatches = strcasecmp($receiptFromAddress, $messageFromEmail) === 0;
            }
            
            // If email doesn't match, skip to next receipt
            if (!$emailMatches) {
                continue;
            }
            
            // Check from_subject matching
            $subjectMatches = false;
            
            if (empty($receiptFromSubject)) {
                // If receipt has no subject requirement, email match is sufficient
                $subjectMatches = true;
            } else {
                // Partial match: receipt subject must be contained in message subject (case-insensitive)
                // Example: "AT&T payment processed for account ending in" matches "AT&T payment processed for account ending in 1733"
                $subjectMatches = stripos($messageSubject, $receiptFromSubject) !== false;
            }
            
            // If both email and subject match, return this receipt
            if ($emailMatches && $subjectMatches) {
                return $receipt;
            }
        }
        
        // No matching receipt found
        return null;
    }

    /**
     * Check if a receipt is a duplicate across all expenses before creating a new expense.
     * This prevents creating orphan expenses that would immediately be detected as duplicates.
     *
     * @param int $belongs_to_vendor_id
     * @param int $vendor_id
     * @param string $amount
     * @param string $date
     * @param string $receipt_html
     * @param array $receipt_items
     * @return Expense|null Returns the existing expense if duplicate found, null otherwise
     */
    protected function findExpenseWithDuplicateReceipt(
        int $belongs_to_vendor_id,
        int $vendor_id,
        string $amount,
        string $date,
        string $receipt_html,
        array $receipt_items
    ): ?Expense {
        // Get candidate expenses with same vendor, amount, and date (±5 days)
        $startDate = Carbon::parse($date)->subDays(5)->format('Y-m-d');
        $endDate = Carbon::parse($date)->addDays(5)->format('Y-m-d');

        $candidates = Expense::withoutGlobalScopes()
            ->with('receipts')
            ->where('belongs_to_vendor_id', $belongs_to_vendor_id)
            ->where('vendor_id', $vendor_id)
            ->whereNull('deleted_at')
            ->where('amount', $amount)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Build signature for the new receipt
        $newItemsSignature = $this->buildItemsSignature($receipt_items ?? []);
        $newInvoice = trim((string)($receipt_items['invoice_number'] ?? ''));

        foreach ($candidates as $candidate) {
            $existingReceipts = $candidate->receipts;

            if ($existingReceipts->isEmpty()) {
                // Candidate has no receipts - this is a match
                // (same vendor, amount, date - very likely the same expense)
                return $candidate;
            }

            foreach ($existingReceipts as $existingReceipt) {
                // Check for exact HTML content match
                if ($existingReceipt->receipt_html === $receipt_html) {
                    return $candidate;
                }

                // Check for invoice number match if both have invoice numbers
                if ($newInvoice !== '' && 
                    isset($existingReceipt->receipt_items['invoice_number']) &&
                    $existingReceipt->receipt_items['invoice_number'] === $newInvoice) {
                    return $candidate;
                }

                // Check for line items similarity
                if ($existingReceipt->receipt_items) {
                    $existingItemsSignature = $this->buildItemsSignature($existingReceipt->receipt_items);

                    // If line items overlap significantly, check the total amount
                    if ($this->itemsOverlap($newItemsSignature, $existingItemsSignature)) {
                        $newTotal = $receipt_items['total'] ?? null;
                        $existingTotal = $existingReceipt->receipt_items['total'] ?? null;

                        if ($newTotal && $existingTotal && abs((float)$newTotal - (float)$existingTotal) < 0.01) {
                            return $candidate;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Consolidate partial expenses by transferring their transactions and checks to a new consolidated expense.
     *
     * @param Expense $consolidatedExpense The expense to consolidate into
     * @param \Illuminate\Support\Collection $partialExpenses Collection of partial expenses to consolidate
     * @param string $logPrefix Prefix for log messages (e.g. 'AutoReceipts:' or '')
     * @return void
     */
    protected function consolidatePartialExpenses(
        Expense $consolidatedExpense,
        \Illuminate\Support\Collection $partialExpenses,
        string $logPrefix = ''
    ): void {
        $checkIds = [];
        
        foreach ($partialExpenses as $partialExpense) {
            // Transfer any transactions from partial expense
            $transactions = Transaction::withoutGlobalScopes()
                ->where('expense_id', $partialExpense->id)
                ->get();
            
            if ($transactions->isNotEmpty()) {
                foreach ($transactions as $transaction) {
                    $transaction->expense_id = $consolidatedExpense->id;
                    $transaction->save();
                }
                
                Log::channel('nylas')->info(trim($logPrefix . ' Transferred transactions from partial expense'), [
                    'partial_expense_id' => $partialExpense->id,
                    'transaction_ids' => $transactions->pluck('id')->toArray(),
                    'new_expense_id' => $consolidatedExpense->id,
                ]);
            }
            
            // Track check_id for many-to-many linking
            if ($partialExpense->check_id) {
                $checkIds[] = $partialExpense->check_id;
            }
            
            // Soft delete the partial expense
            Log::channel('nylas')->info(trim($logPrefix . ' Soft deleting consolidated partial expense'), [
                'partial_expense_id' => $partialExpense->id,
                'partial_amount' => $partialExpense->amount,
                'check_id' => $partialExpense->check_id,
                'new_expense_id' => $consolidatedExpense->id,
            ]);
            $partialExpense->delete(); // Soft delete
        }
        
        // Link checks to the new expense via many-to-many relationship
        if (!empty($checkIds)) {
            $consolidatedExpense->checks()->attach($checkIds);
            Log::channel('nylas')->info(trim($logPrefix . ' Linked checks to consolidated expense'), [
                'expense_id' => $consolidatedExpense->id,
                'check_ids' => $checkIds,
            ]);
        }
    }

    /**
     * Find partial expenses that sum to the receipt total for consolidation.
     * This handles cases where users manually split expenses before receiving the full receipt.
     *
     * @param int $belongs_to_vendor_id
     * @param int $vendor_id
     * @param string $amount The full receipt total
     * @param string $date Receipt date
     * @param string|null $invoice Receipt invoice number if available
     * @return \Illuminate\Support\Collection Collection of expenses to consolidate, empty if none found
     */
    protected function findPartialExpensesToConsolidate(
        int $belongs_to_vendor_id,
        int $vendor_id,
        string $amount,
        string $date,
        ?string $invoice = null
    ): \Illuminate\Support\Collection {
        // Look for expenses in a ±5 day window
        $startDate = Carbon::parse($date)->subDays(5)->format('Y-m-d');
        $endDate = Carbon::parse($date)->addDays(5)->format('Y-m-d');

        // Find all potential partial expenses for this vendor without receipts
        $candidates = Expense::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $belongs_to_vendor_id)
            ->where('vendor_id', $vendor_id)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$startDate, $endDate])
            ->whereDoesntHave('receipts') // Only expenses without receipts (manually created)
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $receiptTotal = (float) $amount;
        
        // If invoice number exists, only match expenses with the same invoice OR no invoice
        if ($invoice !== null && $invoice !== '') {
            $candidates = $candidates->filter(function ($expense) use ($invoice) {
                return empty($expense->invoice) || $expense->invoice === $invoice;
            });
        }

        // Try to find combinations that sum to the receipt total
        // Start with checking if 2 expenses sum to total (most common case)
        $candidateArray = $candidates->toArray();
        $count = count($candidateArray);
        
        // Check pairs (most common: user splits into 2 parts)
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $sum = (float)$candidateArray[$i]['amount'] + (float)$candidateArray[$j]['amount'];
                if (abs($sum - $receiptTotal) < 0.01) { // Within 1 cent
                    return collect([
                        Expense::find($candidateArray[$i]['id']),
                        Expense::find($candidateArray[$j]['id'])
                    ]);
                }
            }
        }
        
        // Check triplets (less common but possible)
        if ($count >= 3) {
            for ($i = 0; $i < $count - 2; $i++) {
                for ($j = $i + 1; $j < $count - 1; $j++) {
                    for ($k = $j + 1; $k < $count; $k++) {
                        $sum = (float)$candidateArray[$i]['amount'] + 
                               (float)$candidateArray[$j]['amount'] + 
                               (float)$candidateArray[$k]['amount'];
                        if (abs($sum - $receiptTotal) < 0.01) {
                            return collect([
                                Expense::find($candidateArray[$i]['id']),
                                Expense::find($candidateArray[$j]['id']),
                                Expense::find($candidateArray[$k]['id'])
                            ]);
                        }
                    }
                }
            }
        }

        return collect();
    }
}
