<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\TransactionBulkMatch;
use App\Models\User;
use App\Models\UserVendor;
use App\Models\Vendor;

use App\Services\NylasService;
use App\Services\ReceiptItemEnricher;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

use Carbon\Carbon;
use Spatie\Browsershot\Browsershot;
use Intervention\Image\Facades\Image;

use Exception;
use App\Support\ApiErrorFormatter;
use App\Support\MaterialOrderStatus;
 
class CompanyEmailController extends Controller
{
    private $nylasService;

    public function __construct(NylasService $nylasService)
    {
        $this->nylasService = $nylasService;
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
        // Prevent the script from being killed when the HTTP client disconnects
        // (e.g. browser tab closed, page refresh, proxy timeout). Without this,
        // PHP silently terminates the process — bypassing catch blocks — leaving
        // emails stuck in the source folder indefinitely.
        ignore_user_abort(true);
        set_time_limit(0);

        $grantId = config('nylas.receipts_grant_id');

        // Use the configured inbox folder ID (production) or test folder (non-production)
        $folder = app()->environment('production')
            ? config('nylas.receipts_inbox_folder_id')
            : (config('nylas.hive_receipts_test_folder_id') ?: config('nylas.receipts_inbox_folder_id'));

        try {
            $allMessages = $this->nylasService->fetchFolderMessages($grantId, $folder, [
                'full_fetch' => true,
                'include_headers' => true,
            ]);
        } catch (\Throwable $e) {
            Log::channel('nylas')->error('Failed to fetch receipt messages from folder', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
                'folder' => $folder,
            ]));
            return;
        }

        foreach($allMessages as $message) {
            $messageId = $message['id'] ?? null;

            try {
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
                $headers = $message['headers'];

                // Shape A: list of header objects like [{name: 'X-Hive-Metadata', value: '...'}]
                foreach ($headers as $hdr) {
                    if (!is_array($hdr)) {
                        continue;
                    }

                    if (isset($hdr['name']) && is_string($hdr['name']) && strcasecmp($hdr['name'], 'X-Hive-Metadata') === 0) {
                        $decoded = json_decode((string) ($hdr['value'] ?? ''), true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $hiveMeta = $decoded;
                        }
                        break;
                    }
                }

                // Shape B: associative map like ['X-Hive-Metadata' => '{...}']
                if (!$hiveMeta && array_key_exists('X-Hive-Metadata', $headers)) {
                    $decoded = json_decode((string) ($headers['X-Hive-Metadata'] ?? ''), true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $hiveMeta = $decoded;
                    }
                }

                if (!$hiveMeta && array_key_exists('x-hive-metadata', $headers)) {
                    $decoded = json_decode((string) ($headers['x-hive-metadata'] ?? ''), true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $hiveMeta = $decoded;
                    }
                }
            }

            if (!$hiveMeta) {
                Log::channel('nylas')->warning('Missing X-Hive-Metadata header; using fallback message fields', [
                    'grant_id' => $grantId,
                    'message_id' => $messageId,
                ]);
            }

            // Prefer metadata when present; otherwise use best-effort message fields.
            $fromEmail = $hiveMeta['from_email']
                ?? ($message['from'][0]['email'] ?? '');
            $toEmail = $hiveMeta['to_email']
                ?? ($message['to'][0]['email'] ?? config('nylas.receipts_email'));
            $subject = $hiveMeta['subject']
                ?? ($message['subject'] ?? '');

            // Prefer original_date (Y-m-d string parsed from the forwarded body's Sent: line).
            // Fall back to unix_date (the forward timestamp) with timezone adjustment.
            if (isset($hiveMeta['original_date'])) {
                // Stored as Y-m-d string — use directly, no timezone conversion needed.
                $dateEmail = $hiveMeta['original_date'];
            } else {
                $fallbackUnixDate = $hiveMeta['unix_date'] ?? ($message['date'] ?? time());
                $dateEmail = Carbon::createFromTimestamp((int) $fallbackUnixDate)
                    ->setTimezone('America/Chicago')
                    ->format('Y-m-d');

                // Fallback for emails forwarded before original_date was added to X-Hive-Metadata:
                // Parse the "Sent:" line from the Outlook forwarded-message block in the body.
                // No timezone conversion — the Sent: value is a plain date string, not a timestamp.
                if (!empty($message['body'])) {
                    $bodyText = str_replace("\xc2\xa0", ' ', html_entity_decode(strip_tags($message['body'])));
                    if (preg_match('/\bSent:\s*(?:\w+,\s*)?(\w+ \d{1,2},\s*\d{4})/i', $bodyText, $dateMatch)) {
                        try {
                            $dateEmail = Carbon::parse(trim($dateMatch[1]))->format('Y-m-d');
                        } catch (\Exception $ex) {
                            // Could not parse; keep existing $dateEmail
                        }
                    }
                }
            }

            $companyEmail = CompanyEmail::withoutGlobalScopes()
                ->where('email', $toEmail)
                ->first();

            if (!$companyEmail) {
                $companyEmail = CompanyEmail::withoutGlobalScopes()
                    ->where('grant_id', $grantId)
                    ->first();
            }

            // Fallback for manually forwarded emails lacking X-Hive-Metadata:
            // try to recover original recipient from forwarded body "To:" lines.
            if (!$companyEmail && !empty($message['body'])) {
                if (preg_match_all('/\bTo:.*?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $message['body'], $toMatches)) {
                    foreach ($toMatches[1] as $candidateEmail) {
                        $candidateEmail = strtolower(trim((string) $candidateEmail));
                        if (!filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
                            continue;
                        }

                        $candidateCompanyEmail = CompanyEmail::withoutGlobalScopes()
                            ->where('email', $candidateEmail)
                            ->first();

                        if ($candidateCompanyEmail) {
                            $companyEmail = $candidateCompanyEmail;
                            $toEmail = $candidateEmail;
                            break;
                        }
                    }
                }
            }

            if (!$companyEmail) {
                Log::channel('nylas')->warning('Unable to resolve CompanyEmail for receipt message — will attempt PDF probe with config defaults', [
                    'grant_id' => $grantId,
                    'to_email' => $toEmail,
                    'message_id' => $messageId,
                ]);
            }

            $folderMap = [
                'Error' => data_get($companyEmail?->api_json, 'folders.Error') ?? config('nylas.hive_receipts_error_folder_id'),
                'Duplicate' => data_get($companyEmail?->api_json, 'folders.Duplicates') ?? config('nylas.hive_receipts_duplicate_folder_id'),
                'Add' => data_get($companyEmail?->api_json, 'folders.Add') ?? config('nylas.hive_receipts_need_to_add_folder_id'),
                'Saved' => data_get($companyEmail?->api_json, 'folders.Saved') ?? config('nylas.hive_receipts_saved_folder_id'),
            ];

            // Coerce a blank model when no DB record was found so all downstream
            // ->id / ->vendor_id accesses return null instead of throwing.
            $companyEmail ??= new CompanyEmail();

            // Find Receipt using intelligent matching:
            // 1. from_address can be wildcard like "@stripe.com" (matches any email ending with @stripe.com)
            // 2. from_subject can be partial match (e.g., "AT&T payment processed" matches "AT&T payment processed for account ending in 1733")
            // Strip common email prefixes (Fw:, Fwd:, Re:) that may have been added during forwarding
            $cleanSubject = preg_replace('/^(fw:|fwd:|re:)\s*/i', '', $subject);

            // Preserve the original metadata email before it may be overwritten by body-From fallback.
            // Used later to detect the belongs-to vendor (e.g. jenn@jpeterson-design.com → vendor 60).
            $metadataFromEmail = $fromEmail;

            $receipt = $this->findMatchingReceipt($fromEmail, $cleanSubject);

            // Fallback: if no match and body contains a "From:" line, try extracting the original sender.
            // This handles cases where the forwarding system captured the forwarder's address
            // instead of the original sender (e.g. szadoky5@hotmail.com instead of orders@em.autozone.com).
            if (!$receipt && !empty($message['body'])) {
                // Search raw HTML body — strip_tags destroys forwarded-message "From:" lines embedded in HTML
                if (preg_match_all('/\bFrom:.*?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $message['body'], $fromMatches)) {
                    foreach ($fromMatches[1] as $candidateEmail) {
                        $bodyFromEmail = strtolower(trim($candidateEmail));
                        if ($bodyFromEmail === strtolower($fromEmail) || !filter_var($bodyFromEmail, FILTER_VALIDATE_EMAIL)) {
                            continue;
                        }
                        $receipt = $this->findMatchingReceipt($bodyFromEmail, $cleanSubject);
                        if ($receipt) {
                            Log::channel('nylas')->info('Receipt matched using body-extracted from_email fallback', [
                                'message_id' => $messageId,
                                'metadata_from_email' => $fromEmail,
                                'body_from_email' => $bodyFromEmail,
                                'receipt_id' => $receipt->id,
                            ]);
                            $fromEmail = $bodyFromEmail;
                            break;
                        }
                    }
                }
            }

            // Attachment-based vendor fallback: if no Receipt matched and the message has
            // PDF attachments, download the first PDF, extract text with a generic analyzer,
            // and try to identify the vendor from the extracted content.
            $attachmentPdfPath = null;
            $attachmentPdfFilename = null;
            if (!$receipt && !empty($message['attachments'])) {
                $pdfAttachment = collect($message['attachments'])->first(function ($att) {
                    $ct = strtolower((string) ($att['content_type'] ?? ''));
                    $fn = strtolower((string) ($att['filename'] ?? ''));
                    return stripos($ct, 'pdf') !== false
                        || str_ends_with($fn, '.pdf');
                });

                if ($pdfAttachment) {
                    try {
                        $probeFilename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '-probe.pdf';
                        $probePath = '_temp_ocr/' . $probeFilename;
                        if (!Storage::disk('files')->exists('_temp_ocr')) {
                            Storage::disk('files')->makeDirectory('_temp_ocr');
                        }

                        $pdfContent = $this->nylasService->downloadAttachment($pdfAttachment['id'], $grantId, $messageId);
                        Storage::disk('files')->put($probePath, $pdfContent);

                        $probeResult = app(\App\Http\Controllers\ReceiptController::class)
                            ->extractReceipt($probePath, 'pdf', null, 'email', null, 'prebuilt-invoice');

                        if (is_array($probeResult) && !empty($probeResult['content'])) {
                            $probeText = strtolower($probeResult['content']);

                            // Build vendor lookup from active Receipt configs
                            $receiptsByVendor = Receipt::whereNotNull('receipt_type')
                                ->where('receipt_type', '!=', 0)
                                ->whereNotNull('from_address')
                                ->where('from_address', '!=', '')
                                ->get()
                                ->groupBy('vendor_id');

                            $vendors = Vendor::whereIn('id', $receiptsByVendor->keys()->all())->get();

                            foreach ($vendors as $vendor) {
                                $name = strtolower(trim($vendor->business_name ?? ''));
                                if ($name !== '' && str_contains($probeText, $name)) {
                                    $matchedReceipts = $receiptsByVendor->get($vendor->id);
                                    $receipt = $matchedReceipts->first(fn ($r) => !empty($r->options['document_model']))
                                        ?? $matchedReceipts->first();

                                    if ($receipt) {
                                        $attachmentPdfPath = $probePath;
                                        $attachmentPdfFilename = $probeFilename;

                                        Log::channel('nylas')->info('Receipt matched via PDF attachment vendor detection', [
                                            'message_id' => $messageId,
                                            'vendor_id' => $vendor->id,
                                            'vendor_name' => $vendor->business_name,
                                            'receipt_id' => $receipt->id,
                                            'pdf_filename' => $pdfAttachment['filename'] ?? null,
                                        ]);
                                        break;
                                    }
                                }
                            }
                        }

                        // Fallback: no Receipt config matched, but try all known vendors by name.
                        // This handles the case where the DB was reset (configs wiped) or the vendor
                        // simply has no Receipt config yet — the PDF still contains enough info to process.
                        // PDF attachments forwarded to the receipts inbox are almost always supplier
                        // invoices / material orders, so default to the material order analyzer.
                        $materialOrderModel = config('services.azure_cu.analyzer_id_material_order');
                        if (!$receipt && isset($probeText)) {
                            $allVendors = Vendor::whereNotNull('business_name')
                                ->where('business_name', '!=', '')
                                ->get();

                            foreach ($allVendors as $vendor) {
                                $name = strtolower(trim($vendor->business_name));
                                if ($name !== '' && str_contains($probeText, $name)) {
                                    $receipt = new Receipt([
                                        'vendor_id' => $vendor->id,
                                        'options' => [
                                            'pdf_html' => true,
                                            'document_model' => $materialOrderModel,
                                        ],
                                    ]);
                                    $attachmentPdfPath = $probePath;
                                    $attachmentPdfFilename = $probeFilename;

                                    Log::channel('nylas')->info('Receipt matched via PDF vendor name (no Receipt config — material order fallback)', [
                                        'message_id' => $messageId,
                                        'vendor_id' => $vendor->id,
                                        'vendor_name' => $vendor->business_name,
                                        'document_model' => $materialOrderModel,
                                        'pdf_filename' => $pdfAttachment['filename'] ?? null,
                                    ]);
                                    break;
                                }
                            }
                        }

                        // Last resort: no vendor detected at all — still process the PDF with the
                        // material order analyzer so OCR data is captured before the email lands in Add.
                        if (!$receipt && isset($probePath) && Storage::disk('files')->exists($probePath)) {
                            $receipt = new Receipt([
                                'vendor_id' => null,
                                'options' => [
                                    'pdf_html' => true,
                                    'document_model' => $materialOrderModel,
                                ],
                            ]);
                            $attachmentPdfPath = $probePath;
                            $attachmentPdfFilename = $probeFilename;

                            Log::channel('nylas')->info('No vendor detected in PDF — using material order analyzer fallback', [
                                'message_id' => $messageId,
                                'document_model' => $materialOrderModel,
                                'pdf_filename' => $pdfAttachment['filename'] ?? null,
                            ]);
                        }

                        // Clean up probe file if no vendor matched
                        if (!$receipt) {
                            Storage::disk('files')->delete($probePath);
                        }
                    } catch (\Throwable $e) {
                        Log::channel('nylas')->warning('PDF attachment vendor probe failed', [
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                        if (isset($probePath)) {
                            Storage::disk('files')->delete($probePath);
                        }
                    }
                }
            }

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

                    $useLastStartMatch = !empty($receipt->options['receipt_start_last']);
                    $includeStartText = !empty($receipt->options['include_receipt_start_text']);
                    $startOffset = isset($receipt->options['receipt_start_offset'])
                        ? intval($receipt->options['receipt_start_offset'])
                        : 0;

                    foreach ($starts as $start_text) {
                        $matchPositions = [];
                        $searchPos = 0;
                        while (($pos = strpos($string, $start_text, $searchPos)) !== false) {
                            $matchPositions[] = $pos;
                            $searchPos = $pos + max(strlen($start_text), 1);
                        }

                        if (!empty($matchPositions)) {
                            $pos = $useLastStartMatch ? end($matchPositions) : $matchPositions[0];

                            $receipt_start = (int) $pos + $startOffset + ($includeStartText ? 0 : strlen($start_text));
                            if ($receipt_start < 0) {
                                $receipt_start = 0;
                            }

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

                // Optionally remove embedded images from extracted receipt HTML.
                if (!empty($receipt->options['remove_images'])) {
                    $receipt_html_main = preg_replace('/<img\b[^>]*>/i', '', $receipt_html_main) ?? $receipt_html_main;
                    $receipt_html_main = preg_replace('/<v:imagedata\b[^>]*\/?>(?:<\/v:imagedata>)?/i', '', $receipt_html_main) ?? $receipt_html_main;
                    $receipt_html_main = preg_replace('/background-image\s*:\s*url\([^)]*\)\s*;?/i', '', $receipt_html_main) ?? $receipt_html_main;
                }

                // Strip HTML tags and convert to readable text with line breaks
                // Useful when substr() extraction breaks HTML table structure
                if (!empty($receipt->options['strip_html'])) {
                    // Convert block-level elements to newlines
                    $receipt_html_main = preg_replace('/<br\s*\/?>/i', "\n", $receipt_html_main);
                    $receipt_html_main = preg_replace('/<\/(?:td|tr|p|div|li|h[1-6])>/i', "\n", $receipt_html_main);
                    // Strip all remaining HTML tags
                    $receipt_html_main = strip_tags($receipt_html_main);
                    // Decode HTML entities
                    $receipt_html_main = html_entity_decode($receipt_html_main, ENT_QUOTES, 'UTF-8');
                    // Collapse multiple blank lines into max two newlines, trim each line
                    $lines = array_map('trim', explode("\n", $receipt_html_main));
                    $lines = array_filter($lines, fn ($line) => $line !== '');
                    $receipt_html_main = implode("\n", $lines);
                    // Force text mode so the Blade template uses <pre> wrapper
                    $bodyType = 'text';
                }

                // DEBUG: log extraction results to diagnose blank PDF
                Log::channel('nylas')->info('Receipt HTML extraction', [
                    'receipt_id' => $receipt->id,
                    'message_id' => $messageId,
                    'receipt_start_found' => $receipt_start_text,
                    'receipt_start_pos' => $receipt_start,
                    'receipt_end_pos' => $receipt_end,
                    'receipt_html_main_len' => strlen($receipt_html_main),
                    'receipt_html_main_preview' => substr(strip_tags($receipt_html_main), 0, 300),
                ]);

                // Set defaults at the top
                $doc_type = 'pdf'; // Default to PDF for most cases
                $ocr_filename = date('Y-m-d-H-i-s') . '-' . rand(10, 99);

                if ($attachmentPdfPath) {
                    // Matched via PDF attachment vendor detection — PDF already downloaded
                    $ocr_filename = $attachmentPdfFilename;
                    $ocr_path = $attachmentPdfPath;
                } elseif (
                    !isset($receipt->options['receipt_image_regex'])
                    && !isset($receipt->options['pdf_html'])
                    && ($receipt->options['document_model'] ?? null) === config('services.azure_cu.analyzer_id_material_order')
                    && ($materialOrderPdfAtt = $this->firstPdfAttachment($message['attachments'] ?? [])) !== null
                ) {
                    // Perf: material orders forwarded as PDF attachments don't need an
                    // HTML→PDF render of the email body (~57s Browsershot) followed by an
                    // OCR pass of that rendered body (~74s Azure CU). The actual PDF
                    // attachment is the source of truth, and saveExpenseReceipt() will
                    // reuse our $ocr_receipt_data for the first attachment.
                    $doc_type = 'pdf';
                    $ocr_filename .= '.' . $doc_type;
                    $ocr_path = '_temp_ocr/' . $ocr_filename;

                    if (!Storage::disk('files')->exists('_temp_ocr')) {
                        Storage::disk('files')->makeDirectory('_temp_ocr');
                    }

                    $pdfContent = $this->nylasService->downloadAttachment(
                        $materialOrderPdfAtt['id'],
                        $grantId,
                        $messageId
                    );
                    Storage::disk('files')->put($ocr_path, $pdfContent);

                    Log::channel('nylas')->info('Material order: skipping HTML→PDF render, OCRing PDF attachment directly', [
                        'message_id' => $messageId,
                        'receipt_id' => $receipt->id,
                        'pdf_filename' => $materialOrderPdfAtt['filename'] ?? null,
                        'pdf_size' => strlen($pdfContent),
                    ]);
                } elseif (!isset($receipt->options['receipt_image_regex']) && !isset($receipt->options['pdf_html'])) {
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
                            'no-sandbox',
                            'disable-setuid-sandbox',
                            'disable-dev-shm-usage',
                            'disable-gpu',
                            'single-process',
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
                        // No attachments found - log and move to error folder
                        Log::channel('nylas')->warning('Receipt requires PDF attachment but message has none', [
                            'grant_id' => $grantId,
                            'company_email_id' => $companyEmail->id,
                            'message_id' => $messageId,
                            'receipt_id' => $receipt->id,
                            'from_email' => $fromEmail,
                            'to_email' => $toEmail,
                            'subject' => $subject,
                        ]);
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
                        Log::channel('nylas')->warning('Empty image URL for receipt - image regex did not match', [
                            'receipt_id' => $receipt->id,
                            'message_id' => $messageId,
                            'from_email' => $fromEmail,
                            'to_email' => $toEmail,
                            'subject' => $subject,
                            'receipt_image_regex' => $receipt->options['receipt_image_regex'] ?? null,
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

                $document_model = $receipt->options['document_model'] ?? null;
                $isMaterialOrder = $document_model === config('services.azure_cu.analyzer_id_material_order');

                if (!$document_model) {
                    $this->nylasService->moveEmailToFolder($messageId, $folderMap['Add'], $grantId, $companyEmail->id);
                    Log::channel('nylas')->warning('Receipt is missing document_model option - moved to Add folder', [
                        'receipt_id' => $receipt->id,
                        'message_id' => $messageId,
                    ]);
                    continue;
                }

                // Use 'material_order' docType so extractReceipt extracts Status/ETA/Area fields
                $effectiveDocType = $isMaterialOrder ? 'material_order' : $doc_type;

                //ocr the file via unified extractReceipt()
                $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->extractReceipt($ocr_path, $effectiveDocType, null, 'email', $receipt, $document_model);

                // DEBUG: log OCR results
                Log::channel('nylas')->info('Receipt OCR result', [
                    'receipt_id' => $receipt->id,
                    'message_id' => $messageId,
                    'ocr_content_len' => strlen($ocr_receipt_data['content'] ?? ''),
                    'ocr_content_preview' => substr($ocr_receipt_data['content'] ?? '', 0, 300),
                    'ocr_fields' => $ocr_receipt_data['fields'] ?? [],
                ]);

                // Some OCR providers / failure modes can return a partial payload without a "fields" key.
                // This method is executed by the scheduler; avoid crashing the whole run.
                if (! is_array($ocr_receipt_data) || ($ocr_receipt_data['error'] ?? false) === true) {
                    if (! empty($folderMap['Error'])) {
                        $this->nylasService->moveEmailToFolder($messageId, $folderMap['Error'], $grantId, $companyEmail->id);
                    }
                    Log::channel('nylas')->warning('OCR extract returned error or non-array payload — moved to Error folder', [
                        'receipt_id' => $receipt->id,
                        'company_email_id' => $companyEmail->id,
                        'message_id' => $messageId,
                        'ocr_error' => is_array($ocr_receipt_data) ? ($ocr_receipt_data['error'] ?? null) : null,
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

                // Detect the "bill to" vendor from OCR content (e.g. forwarded vendor orders
                // where the buyer isn't the company email owner)
                $detectedBillToVendorId = $this->detectBillToVendorId(
                    $ocr_receipt_data['fields']['raw_content'] ?? '',
                    $receipt->vendor_id // exclude the selling vendor
                );

                // Fallback: resolve the forwarding email address to a vendor
                // (e.g. jenn@jpeterson-design.com → user 37 → vendor 60 via user_vendor)
                $emailDetectedVendorId = $this->detectBelongsToVendorFromEmail(
                    $metadataFromEmail,
                    $receipt->vendor_id
                );

                $effectiveBelongsToVendorId = $detectedBillToVendorId
                    ?? $emailDetectedVendorId
                    ?? ($receipt_account->belongs_to_vendor_id ?? $companyEmail->vendor_id);

                // Detect a "sold to" / "bill to" client (e.g. material order PDFs that are
                // billed to a homeowner). This is independent of belongs_to_vendor_id —
                // a contractor (vendor) can purchase materials FOR a specific client.
                $detectedBillToClientId = $this->detectBillToClientId(
                    $ocr_receipt_data['fields']['raw_content'] ?? ''
                );
                $detectedProjectId = null;
                if ($detectedBillToClientId) {
                    $detectedProjectId = $this->resolveProjectIdForClient(
                        $detectedBillToClientId,
                        $companyEmail->vendor_id
                    );
                    Log::channel('nylas')->info('Detected bill-to client', [
                        'message_id' => $messageId,
                        'client_id' => $detectedBillToClientId,
                        'project_id' => $detectedProjectId,
                        'receipt_id' => $receipt->id,
                    ]);
                }

                $detectedVendorId = $detectedBillToVendorId ?? $emailDetectedVendorId;
                if ($detectedVendorId && $detectedVendorId !== ($receipt_account->belongs_to_vendor_id ?? null)) {
                    Log::channel('nylas')->info('Detected belongs-to vendor override', [
                        'message_id' => $messageId,
                        'detected_vendor_id' => $detectedVendorId,
                        'detection_method' => $detectedBillToVendorId ? 'ocr_bill_to' : 'forwarding_email',
                        'from_email' => $fromEmail,
                        'default_belongs_to' => $receipt_account->belongs_to_vendor_id ?? null,
                        'receipt_id' => $receipt->id,
                    ]);
                }

                // When the detected belongs-to vendor differs from the company email owner,
                // OR when we matched a "sold to" client on the receipt, this is a material order
                // (e.g. vendor 1 forwarding a store receipt for a client).
                // Re-OCR with the material order analyzer to extract Status/ETA/Area fields.
                if (!$isMaterialOrder && ($effectiveBelongsToVendorId !== $companyEmail->vendor_id || $detectedBillToClientId)) {
                    $isMaterialOrder = true;
                    $materialOrderModel = config('services.azure_cu.analyzer_id_material_order');
                    Log::channel('nylas')->info('Upgrading to material order — belongs_to differs from company email vendor', [
                        'message_id' => $messageId,
                        'effective_belongs_to' => $effectiveBelongsToVendorId,
                        'company_email_vendor' => $companyEmail->vendor_id,
                        'detected_client_id' => $detectedBillToClientId,
                        'original_model' => $document_model,
                        'new_model' => $materialOrderModel,
                        'receipt_id' => $receipt->id,
                    ]);
                    $document_model = $materialOrderModel;
                    $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)
                        ->extractReceipt($ocr_path, 'material_order', null, 'email', $receipt, $document_model);
                    if (!isset($ocr_receipt_data['content']) || !is_string($ocr_receipt_data['content'])) {
                        $ocr_receipt_data['content'] = '';
                    }
                }
        
                // Missing receipt_account.. receipt and company email exist but this pairing does not.
                // If we already know who the expense belongs to (via email or OCR detection), we can
                // still create the expense — receipt_account is only needed as a fallback for that.
                // Only bail to the Add folder when we have no way to determine belongs_to_vendor_id.
                if (is_null($receipt_account) && is_null($effectiveBelongsToVendorId)) {
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
                            $companyEmail?->id
                        );
                    } else {
                        Log::channel('nylas')->warning('Missing Add folder configuration when receipt account lookup fails', [
                            'grant_id' => $grantId,
                            'company_email_id' => $companyEmail?->id,
                            'message_id' => $messageId,
                        ]);
                    }
                    continue;
                }

                if (is_null($receipt_account)) {
                    Log::channel('nylas')->info('No receipt_account pairing found — proceeding with detected belongs_to_vendor_id', [
                        'message_id' => $messageId,
                        'effective_belongs_to_vendor_id' => $effectiveBelongsToVendorId,
                        'receipt_vendor_id' => $receipt->vendor_id,
                    ]);
                }

                //01-26-2023 pass rest of receipt info to ocr_extract method
                $transactionDate = $ocr_receipt_data['fields']['transaction_date'] ?? null;
                if (empty($transactionDate)) {
                    Log::channel('nylas')->warning('OCR extract missing transaction_date field', [
                        'receipt_id' => $receipt->id,
                        'company_email_id' => $companyEmail->id,
                        'message_id' => $messageId,
                        'from_email' => $fromEmail,
                        'to_email' => $toEmail,
                        'subject' => $subject,
                    ]);
                }

                // use_email_date option forces the email date regardless of what OCR extracted
                $date = (!empty($receipt->options['use_email_date']) || empty($transactionDate))
                    ? $dateEmail
                    : $transactionDate;

                //8-18-23 we can remove this?!
                $total = $ocr_receipt_data['fields']['total'] ?? null;
                if ($total === null || $total === '') {
                    Log::channel('nylas')->warning('OCR extract missing total field', [
                        'receipt_id' => $receipt->id,
                        'company_email_id' => $companyEmail->id,
                        'message_id' => $messageId,
                        'from_email' => $fromEmail,
                        'to_email' => $toEmail,
                        'subject' => $subject,
                    ]);
                    $total = '0';
                }

                if (isset($receipt->options['refund'])) {
                    $amount = '-'.$total;
                } else {
                    $amount = $total;
                }

                // amount override via regex (useful when OCR picks the wrong field, e.g. "Amount Due" vs "Amount Paid")
                if (isset($receipt->options['amount_regex'])) {
                    $re = $receipt->options['amount_regex'];
                    $str = $ocr_receipt_data['content'];
                    preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);
                    if (!empty($matches)) {
                        $lastMatch = $matches[count($matches) - 1];
                        $matchedAmount = trim((string) ($lastMatch[1] ?? $lastMatch[0] ?? ''));
                        $matchedAmount = preg_replace('/[^0-9.]/', '', $matchedAmount);
                        if ($matchedAmount !== '') {
                            $amount = isset($receipt->options['refund'])
                                ? '-'.$matchedAmount
                                : $matchedAmount;
                        }
                    }
                }

                // receipt number / invoice — trust the CU API extraction
                $invoice = $ocr_receipt_data['fields']['invoice_number'] ?? null;
                if (is_string($invoice) && in_array(strtolower(trim($invoice)), ['null', 'n/a', 'na', 'none', ''], true)) {
                    $invoice = null;
                }

                // invoice override via subject regex (useful when OCR picks the wrong field,
                // e.g. account number instead of order number)
                if (!empty($receipt->options['invoice_from_subject_regex'])) {
                    if (preg_match($receipt->options['invoice_from_subject_regex'], $subject, $invoiceMatch)) {
                        $invoice = trim($invoiceMatch[1] ?? $invoiceMatch[0]);
                    }
                }

                // receipt po / purchase order — trust the CU API extraction
                if (!empty($receipt->options['no_po'])) {
                    $purchase_order = null;
                } else {
                    $purchase_order = $ocr_receipt_data['fields']['purchase_order'] ?? null;
                }

                $ocr_receipt_data['fields']['purchase_order'] = $purchase_order;

                // Handle "update_existing" receipts: find an existing expense with the same
                // invoice number and vendor, update its amount/date, and attach the new
                // receipt as an additional record (preserving the original).
                if (!empty($receipt->options['update_existing']) && !empty($invoice)) {
                    $existingExpense = Expense::where('vendor_id', $receipt->vendor_id)
                        ->where('invoice', trim((string) $invoice))
                        ->whereNull('deleted_at')
                        ->latest('id')
                        ->first();

                    if ($existingExpense) {
                        $oldAmount = $existingExpense->amount;

                        // For material order updates, only merge item statuses — don't overwrite amount/date
                        // Use the actual email date (not OCR transaction_date which is the original order date)
                        if ($isMaterialOrder) {
                            $this->mergeMaterialOrderUpdate($existingExpense, $ocr_receipt_data, $dateEmail, $subject);
                        } else {
                            // Update expense amount and date from the update email
                            $existingExpense->amount = $amount;
                            $existingExpense->date = $date;
                            $existingExpense->save();

                            // Attach as a new receipt record (keeps the original intact)
                            $this->saveExpenseReceipt($existingExpense->id, $ocr_receipt_data, $ocr_filename, !empty($receipt->options['html_to_pdf']) ? null : $message, true, $isMaterialOrder);
                        }

                        Log::channel('nylas')->info('Updated existing expense via update_existing receipt', [
                            'expense_id' => $existingExpense->id,
                            'receipt_id' => $receipt->id,
                            'invoice' => $invoice,
                            'old_amount' => $oldAmount,
                            'new_amount' => $amount,
                            'is_material_order' => $isMaterialOrder,
                        ]);

                        // Move to Saved folder
                        if (!empty($folderMap['Saved'])) {
                            $this->nylasService->moveEmailToFolder($messageId, $folderMap['Saved'], $grantId, $companyEmail->id);
                        }
                        continue;
                    }
                    // No existing expense found — fall through to normal creation flow
                }

                //FIND duplicates
                //confirm expense does not yet exist
                //1-18-2023 | 9/30/2023 NEED TO ACCOUNT FOR SAME VENDOR, AMOUNT, AND DATE being saved multiple of times
                //maybe by adding date_TIME to 'date'? or checking time in the expense_receipt_data json?

                Log::channel('nylas')->info('Starting duplicate check and expense creation', [
                    'message_id' => $messageId,
                    'receipt_id' => $receipt->id,
                    'amount' => $amount,
                    'invoice' => $invoice ?? '',
                    'date' => $date,
                    'belongs_to_vendor_id' => $effectiveBelongsToVendorId,
                    'vendor_id' => $receipt->vendor_id,
                    'is_material_order' => $isMaterialOrder,
                ]);

                // Match on invoice number + amount + date range to avoid false positives
                // (invoice numbers can repeat across different transactions at same vendor)
                $invoice = isset($invoice) ? trim((string) $invoice) : '';
                
                // Use ±5 day window for date matching to handle slight variations
                $duplicate_start_date = Carbon::parse($date)->subDays(5)->format('Y-m-d');
                $duplicate_end_date = Carbon::parse($date)->addDays(5)->format('Y-m-d');
                
                if ($invoice !== '') {
                    // When invoice exists, require invoice + amount + date range match
                    $duplicates = Expense::with('receipts')
                        ->where('belongs_to_vendor_id', $effectiveBelongsToVendorId)
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
                        ->where('belongs_to_vendor_id', $effectiveBelongsToVendorId)
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
                    $this->saveExpenseReceipt($duplicate_expense->id, $ocr_receipt_data, $ocr_filename, !empty($receipt->options['html_to_pdf']) ? null : $message, false, $isMaterialOrder);

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
                    // Material orders / supplements often have no totals to match on, but the
                    // invoice/order number on the PDF is the same as on a previously-saved
                    // receipt for that purchase. If we can match by invoice, MERGE the
                    // supplement's Manufacturer/MPN data into the existing receipt's items
                    // rather than attaching a duplicate receipt row.
                    //
                    // FALLBACK: when OCR fails to extract the invoice (common for supplement
                    // PDFs), score recent material-order expenses by item-description overlap
                    // and merge into the best-matching one above the threshold.
                    if ($isMaterialOrder) {
                        $supplementItems = $ocr_receipt_data['fields']['items'] ?? [];
                        $invoiceMatchExpense = null;

                        if ($invoice !== '') {
                            $invoiceMatchExpense = Expense::withoutGlobalScopes()
                                ->with('receipts')
                                ->whereNull('deleted_at')
                                ->where('invoice', $invoice)
                                ->orderBy('date')
                                ->first();
                        }

                        $matchReason = $invoiceMatchExpense ? 'invoice' : null;

                        if (!$invoiceMatchExpense && !empty($subject)) {
                            $invoiceMatchExpense = $this->findMaterialOrderBySubjectInvoice(
                                (string) $subject,
                                $effectiveBelongsToVendorId,
                                $date
                            );
                            if ($invoiceMatchExpense) {
                                $matchReason = 'subject_invoice';
                            }
                        }

                        if (!$invoiceMatchExpense && !empty($supplementItems)) {
                            $invoiceMatchExpense = $this->findMaterialOrderByItemSignature(
                                $supplementItems,
                                $effectiveBelongsToVendorId,
                                $date
                            );
                            if ($invoiceMatchExpense) {
                                $matchReason = 'item_signature';
                            }
                        }

                        if ($invoiceMatchExpense) {
                            $existingReceipt = $invoiceMatchExpense->receipts->sortByDesc('id')->first();

                            $changedCount = 0;
                            $changedIndexes = [];
                            $appendedCount = 0;
                            $appendedIndexes = [];
                            if ($existingReceipt && !empty($supplementItems)) {
                                $existingPayload = $existingReceipt->receipt_items ?? [];
                                $existingItems = $existingPayload['items'] ?? [];

                                if (!empty($existingItems)) {
                                    $enricher = app(ReceiptItemEnricher::class);
                                    $updates = $enricher->planMerge($existingItems, $supplementItems);
                                    $existingItems = $enricher->applyUpdates($existingItems, $updates);
                                    $changedCount = collect($updates)->where('changed', true)->count();
                                    $changedIndexes = collect($updates)
                                        ->where('changed', true)
                                        ->pluck('index')
                                        ->all();

                                    // Newly-supplied Manufacturer/MPN must drive a re-scrape:
                                    // clear stale scraped fields and cache rows for changed
                                    // items so ScrapeReceiptItemImagesV2 re-resolves them.
                                    foreach ($changedIndexes as $changedIdx) {
                                        $existingItems[$changedIdx]['product_url'] = null;
                                        $existingItems[$changedIdx]['image_url']   = null;
                                    }

                                    // Append supplement items that didn't match anything
                                    // existing (e.g. mirrors / accessories added by the
                                    // designer that weren't on the original PO). These
                                    // become brand-new rows on the receipt and need scraping.
                                    $appendedItems = $enricher->unmatchedSupplementItems($existingItems, $supplementItems, $updates);
                                    $appendedCount = count($appendedItems);
                                    if ($appendedCount > 0) {
                                        $nextIdx = count($existingItems);
                                        foreach ($appendedItems as $newItem) {
                                            $existingItems[$nextIdx] = $newItem;
                                            $appendedIndexes[] = $nextIdx;
                                            $nextIdx++;
                                        }
                                    }

                                    $existingPayload['items'] = $existingItems;
                                    $existingReceipt->receipt_items = $existingPayload;
                                    $existingReceipt->save();

                                    if (!empty($changedIndexes) || $appendedCount > 0) {
                                        \App\Models\ReceiptLineItemDesc::where('expense_receipt_id', $existingReceipt->id)
                                            ->whereIn('item_index', array_merge($changedIndexes, $appendedIndexes))
                                            ->delete();

                                        \App\Jobs\ScrapeReceiptItemImagesV2::dispatch($existingReceipt);
                                    }
                                }
                            }

                            Log::channel('nylas')->info('Material order supplement merged into existing expense', [
                                'message_id' => $messageId,
                                'match_reason' => $matchReason,
                                'invoice' => $invoice,
                                'existing_expense_id' => $invoiceMatchExpense->id,
                                'existing_receipt_id' => $existingReceipt?->id,
                                'supplement_item_count' => count($supplementItems),
                                'items_changed' => $changedCount,
                                'items_appended' => $appendedCount,
                            ]);

                            // Move email to Duplicate folder — the supplement has been absorbed
                            // into the existing receipt; we don't need it as a standalone receipt.
                            if (!empty($folderMap['Duplicate'])) {
                                $this->nylasService->moveEmailToFolder($messageId, $folderMap['Duplicate'], $grantId, $companyEmail->id);
                            }

                            continue;
                        }
                    }

                    // Before creating a new expense, check if this receipt is a duplicate across all expenses
                    $existingExpenseWithDuplicate = $this->findExpenseWithDuplicateReceipt(
                        $effectiveBelongsToVendorId,
                        $receipt->vendor_id,
                        $amount,
                        $date,
                        $ocr_receipt_data['content'],
                        $ocr_receipt_data['fields']
                    );

                    if ($existingExpenseWithDuplicate) {
                        // Found a duplicate - attach receipt to existing expense and move to Duplicate folder
                        $this->saveExpenseReceipt($existingExpenseWithDuplicate->id, $ocr_receipt_data, $ocr_filename, !empty($receipt->options['html_to_pdf']) ? null : $message, false, $isMaterialOrder);
                        
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
                        // Check if this receipt shares a DEPOSIT NO# with an existing expense's receipt
                        // (Home Depot sends separate deposit + final receipts for the same purchase)
                        $depositMatchExpense = $this->findExpenseBySharedDepositNumber(
                            $effectiveBelongsToVendorId,
                            $receipt->vendor_id,
                            $date,
                            $ocr_receipt_data['content']
                        );

                        if ($depositMatchExpense) {
                            // Same purchase — attach the new receipt and update amount to the final total
                            $this->saveExpenseReceipt($depositMatchExpense->id, $ocr_receipt_data, $ocr_filename, !empty($receipt->options['html_to_pdf']) ? null : $message, false, $isMaterialOrder);

                            $depositMatchExpense->amount = $amount;
                            $depositMatchExpense->date = $date;
                            if ($invoice !== '') {
                                $depositMatchExpense->invoice = $invoice;
                            }
                            $depositMatchExpense->save();

                            Log::channel('nylas')->info('Merged receipt into existing expense via shared DEPOSIT NO#', [
                                'expense_id' => $depositMatchExpense->id,
                                'old_amount' => $depositMatchExpense->getOriginal('amount'),
                                'new_amount' => $amount,
                                'receipt_id' => $receipt->id,
                            ]);

                            if (!empty($folderMap['Saved'])) {
                                $this->nylasService->moveEmailToFolder($messageId, $folderMap['Saved'], $grantId, $companyEmail->id);
                            }
                        } else {
                        // Check if there are partial expenses that sum to this receipt total
                        $partialExpenses = $this->findPartialExpensesToConsolidate(
                            $effectiveBelongsToVendorId,
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

                            // Find matching bulk match rule for this amount
                            $bulkMatch = TransactionBulkMatch::findMatchForAmount($receipt->vendor_id, (float) $amount);

                            // Create new expense with receipt
                            $expense = new Expense;
                            $expense->amount = $amount;
                            $expense->reimbursment = null;
                            $expense->project_id = $detectedProjectId;
                            $expense->distribution_id = $bulkMatch?->distribution_id;
                            $expense->created_by_user_id = 0; //automated
                            $expense->date = $date;
                            $expense->invoice = $invoice;
                            $expense->vendor_id = $receipt->vendor_id;
                            $expense->note = null;
                            $expense->belongs_to_vendor_id = $effectiveBelongsToVendorId;
                            $expense->belongs_to_client_id = $detectedBillToClientId;
                            $expense->save();

                            // Apply splits if the match has them
                            $bulkMatch?->applySplits($expense, (float) $amount);

                            //ATTACHMENTS
                            $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename, !empty($receipt->options['html_to_pdf']) ? null : $message, false, $isMaterialOrder);

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
                            // Find matching bulk match rule for this amount
                            $bulkMatch = TransactionBulkMatch::findMatchForAmount($receipt->vendor_id, (float) $amount);

                            // No duplicate and no partial expenses found - create new expense
                            $expense = new Expense;
                            $expense->amount = $amount;
                            $expense->reimbursment = null;
                            $expense->project_id = $detectedProjectId;
                            $expense->distribution_id = $bulkMatch?->distribution_id;
                            $expense->created_by_user_id = 0; //automated
                            $expense->date = $date;
                            $expense->invoice = $invoice;
                            $expense->vendor_id = $receipt->vendor_id; //Vendor_id of vendor being Queued
                            $expense->note = null;
                            $expense->belongs_to_vendor_id = $effectiveBelongsToVendorId;
                            $expense->belongs_to_client_id = $detectedBillToClientId;
                            $expense->save();

                            // Apply splits if the match has them
                            $bulkMatch?->applySplits($expense, (float) $amount);

                            //ATTACHMENTS
                            $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename, !empty($receipt->options['html_to_pdf']) ? null : $message, false, $isMaterialOrder);

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

                            Log::channel('nylas')->info('Created new expense from receipt email', [
                                'expense_id' => $expense->id,
                                'message_id' => $messageId,
                                'receipt_id' => $receipt->id,
                                'amount' => $amount,
                                'invoice' => $invoice,
                                'vendor_id' => $receipt->vendor_id,
                                'belongs_to_vendor_id' => $effectiveBelongsToVendorId,
                                'is_material_order' => $isMaterialOrder,
                            ]);
                        }
                    }
                    }
                }
            } else{
                continue;
            }
            } catch (\Throwable $e) {
                Log::channel('nylas')->error('Failed to process receipt message', ApiErrorFormatter::format($e, [
                    'grant_id' => $grantId,
                    'message_id' => $messageId,
                ]));

                // Move failed message to error folder so it doesn't retry every cycle
                try {
                    $errorFolder = $folderMap['Error']
                        ?? config('nylas.hive_receipts_error_folder_id');
                    if ($errorFolder && $messageId) {
                        $this->nylasService->moveEmailToFolder(
                            $messageId,
                            $errorFolder,
                            $grantId,
                            $companyEmail->id ?? null
                        );
                    }
                } catch (\Throwable $moveEx) {
                    Log::channel('nylas')->warning('Failed to move errored receipt message to error folder', [
                        'grant_id' => $grantId,
                        'message_id' => $messageId,
                        'move_error' => $moveEx->getMessage(),
                    ]);
                }

                continue;
            }
        }

        // Queue an auto-match sweep so freshly-created receipt expenses get matched
        // to their PO/distribution/project without waiting for the 10-minute scheduler.
        // ShouldBeUnique guards against duplicate dispatches when batches finish back-to-back.
        \App\Jobs\AutoMatchNoProjectExpenses::dispatch();
    }

    public function fetchAutoReceipts()
    {
        ignore_user_abort(true);
        set_time_limit(0);

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
                    $lastExpenseForMessage = null;

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

                        // Process the attachment using ReceiptController.
                        $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->extractReceipt($ocr_path, $doc_type, null, true);

                        if (isset($ocr_receipt_data['error']) && $ocr_receipt_data['error'] === true)
                        {
                            $partial = is_array($ocr_receipt_data['partial'] ?? null) ? $ocr_receipt_data['partial'] : [];
                            $partialInvoice = trim((string) ($partial['invoice_number'] ?? ''));
                            $isContinuationPage = (bool) ($partial['continued_from_previous'] ?? false)
                                && (int) ($partial['page_number'] ?? 0) > 1;

                            $continuationExpense = null;

                            if ($partialInvoice !== '') {
                                $continuationExpense = Expense::withoutGlobalScopes()
                                    ->where('belongs_to_vendor_id', $email_vendor->id)
                                    ->where('invoice', $partialInvoice)
                                    ->latest('id')
                                    ->first();
                            }

                            if (! $continuationExpense && $isContinuationPage && $lastExpenseForMessage instanceof Expense) {
                                $continuationExpense = $lastExpenseForMessage;
                            }

                            if ($continuationExpense) {
                                if (($partial['invoice_number'] ?? null) === null || trim((string) $partial['invoice_number']) === '') {
                                    $partial['invoice_number'] = $continuationExpense->invoice;
                                }

                                $this->saveExpenseReceipt(
                                    $continuationExpense->id,
                                    [
                                        'fields' => $partial,
                                        'content' => (string) ($ocr_receipt_data['content'] ?? ''),
                                    ],
                                    $ocr_filename,
                                    null,
                                    true,
                                );

                                if ($attachment_key === array_key_last($message['attachments'])) {
                                    $this->nylasService->moveOriginalMessageToHiveFolder($grantId, $messageId, $company_email->id);
                                }

                                continue;
                            }

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

                        $resolvedTransactionDate = $this->resolveAutoReceiptTransactionDate(
                            $ocr_receipt_data['fields']['transaction_date'] ?? null,
                            (string) ($ocr_receipt_data['content'] ?? '')
                        );

                        if (! $resolvedTransactionDate) {
                            Log::channel('receipt_processing')->warning('AutoReceipts: invalid transaction_date; skipping receipt', [
                                'company_email_id' => $company_email->id,
                                'attachment_filename' => $attachment['filename'] ?? 'unknown',
                                'raw_transaction_date' => $ocr_receipt_data['fields']['transaction_date'] ?? null,
                                'timestamp' => now()->toISOString(),
                            ]);

                            Storage::disk('files')->delete($ocr_path);

                            if ($attachment_key === array_key_last($message['attachments'])) {
                                $this->nylasService->moveOriginalMessageToHiveFolder($grantId, $messageId, $company_email->id);
                            }

                            continue;
                        }

                        // Normalize stored value so downstream logic uses the corrected date.
                        $ocr_receipt_data['fields']['transaction_date'] = $resolvedTransactionDate->toDateString();

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

                            // Epson auto-receipt scans almost always carry handwritten notes
                            // that the OCR's `content` may not capture (low style confidence,
                            // cursive too messy, etc.). Two visually identical scans can therefore
                            // produce identical `content` even though the handwriting differs.
                            // Always attach the new scan as an additional receipt — the user can
                            // delete extras manually if truly redundant.
                            if (isset($expense_duplicate->receipts()->latest()->first()->receipt_html)) {
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
                            // The inner duplicate-content check (HTML hash + line-items signature
                            // including handwritten_notes) will drop byte-identical re-scans but
                            // allow through scans whose handwritten notes differ.
                            // (saveExpenseReceipt moves the temp file into receipts/ and persists the receipt record)
                            if (isset($expense) && $expense instanceof Expense) {
                                $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename, null, false);
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

                        if (isset($expense) && $expense instanceof Expense) {
                            $lastExpenseForMessage = $expense;
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

    /**
     * Merge status/ETA from a new material-order OCR into the existing receipt items.
     * Only items that were previously pending (BO, open, partial) are updated.
     */
    protected function mergeMaterialOrderUpdate(Expense $expense, array $ocrData, string $emailDate, string $emailSubject = ''): void
    {
        $existingReceipt = $expense->orderedReceipts->first(fn ($r) => $r->is_material_order)
            ?? ExpenseReceipts::where('expense_id', $expense->id)->where('is_material_order', true)->first();

        if (! $existingReceipt) {
            return;
        }

        $newFields = $ocrData['fields'] ?? [];
        $oldItems = $existingReceipt->receipt_items;

        // Build a lookup of new items by ProductCode
        $newByCode = [];
        foreach (($newFields['items'] ?? []) as $newItem) {
            $code = $newItem['ProductCode'] ?? null;
            if ($code) {
                $newByCode[$code][] = $newItem;
            }
        }

        // Detect "order complete" subject as a fallback when OCR doesn't extract items
        $isOrderComplete = (bool) preg_match('/order\s+is\s+complete|order\s+complete/i', $emailSubject);

        $existingItems = $oldItems['items'] ?? [];
        $updated = false;
        $itemChanges = [];

        foreach ($existingItems as $idx => &$existingItem) {
            $oldNormalized = MaterialOrderStatus::normalize($existingItem['Status'] ?? null);

            if (! MaterialOrderStatus::isPending($oldNormalized)) {
                continue;
            }

            $code = $existingItem['ProductCode'] ?? null;
            $oldStatus = $existingItem['Status'] ?? null;
            $oldEta = $existingItem['ETA'] ?? null;

            // Try to match by ProductCode from the OCR'd update document
            if ($code && isset($newByCode[$code])) {
                $match = array_shift($newByCode[$code]);
                $newStatus = $match['Status'] ?? null;

                if ($newStatus !== null) {
                    $newNormalized = MaterialOrderStatus::normalize($newStatus);
                    // Store a clean display value: resolved statuses become "Available"
                    $existingItem['Status'] = MaterialOrderStatus::isResolved($newNormalized)
                        ? 'Available'
                        : $newStatus;
                    $updated = true;
                }

                $newEta = $match['ETA'] ?? null;
                $newNormalized = MaterialOrderStatus::normalize($existingItem['Status'] ?? null);

                if (! empty($newEta)) {
                    $existingItem['ETA'] = $newEta;
                    $updated = true;
                } elseif (MaterialOrderStatus::isResolved($newNormalized)) {
                    $existingItem['ETA'] = $emailDate;
                    $updated = true;
                }
            } elseif ($isOrderComplete) {
                // Fallback: subject says "Order is Complete" — mark all pending items as available
                $existingItem['Status'] = 'Available';
                $existingItem['ETA'] = $emailDate;
                $updated = true;
            }

            // Track per-item changes
            if ($existingItem['Status'] !== $oldStatus || $existingItem['ETA'] !== $oldEta) {
                $itemChanges[] = [
                    'item_index' => $idx,
                    'product_code' => $code,
                    'description' => $existingItem['Description'] ?? null,
                    'old_status' => $oldStatus,
                    'new_status' => $existingItem['Status'],
                    'old_eta' => $oldEta,
                    'new_eta' => $existingItem['ETA'] ?? null,
                ];
            }
        }
        unset($existingItem);

        if ($updated) {
            $oldItems['items'] = $existingItems;
            $existingReceipt->receipt_items = $oldItems;
            $existingReceipt->save();

            // Log per-item changes to activity_log
            if (! empty($itemChanges)) {
                activity('materials')
                    ->performedOn($existingReceipt)
                    ->withProperties([
                        'expense_id' => $expense->id,
                        'invoice' => $expense->invoice,
                        'email_subject' => $emailSubject,
                        'items' => $itemChanges,
                    ])
                    ->event('material_order_updated')
                    ->log('Material order items updated');
            }

            Log::channel('nylas')->info('Merged material order status/ETA update', [
                'expense_id' => $expense->id,
                'receipt_id' => $existingReceipt->id,
                'items_count' => count($existingItems),
                'changes' => $itemChanges,
            ]);
        }
    }

    public function saveExpenseReceipt($expense_id, $ocr_receipt_data, $ocr_filename, $message = NULL, bool $skipDuplicateCheck = false, bool $isMaterialOrder = false)
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

                // Filter to only document attachments (PDF, JPG, PNG) worth processing.
                // Pre-download and check each attachment so we know if any are real documents
                // before deleting the already-created HTML-to-PDF receipt.
                $documentAttachments = [];
                if (!empty($nonInlineAttachments)) {
                    foreach ($nonInlineAttachments as $attachmentIndex => $attachment) {
                        $attachmentContent = $this->nylasService->downloadAttachment(
                            $attachment['id'],
                            $message['grant_id'],
                            $message['id']
                        );

                        $detectedType = $this->detectFileType($attachmentContent, $attachment['filename'] ?? null, $attachment['content_type'] ?? null);

                        // Skip unsupported file types (MIME artifacts like ATT00001, text/plain parts, etc.)
                        if (!in_array($detectedType, ['pdf', 'jpg', 'jpeg', 'png'])) {
                            Log::channel('nylas')->info('Skipping non-document attachment in saveExpenseReceipt', [
                                'expense_id' => $expense_id,
                                'attachment_filename' => $attachment['filename'] ?? 'unknown',
                                'detected_type' => $detectedType,
                                'content_type' => $attachment['content_type'] ?? 'unknown',
                                'size' => strlen($attachmentContent),
                            ]);
                            continue;
                        }

                        // Skip tiny images (likely logos/icons, not receipt documents)
                        if (in_array($detectedType, ['jpg', 'jpeg', 'png']) && strlen($attachmentContent) < 15000) {
                            Log::channel('nylas')->info('Skipping small image attachment (likely logo/icon)', [
                                'expense_id' => $expense_id,
                                'attachment_filename' => $attachment['filename'] ?? 'unknown',
                                'detected_type' => $detectedType,
                                'size' => strlen($attachmentContent),
                            ]);
                            continue;
                        }

                        $documentAttachments[] = [
                            'index' => $attachmentIndex,
                            'attachment' => $attachment,
                            'content' => $attachmentContent,
                            'doc_type' => $detectedType,
                        ];
                    }
                }

                // Only process document attachments if we found real ones.
                // Otherwise fall through to the single-file path which uses the
                // already-created HTML-to-PDF receipt (e.g. Erie Insurance).
                if (!empty($documentAttachments)) {
                    $processedFiles = [];

                    foreach ($documentAttachments as $docAttachment) {
                        $attachmentIndex = $docAttachment['index'];
                        $doc_type = $docAttachment['doc_type'];
                        $attachmentContent = $docAttachment['content'];

                        // Generate unique filename with correct extension
                        $currentFilename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '-' . $attachmentIndex . '.' . $doc_type;
                        $ocr_path = '_temp_ocr/' . $currentFilename;

                        Storage::disk('files')->put($ocr_path, $attachmentContent);

                        // OCR the file via unified extractReceipt(). For material orders we MUST
                        // pass the material_order analyzer/docType so multi-page product portfolio
                        // PDFs (e.g. Studio 41 / Kohler Signature) extract items + content. Without
                        // this the generic receipt analyzer fails and the supplement is dropped,
                        // which previously caused orphan expenses to be created with no items.
                        //
                        // OPTIMIZATION: For the FIRST attachment, the caller already OCR'd this exact
                        // file (it's the same temp PDF). Reuse that result instead of running Azure CU
                        // a second time — saves 10-40s per multi-page material order PDF.
                        $isFirstAttachment = $attachmentIndex === array_key_first($nonInlineAttachments);
                        if ($isFirstAttachment && !empty($ocr_receipt_data['content'])) {
                            $current_ocr_data = $ocr_receipt_data;
                        } else {
                            $attachmentDocType = $isMaterialOrder ? 'material_order' : $doc_type;
                            $attachmentAnalyzerId = $isMaterialOrder
                                ? config('services.azure_cu.analyzer_id_material_order')
                                : null;
                            $current_ocr_data = app(\App\Http\Controllers\ReceiptController::class)
                                ->extractReceipt($ocr_path, $attachmentDocType, null, 'email', null, $attachmentAnalyzerId);
                        }

                        // If OCR extraction failed (e.g. Azure couldn't parse the file), skip this attachment
                        if (isset($current_ocr_data['error']) && $current_ocr_data['error'] === true) {
                            Log::channel('nylas')->warning('OCR extraction failed for attachment, skipping', [
                                'expense_id' => $expense_id,
                                'attachment_filename' => $docAttachment['attachment']['filename'] ?? 'unknown',
                                'doc_type' => $doc_type,
                            ]);
                            Storage::disk('files')->delete($ocr_path);
                            continue;
                        }

                        // Save this attachment as an expense receipt
                        $targetFilename = $expense_id . '-' . $currentFilename;
                        $destinationPath = 'receipts/' . $targetFilename;

                        // Determine receipt content and items for duplicate checking
                        $receipt_html = ($attachmentIndex === array_key_first($nonInlineAttachments) && isset($ocr_receipt_data['content']))
                            ? $ocr_receipt_data['content']
                            : ($current_ocr_data['content'] ?? '');
                        $receipt_items = ($attachmentIndex === array_key_first($nonInlineAttachments) && isset($ocr_receipt_data['content']))
                            ? ($ocr_receipt_data['fields'] ?? $current_ocr_data['fields'] ?? [])
                            : ($current_ocr_data['fields'] ?? []);

                        // Check for duplicate receipts based on content and invoice number
                        $isDuplicate = $skipDuplicateCheck ? false : $this->isDuplicateReceipt($expense_id, $receipt_html, $receipt_items);

                        if ($isDuplicate) {
                            Storage::disk('files')->delete($ocr_path);
                            continue;
                        }

                        // Create receipt record in database
                        $expense_receipt = new ExpenseReceipts;
                        $expense_receipt->expense_id = $expense_id;
                        $expense_receipt->receipt_filename = $targetFilename;
                        $expense_receipt->receipt_html = $receipt_html;
                        $expense_receipt->receipt_items = $receipt_items;
                        $expense_receipt->is_material_order = $isMaterialOrder;

                        $expense_receipt->save();

                        if ($isMaterialOrder && !empty($receipt_items['items'])) {
                            \App\Jobs\ScrapeReceiptItemImagesV2::dispatch($expense_receipt);
                        }

                        // Move the file to permanent storage
                        if (Storage::disk('files')->move($ocr_path, $destinationPath)) {
                            // Success case
                        } else {
                            if (Storage::disk('files')->copy($ocr_path, $destinationPath)) {
                                Storage::disk('files')->delete($ocr_path);
                            }
                        }

                        $processedFiles[] = $targetFilename;
                    }

                    // If at least one attachment was successfully processed, clean up
                    // the original Browsershot PDF and return. Otherwise fall through
                    // to the single-file path so the HTML-to-PDF receipt is used.
                    if (!empty($processedFiles)) {
                        if ($ocr_filename) {
                            $sourcePath = '_temp_ocr/' . $ocr_filename;
                            Storage::disk('files')->delete($sourcePath);
                        }

                        return $processedFiles;
                    }

                    Log::channel('nylas')->info('All document attachments failed OCR, falling back to HTML-to-PDF receipt', [
                        'expense_id' => $expense_id,
                        'attempted_attachments' => count($documentAttachments),
                    ]);
                }
            }
        }

        // Original functionality for handling single files (for backward compatibility)
        $filename = $expense_id . '-' . $ocr_filename;
        $sourcePath = '_temp_ocr/' . $ocr_filename;
        $destinationPath = 'receipts/' . $filename;

        $receiptContent = $ocr_receipt_data['content'] ?? '';
        $receiptFields = $ocr_receipt_data['fields'] ?? [];

        // Check for duplicate receipts based on content and invoice number
        $isDuplicate = $skipDuplicateCheck ? false : $this->isDuplicateReceipt($expense_id, $receiptContent, $receiptFields);
        
        if ($isDuplicate) {
            // Skip saving this duplicate receipt and clean up temp file
            Storage::disk('files')->delete($sourcePath);
            return [];
        }

        // Save expense receipt data to the database
        $expense_receipt = new ExpenseReceipts;
        $expense_receipt->expense_id = $expense_id;
        $expense_receipt->receipt_filename = $filename;
        $expense_receipt->receipt_html = $receiptContent;
        $expense_receipt->receipt_items = $receiptFields;
        $expense_receipt->is_material_order = $isMaterialOrder;
        $expense_receipt->save();

        if ($isMaterialOrder && !empty($receiptFields['items'])) {
            \App\Jobs\ScrapeReceiptItemImagesV2::dispatch($expense_receipt);
        }

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
                    
                    // Find common words that appear in aliases but NOT in the vendor name - these are likely filler/generic
                    // Words that ARE part of the vendor's business_name are always significant for that vendor
                    $aliasCounts = array_count_values($aliasWords);
                    $vendorNameWords = explode(' ', $vendorNorm);
                    
                    foreach ($aliasCounts as $word => $count) {
                        if (!in_array($word, $vendorNameWords)) {
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
     * Scan an email subject for any invoice/order token belonging to a recent
     * material-order Expense and return the match. Supplement emails almost
     * always reference the original order in the subject (e.g.
     * "Fw: S2431488 Presentation - Egger" → invoice S2431488), even when the
     * attachment OCR fails to surface the invoice number.
     *
     * Strategy:
     *   1. Pull recent material-order expenses (vendor-scoped first, then
     *      cross-vendor as fallback) within $lookbackDays.
     *   2. For each candidate, normalise its `invoice` and check whether the
     *      subject (also normalised) contains it as a token.
     *   3. Prefer longer (more specific) invoice tokens to avoid false hits
     *      on short/numeric IDs that happen to appear in unrelated subjects.
     */
    protected function findMaterialOrderBySubjectInvoice(
        string $subject,
        ?int $belongsToVendorId,
        ?string $supplementDate,
        int $lookbackDays = 180,
        int $minInvoiceLen = 5
    ): ?Expense {
        $subjectNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $subject));
        if ($subjectNorm === '') {
            return null;
        }

        $cutoffDate = $supplementDate
            ? Carbon::parse($supplementDate)->copy()->subDays($lookbackDays)->toDateString()
            : Carbon::now()->subDays($lookbackDays)->toDateString();

        $query = Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereHas('receipts', fn ($q) => $q->where('is_material_order', true))
            ->whereNotNull('invoice')
            ->where('invoice', '!=', '')
            ->where('date', '>=', $cutoffDate)
            ->orderByDesc('date');

        if ($belongsToVendorId) {
            $vendorMatches = (clone $query)
                ->where('belongs_to_vendor_id', $belongsToVendorId)
                ->limit(200)
                ->get();
            $match = $this->matchExpenseByInvoiceInSubject($vendorMatches, $subjectNorm, $minInvoiceLen);
            if ($match) {
                return $match;
            }
        }

        $allMatches = $query->limit(500)->get();
        return $this->matchExpenseByInvoiceInSubject($allMatches, $subjectNorm, $minInvoiceLen);
    }

    /**
     * Pick the best Expense whose normalised invoice appears as a substring
     * of the normalised subject. Ties are broken by longest invoice (most
     * specific) then most recent date.
     *
     * @param \Illuminate\Support\Collection<int, Expense> $candidates
     */
    private function matchExpenseByInvoiceInSubject(
        \Illuminate\Support\Collection $candidates,
        string $subjectNorm,
        int $minInvoiceLen
    ): ?Expense {
        $best = null;
        $bestLen = 0;

        foreach ($candidates as $candidate) {
            $invNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $candidate->invoice));
            if (strlen($invNorm) < $minInvoiceLen) {
                continue;
            }
            if (!str_contains($subjectNorm, $invNorm)) {
                continue;
            }
            $len = strlen($invNorm);
            if ($len > $bestLen) {
                $best = $candidate;
                $bestLen = $len;
            }
        }

        return $best;
    }

    /**
     * Find an existing material-order Expense whose latest receipt's items strongly
     * overlap (by description, ≥60%) with the supplied supplement items. Used to
     * merge a supplement PDF into an existing receipt when the OCR failed to
     * extract the invoice number.
     *
     * @param array<int, array<string, mixed>> $supplementItems
     */
    protected function findMaterialOrderByItemSignature(
        array $supplementItems,
        ?int $belongsToVendorId,
        ?string $supplementDate,
        int $lookbackDays = 90,
        float $minMatchRatio = 0.6
    ): ?Expense {
        if (empty($supplementItems) || !$belongsToVendorId) {
            return null;
        }

        $supplementSig = $this->buildItemsSignature(['items' => $supplementItems]);
        $supplementDescs = $supplementSig['descriptions'] ?? [];
        $supplementCodes = $supplementSig['codes'] ?? [];
        $totalSupplement = max(count($supplementDescs), 1);

        $anchor = $supplementDate ? Carbon::parse($supplementDate) : Carbon::now();
        $start = $anchor->copy()->subDays($lookbackDays)->format('Y-m-d');
        $end = $anchor->copy()->addDays($lookbackDays)->format('Y-m-d');

        $candidates = Expense::withoutGlobalScopes()
            ->with(['receipts' => fn ($q) => $q->orderByDesc('id')])
            ->whereNull('deleted_at')
            ->where('belongs_to_vendor_id', $belongsToVendorId)
            ->whereBetween('date', [$start, $end])
            ->whereHas('receipts', fn ($q) => $q->where('is_material_order', true))
            ->orderByDesc('date')
            ->limit(200)
            ->get();

        $bestExpense = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $receipt = $candidate->receipts->first();
            if (!$receipt) {
                continue;
            }
            $storedFields = json_decode(json_encode($receipt->receipt_items), true) ?? [];
            $storedSig = $this->buildItemsSignature($storedFields);
            $storedDescs = $storedSig['descriptions'] ?? [];
            $storedCodes = $storedSig['codes'] ?? [];

            // Code overlap is the strongest signal when present.
            $matched = count(array_intersect($supplementCodes, $storedCodes));

            // Description fuzzy matching for items lacking a code on either side.
            $usedStored = [];
            foreach ($supplementDescs as $descA) {
                foreach ($storedDescs as $j => $descB) {
                    if (isset($usedStored[$j])) {
                        continue;
                    }
                    if ($descA === $descB) {
                        $matched++;
                        $usedStored[$j] = true;
                        break;
                    }
                    similar_text($descA, $descB, $pct);
                    if ($pct >= 80) {
                        $matched++;
                        $usedStored[$j] = true;
                        break;
                    }
                }
            }

            $score = $matched / $totalSupplement;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestExpense = $candidate;
            }
        }

        return $bestScore >= $minMatchRatio ? $bestExpense : null;
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
     * Check if a receipt is a duplicate for an expense.
     *
     * A duplicate is defined as having identical receipt HTML and/or identical receipt line items
     * (plus stable fields like total/date when present), after normalization.
     */
    protected function isDuplicateReceipt(int $expense_id, string $receipt_html, array $receipt_items): bool
    {
        return ExpenseReceipts::isDuplicateForExpense($expense_id, $receipt_html, $receipt_items);
    }

    protected function resolveAutoReceiptTransactionDate(mixed $rawTransactionDate, string $receiptContent): ?Carbon
    {
        $now = Carbon::now();

        $parse = static function (mixed $value): ?Carbon {
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value);
            }

            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);
            if ($value === '') {
                return null;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        };

        $parsed = $parse($rawTransactionDate);

        // Guard against OCR year/day drift into the future (e.g. 2026-12-25).
        // Allow a small buffer because processing can happen after midnight.
        if ($parsed && $parsed->greaterThan($now->copy()->addDays(2))) {
            $parsed = null;
        }

        // If OCR date is missing/invalid, try to extract the first date-with-time from the receipt body.
        if (! $parsed && $receiptContent !== '') {
            $patterns = [
                // 12/12/25 09:49 AM
                '/\b(\d{1,2}\/\d{1,2}\/\d{2,4})\s+\d{1,2}:\d{2}(?:\s*[AP]M)?\b/i',
                // 12/12/25:09:49 AM
                '/\b(\d{1,2}\/\d{1,2}\/\d{2,4})\s*:\s*\d{1,2}:\d{2}(?:\s*[AP]M)?\b/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $receiptContent, $m)) {
                    $date = trim((string) ($m[1] ?? ''));
                    if ($date !== '') {
                        $candidate = null;

                        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $date, $parts)) {
                            $month = (int) ($parts[1] ?? 0);
                            $day = (int) ($parts[2] ?? 0);
                            $yearRaw = (string) ($parts[3] ?? '');

                            if (strlen($yearRaw) === 2) {
                                // Receipts in this app are contemporary; interpret 2-digit years as 20xx.
                                $yearRaw = '20'.$yearRaw;
                            }

                            $year = (int) $yearRaw;

                            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && $year >= 2000 && $year <= 2100) {
                                $normalized = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                try {
                                    $candidate = Carbon::createFromFormat('Y-m-d', $normalized);
                                } catch (\Throwable) {
                                    $candidate = null;
                                }
                            }
                        }

                        if ($candidate) {
                            if ($candidate->greaterThan($now->copy()->addDays(2))) {
                                continue;
                            }

                            $parsed = $candidate;
                            break;
                        }
                    }
                }
            }
        }

        // Final fallback: if still missing, use today's date to avoid writing a future date.
        return $parsed ?: $now;
    }

    /**
     * Build receipt criteria from company email receipts
     */
    protected function buildReceiptCriteria($receipts): array
    {
        $receiptCriteria = []; // address => [subjects]
        
        foreach ($receipts as $receipt) {
            $address = strtolower(trim((string) $receipt->from_address));
            $subjects = $receipt->from_subject ?? [];
            if (! is_array($subjects)) {
                $subjects = [(string) $subjects];
            }

            // Only process valid addresses (domain patterns start with @, emails contain @)
            if (str_starts_with($address, '@') || str_contains($address, '@')) {
                if (!isset($receiptCriteria[$address])) {
                    $receiptCriteria[$address] = [];
                }
                foreach ($subjects as $subject) {
                    $subject = strtolower(trim((string) $subject));
                    if ($subject !== '' && !in_array($subject, $receiptCriteria[$address])) {
                        $receiptCriteria[$address][] = $subject;
                    }
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

        // Forward each matching message (only if it would match a receipt in central processing)
        foreach ($matchingMessages as $message) {
            // Validate that this message would actually match a receipt before forwarding
            // This prevents forwarding emails that pass the loose criteria but fail findMatchingReceipt
            if (!$this->validateMessageWouldMatchReceipt($grantId, $message)) {
                continue;
            }

            $this->nylasService->sendForwardCopy(
                $grantId,
                $message['id'],
                true,
                $companyEmail->id
            );
        }
    }

    /**
     * Validate that a message would match a receipt when processed in the central mailbox.
     * This prevents forwarding emails that pass the loose filtering but would fail findMatchingReceipt.
     * 
     * Strategy:
     * 1. First try to match using the direct sender email
     * 2. If no match, fetch the message body and try to extract the original sender
     *    (handles forwarded emails regardless of subject prefix)
     */
    protected function validateMessageWouldMatchReceipt(string $grantId, array $message): bool
    {
        $fromEmail = strtolower($message['from'][0]['email'] ?? '');
        $subject = $message['subject'] ?? '';
        
        // Clean subject for matching (remove Fw:/Fwd:/Re: prefixes)
        $cleanSubject = preg_replace('/^(fw:|fwd:|re:)\s*/i', '', $subject);
        
        // First, try matching with the direct sender
        $receipt = $this->findMatchingReceipt($fromEmail, $cleanSubject);
        
        if ($receipt !== null) {
            return true;
        }
        
        // If no match with direct sender, try extracting original sender from body
        // This handles forwarded emails regardless of whether they have Fw:/Fwd: prefix
        $fullMessage = $this->nylasService->getMessage($grantId, $message['id'], true);
        $bodyHtml = $fullMessage['data']['body'] ?? '';
        
        if (!empty($bodyHtml)) {
            // Extract all sender emails from forwarded headers in body
            // The first From: is often the forwarder; the original sender may be deeper
            if (preg_match_all('/From:.*?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i', $bodyHtml, $matches)) {
                foreach ($matches[1] as $rawEmail) {
                    $extractedEmail = strtolower(trim($rawEmail));
                    if ($extractedEmail === $fromEmail || empty($extractedEmail)) {
                        continue; // Skip the direct sender we already tried
                    }
                    if (filter_var($extractedEmail, FILTER_VALIDATE_EMAIL)) {
                        $receipt = $this->findMatchingReceipt($extractedEmail, $cleanSubject);
                        if ($receipt !== null) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
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
            $receiptFromSubjects = $receipt->from_subject ?? [];
            if (! is_array($receiptFromSubjects)) {
                $receiptFromSubjects = [(string) $receiptFromSubjects];
            }
            $receiptFromSubjects = array_values(array_filter(
                array_map('trim', $receiptFromSubjects),
                fn ($v) => $v !== ''
            ));

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
            
            if (empty($receiptFromSubjects)) {
                // If receipt has no subject requirement, email match is sufficient
                $subjectMatches = true;
            } else {
                // Partial match: receipt subject must be contained in message subject (case-insensitive)
                // Example: "AT&T payment processed for account ending in" matches "AT&T payment processed for account ending in 1733"
                // Any one of the configured subjects matching is sufficient.
                foreach ($receiptFromSubjects as $candidate) {
                    if (stripos($messageSubject, $candidate) !== false) {
                        $subjectMatches = true;
                        break;
                    }
                }
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
        ?int $vendor_id,
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
            ->when($vendor_id !== null, fn($q) => $q->where('vendor_id', $vendor_id), fn($q) => $q->whereNull('vendor_id'))
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
     * Find an existing expense whose receipt shares the same DEPOSIT NO# as the current receipt content.
     * Home Depot sends separate deposit and final receipts for the same purchase.
     */
    protected function findExpenseBySharedDepositNumber(
        int $belongs_to_vendor_id,
        ?int $vendor_id,
        string $date,
        string $receiptContent
    ): ?Expense {
        if (! preg_match('/DEPOSIT NO#\s*(\S+)/', $receiptContent, $matches)) {
            return null;
        }

        $depositNumber = $matches[1];
        $startDate = Carbon::parse($date)->subDays(10)->format('Y-m-d');
        $endDate = Carbon::parse($date)->addDays(5)->format('Y-m-d');

        return Expense::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $belongs_to_vendor_id)
            ->when($vendor_id !== null, fn($q) => $q->where('vendor_id', $vendor_id), fn($q) => $q->whereNull('vendor_id'))
            ->whereNull('deleted_at')
            ->whereBetween('date', [$startDate, $endDate])
            ->whereHas('receipts', function ($q) use ($depositNumber) {
                $q->where('receipt_html', 'LIKE', '%' . $depositNumber . '%');
            })
            ->orderBy('date')
            ->first();
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
            $consolidatedExpense->searchable();
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
        ?int $vendor_id,
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
            ->when($vendor_id !== null, fn($q) => $q->where('vendor_id', $vendor_id), fn($q) => $q->whereNull('vendor_id'))
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

    /**
     * Find the first non-inline PDF attachment in a Nylas message attachment
     * list (using filename / content_type metadata only — no download).
     *
     * @param array<int,array<string,mixed>> $attachments
     * @return array<string,mixed>|null
     */
    protected function firstPdfAttachment(array $attachments): ?array
    {
        foreach ($attachments as $att) {
            if (!empty($att['is_inline'])) {
                continue;
            }
            $ct = strtolower((string) ($att['content_type'] ?? ''));
            $fn = strtolower((string) ($att['filename'] ?? ''));
            if (stripos($ct, 'pdf') !== false || str_ends_with($fn, '.pdf')) {
                return $att;
            }
        }

        return null;
    }

    /**
     * Detect the actual file type from content magic bytes, falling back to
     * the attachment filename extension or content_type header.
     */
    protected function detectFileType(string $content, ?string $filename = null, ?string $contentType = null): string
    {
        $header = substr($content, 0, 8);

        // Check magic bytes
        if (str_starts_with($header, '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($header, "\x89PNG")) {
            return 'png';
        }

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        // Fallback: infer from filename extension
        if ($filename) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'])) {
                return $ext;
            }
        }

        // Fallback: infer from content_type
        if ($contentType) {
            $map = [
                'application/pdf' => 'pdf',
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
            ];

            foreach ($map as $mime => $type) {
                if (str_contains(strtolower($contentType), $mime)) {
                    return $type;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Detect the "bill to" vendor from OCR raw content.
     *
     * Parses the "BILL TO:" section from receipt/order documents and matches
     * the company name against known vendor business names.
     *
     * @return int|null The vendor_id of the matched bill-to vendor, or null
     */
    protected function detectBillToVendorId(string $rawContent, ?int $excludeVendorId = null): ?int
    {
        if ($rawContent === '') {
            return null;
        }

        // Parse "BILL TO:", "QUOTE TO:", "SOLD TO:", "SHIP TO:", or "ORDER TO:" sections
        // — capture the first non-empty line after each label, try all matches
        if (!preg_match_all('/(?:BILL|QUOTE|SOLD|SHIP|ORDER)\s*TO\s*:?\s*\n\s*\n?\s*(.+)/i', $rawContent, $matches)) {
            return null;
        }

        $normalize = function (string $name): string {
            $name = strtolower($name);
            $name = preg_replace('/\b(inc|llc|corp|co|ltd|company)\b\.?/i', '', $name);
            $name = str_replace(['.', ',', "'"], '', $name);

            return trim(preg_replace('/\s+/', ' ', $name));
        };

        $vendors = Vendor::withoutGlobalScopes()->whereNotNull('business_name')
            ->where('business_name', '!=', '')
            ->get(['id', 'business_name']);

        // Try each captured name until one resolves to a known vendor
        foreach ($matches[1] as $billToRaw) {
            $billToName = trim($billToRaw);
            if ($billToName === '' || is_numeric($billToName)) {
                continue;
            }

            $normalizedBillTo = $normalize($billToName);
            if ($normalizedBillTo === '') {
                continue;
            }

            foreach ($vendors as $vendor) {
                if ($vendor->id === $excludeVendorId) {
                    continue;
                }

                $normalizedVendor = $normalize($vendor->business_name);
                if ($normalizedVendor === '') {
                    continue;
                }

                if ($normalizedBillTo === $normalizedVendor) {
                    return $vendor->id;
                }

                if (str_contains($normalizedVendor, $normalizedBillTo) || str_contains($normalizedBillTo, $normalizedVendor)) {
                    return $vendor->id;
                }
            }
        }

        return null;
    }

    /**
     * Detect the "sold to" / "bill to" client from OCR raw content.
     *
     * Parses the BILL/SOLD/SHIP/QUOTE/ORDER TO sections from receipt/order
     * documents and matches each captured block against the full names of
     * users associated with clients. Returns the client_id only when a
     * single distinct client is matched (ambiguous matches return null).
     *
     * @return int|null The matched client_id, or null
     */
    protected function detectBillToClientId(string $rawContent): ?int
    {
        if ($rawContent === '') {
            return null;
        }

        // Capture the few lines following each label so multi-line "SOLD TO:" blocks
        // (e.g. "LOU & RICK FRIEDMAN\n239 PERTH RD\nCARY, IL 60013") are searchable.
        if (!preg_match_all(
            '/(?:BILL|QUOTE|SOLD|SHIP|ORDER)\s*TO\s*:?\s*\n((?:[^\n]+\n){1,6})/i',
            $rawContent,
            $matches
        )) {
            return null;
        }

        $users = User::query()
            ->whereNotNull('first_name')
            ->whereNotNull('last_name')
            ->where('first_name', '!=', '')
            ->where('last_name', '!=', '')
            ->whereHas('clients')
            ->with(['clients' => function ($q) {
                $q->withoutGlobalScopes()->select('clients.id', 'clients.address', 'clients.zip_code');
            }])
            ->get(['id', 'first_name', 'last_name']);

        // Score each candidate client; pick the highest. Scoring:
        //   +2 for each user (first+last name) match within a label block
        //   +3 if the client's zip_code appears in the same block
        //   +2 if the client's street address (number + first word) appears in the block
        $clientScores = [];

        foreach ($matches[1] as $block) {
            foreach ($users as $user) {
                $first = preg_quote(trim($user->first_name), '/');
                $last = preg_quote(trim($user->last_name), '/');
                if ($first === '' || $last === '') {
                    continue;
                }

                // Allow tokens between first and last (e.g. "LOU & RICK FRIEDMAN"
                // matches both "Lou Friedman" and "Rick Friedman").
                if (!preg_match('/\b'.$first.'\b[^\n]*\b'.$last.'\b/i', $block)) {
                    continue;
                }

                foreach ($user->clients as $client) {
                    $clientScores[$client->id] = ($clientScores[$client->id] ?? 0) + 2;

                    if (!empty($client->zip_code) && stripos($block, (string) $client->zip_code) !== false) {
                        $clientScores[$client->id] += 3;
                    }

                    if (!empty($client->address)) {
                        $addr = trim((string) $client->address);
                        // Match street number + first street-name token (e.g. "239 PERTH").
                        if (preg_match('/^\s*(\d+)\s+(\S+)/', $addr, $am)) {
                            $needle = '/\b'.preg_quote($am[1], '/').'\s+'.preg_quote($am[2], '/').'\b/i';
                            if (preg_match($needle, $block)) {
                                $clientScores[$client->id] += 2;
                            }
                        }
                    }
                }
            }
        }

        if (empty($clientScores)) {
            return null;
        }

        arsort($clientScores);
        $top = array_keys($clientScores);
        $topScore = $clientScores[$top[0]];

        // Tie at the top → ambiguous, skip rather than guess wrong.
        if (isset($top[1]) && $clientScores[$top[1]] === $topScore) {
            return null;
        }

        return $top[0];
    }

    /**
     * Resolve the project_id for a detected bill-to client. Prefers a project
     * that belongs to the same Hive vendor as the company email; falls back to
     * the client's most recent non-deleted project.
     */
    protected function resolveProjectIdForClient(int $clientId, ?int $preferredVendorId): ?int
    {
        $base = Project::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->whereNull('deleted_at');

        if ($preferredVendorId) {
            $projectId = (clone $base)
                ->where('belongs_to_vendor_id', $preferredVendorId)
                ->latest('id')
                ->value('id');

            if ($projectId) {
                return $projectId;
            }
        }

        return $base->latest('id')->value('id');
    }

    /**
     * Try to resolve a forwarding email address to a vendor via the user_vendor relationship.
     * e.g. jenn@jpeterson-design.com → user 37 → vendor 60 (J Peterson Design, Inc.)
     */
    protected function detectBelongsToVendorFromEmail(string $email, ?int $excludeVendorId = null): ?int
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = User::where('email', $email)->first(['id']);
        if (!$user) {
            return null;
        }

        $userVendor = UserVendor::where('user_id', $user->id)
            ->when($excludeVendorId, fn ($q) => $q->where('vendor_id', '!=', $excludeVendorId))
            ->first(['vendor_id']);

        return $userVendor?->vendor_id;
    }
}
