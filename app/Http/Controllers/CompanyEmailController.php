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
        if ($request->has('error')) {
            Log::channel('nylas')->error(["Failed to nylasAuthResponse: ", $request->all()]);
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

                return redirect(route('company_emails.index'))->with('success', 'Nylas account connected successfully.');
            } else {
                return redirect()->back()->withErrors(['error' => 'Failed to retrieve account details from Nylas.']);
            }
        } catch (\Exception $e) {
            Log::channel('nylas')->error('Failed to handle Nylas authentication response', ApiErrorFormatter::format($e, [
                'code' => $code,
            ]));
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
        }
    }

    /**
     * Create the "HIVE RECEIPTS" folder for a company email and store the folder ID
     */
    private function createHiveReceiptsFolder(CompanyEmail $companyEmail): void
    {
        try {
            $folderResult = $this->nylasService->createFolder($companyEmail->grant_id, 'HIVE RECEIPTS');
            
            if ($folderResult['status'] === 200 || $folderResult['status'] === 201) {
                $folderId = $folderResult['data']['id'] ?? null;
                
                if ($folderId) {
                    // Update the api_json with the folder information using HIVE_RECEIPTS_FOLDER key
                    $apiJson = $companyEmail->api_json ?? [];
                    $apiJson['HIVE_RECEIPTS_FOLDER'] = $folderId;
                    
                    $companyEmail->update(['api_json' => $apiJson]);
                    
                    Log::channel('nylas')->info('HIVE RECEIPTS folder created successfully', [
                        'company_email_id' => $companyEmail->id,
                        'grant_id' => $companyEmail->grant_id,
                        'folder_id' => $folderId,
                    ]);
                } else {
                    Log::channel('nylas')->warning('Folder created but no ID returned', [
                        'company_email_id' => $companyEmail->id,
                        'grant_id' => $companyEmail->grant_id,
                        'response' => $folderResult,
                    ]);
                }
            } else {
                Log::channel('nylas')->error('Failed to create HIVE RECEIPTS folder', [
                    'company_email_id' => $companyEmail->id,
                    'grant_id' => $companyEmail->grant_id,
                    'status' => $folderResult['status'],
                    'error' => $folderResult['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('nylas')->error('Exception creating HIVE RECEIPTS folder', ApiErrorFormatter::format($e, [
                'company_email_id' => $companyEmail->id,
                'grant_id' => $companyEmail->grant_id,
            ]));
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

    public function fetchMessagesForGrantId()
    {
        dd('fetchMessagesForGrantId');
        // Retrieve all CompanyEmail records with a grant_id
        $companyEmails = CompanyEmail::withoutGlobalScopes()->whereNotNull('grant_id')->get();
        $receipts = Receipt::all();

        foreach ($companyEmails as $companyEmail) {
            $grantId = $companyEmail->grant_id; // Extract the grant_id

            // Define the folders to query based on the environment.
            $folders = env('APP_ENV') === 'production'
                ? ['inbox', $companyEmail->api_json['folders']['Retry']] // For non-production, use both inbox and retry folder.
                //$companyEmail->api_json['folders']['Test']
                : ['inbox'];          // For production, use the test folder.

            $syncResult = $this->nylasService->syncMessages($folders, $companyEmail);
            $allMessages = $syncResult['messages'];

            foreach($allMessages as $message) {
                // Display message structure without rendering HTML body
                $messageDisplay = $message;
                if (isset($messageDisplay['body'])) {
                    $messageDisplay['body'] = '[HTML CONTENT - ' . strlen($message['body']) . ' chars]';
                }
                // echo '<pre>' . json_encode($messageDisplay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</pre>';
                // dd();
                $messageId = $message['id'];
                $fromEmail = $message['from'][0]['email'];
                $subject = $message['subject'];
                $dateEmail = Carbon::parse($message['date'])->setTimezone('America/Chicago')->format('Y-m-d');

                // Check if the 'from' email and 'subject' match any receipt
                //receipt_type 0 = API
                $receipt = $receipts->where('receipt_type', '!=', 0)->first(function ($receipt) use ($fromEmail, $subject) {
                    return strcasecmp($receipt->from_address, $fromEmail) === 0
                        && stripos($subject, $receipt->from_subject) !== false;
                });

                //If null, check if email was forwarded
                if(is_null($receipt)){
                    $emailBody = strip_tags($message['body']);
                    $emailBody = html_entity_decode($emailBody); // Clean up encoded characters
                    preg_match('/From:\s.*?<(.*?)>/', $emailBody, $fromMatch);
                    preg_match('/Sent:\s*(.+?)\s*To:/', $emailBody, $dateMatch);
                    $fromEmail = trim($fromMatch[1] ?? '');
                    $date = trim($dateMatch[1] ?? '');
                    $dateEmail = Carbon::parse($date)->setTimezone('America/Chicago')->format('Y-m-d');

                    //receipt_type 0 = API
                    $receipt = $receipts->where('receipt_type', '!=', 0)->first(function ($receipt) use ($fromEmail, $subject) {
                        return strcasecmp($receipt->from_address, $fromEmail) === 0
                            && stripos($subject, $receipt->from_subject) !== false;
                    });
                }

                // dd($receipt);
                if ($receipt) {
                    $toEmail = $message['to'][0]['email'];
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

                            // $attachment = collect($attachments)->first(function ($attachment_found, $loop) use ($receipt) {
                            //     if (isset($receipt->options['attachment_name'])) {
                            //         preg_match('/' . $receipt->options['attachment_name'] . '/', $attachment_found->getName(), $matches);
                            //         return !empty($matches) || array_key_last($attachments) === $loop;
                            //     }
                            //     return true;
                            // });

                            $ocr_path = '_temp_ocr/' . $ocr_filename;
                            Storage::disk('files')->put($ocr_path, $attachmentContent);
                        } else {
                            // No attachments found
                            $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Error'], $grantId, $companyEmail->id);
                            continue;
                        }
                    } else {
                        // Image processing - override the default $doc_type
                        $doc_type = 'jpg';
                        $ocr_filename .= '.' . $doc_type;
                        $ocr_path = '_temp_ocr/' . $ocr_filename;
                        $location = Storage::disk('files')->path($ocr_path);
                        
                        // Validate image URL before processing
                        if (empty($image_email_url)) {
                            // Log error and skip image processing
                            Log::error("Empty image URL for receipt", [
                                'receipt_id' => $receipt->id,
                                'message_id' => $messageId ?? null
                            ]);
                            // Move to error folder or handle appropriately
                            $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Error'], $grantId, $companyEmail->id);
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
                            $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Error'], $grantId, $companyEmail->id);
                            continue;
                        }
                    }

                    $document_model = $receipt->options['document_model'];

                    //ocr the file
                    $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)->azure_receipts($ocr_path, $doc_type, $document_model);

                    //pass receipt info to ocr_extract method
                    $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->ocr_extract($ocr_receipt_extracted, null, 'email');

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
                        $this->nylasService->moveEmailToFolder(
                            $messageId,
                            $companyEmail->api_json['folders']['Add'],
                            $grantId,
                            $companyEmail->id
                        );
                        continue;
                    }

                    //01-26-2023 pass rest of receipt info to ocr_extract method
                    if (!is_null($ocr_receipt_data['fields']['transaction_date'])) {
                        $date = $ocr_receipt_data['fields']['transaction_date'];
                    } else {
                        $date = $dateEmail;
                    }

                    //8-18-23 we can remove this?!
                    if (isset($receipt->options['refund'])) {
                        $amount = '-'.$ocr_receipt_data['fields']['total'];
                    } else {
                        $amount = $ocr_receipt_data['fields']['total'];
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

                    // Prefer matching by invoice number when available to avoid
                    // false positives on same-day/same-amount receipts.
                    $invoice = isset($invoice) ? trim((string) $invoice) : '';
                    if ($invoice !== '') {
                        $duplicates = Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                            ->where('vendor_id', $receipt->vendor_id)
                            ->where('invoice', $invoice)
                            ->get();
                    } else {
                        // Candidate pool by amount + date (eager-load receipts to avoid N+1)
                        $candidates = Expense::with('receipts')
                            ->where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                            ->where('vendor_id', $receipt->vendor_id)
                            ->whereNull('deleted_at')
                            ->where('amount', $amount)
                            ->where('date', $date)
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
                                if (!$receiptRecord) {
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
                        // Choose the best matching duplicate.
                        if ($invoice !== '') {
                            // With invoice, prefer the closest date to the OCR date.
                            $duplicate_expense = $duplicates->sortBy(function ($d) use ($date) {
                                return abs(Carbon::parse($d->date)->diffInDays(Carbon::parse($date)));
                            })->first();
                        } else {
                            // Without invoice: if we captured time for current receipt, prefer identical-time matches.
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

                            // Then pick the one with the closest date as a final tie-breaker.
                            $duplicate_expense = $chosen->sortBy(function ($d) use ($date) {
                                return abs(Carbon::parse($d->date)->diffInDays(Carbon::parse($date)));
                            })->first();
                        }

                        //ATTACHMENTS
                        $this->saveExpenseReceipt($duplicate_expense->id, $ocr_receipt_data, $ocr_filename, $message);

                        //add po and add invoice from ocr
                        // $duplicate_expense->invoice = $invoice;
                        // $duplicate_expense->date = $date;
                        // $duplicate_expense->save();

                        //move email receipt to Duplicate folder
                        $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Duplicate'], $grantId, $companyEmail->id);
                    }else{
                        //SAVE expense
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

                        $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Saved'], $grantId, $companyEmail->id);
                    }
                }
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

            // Fetch messages from the inbox using the incremental sync helper
            $syncResult = $this->nylasService->syncMessages(['inbox'], $company_email);
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
                                $this->nylasService->moveOriginalMessageToHiveFolder($messageId, $grantId, $company_email->id);
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
                        } elseif ($duplicates->isEmpty()) {
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
                        $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename);
                    } // end foreach attachment

                    // After processing all attachments for the message, move the email to the HIVE folder.
                    $this->nylasService->moveOriginalMessageToHiveFolder(
                        $messageId,
                        $grantId,
                        $company_email->id
                    );
                    continue;
                }
            }
        }
    }

    public function saveExpenseReceipt($expense_id, $ocr_receipt_data, $ocr_filename, $message = NULL)
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
                        $isDuplicate = $this->isDuplicateReceipt($expense_id, $receipt_html, $receipt_items);
                        
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
        $isDuplicate = $this->isDuplicateReceipt($expense_id, $ocr_receipt_data['content'], $ocr_receipt_data['fields']);
        
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

        // Build dynamic alias map from vendor_transactions.desc
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
        if (!isset($aliasCache[$aliasCacheKey])) {
            $aliasRows = [];
            if (!empty($vendorIds)) {
                try {
                    $aliasRows = DB::table('vendor_transactions')
                        ->select(['vendor_id', DB::raw('`desc` as pattern')])
                        ->whereIn('vendor_id', $vendorIds)
                        ->get();
                } catch (\Throwable $e) {
                    // If the table is missing or any error occurs, fall back to no aliases.
                    $aliasRows = collect();
                }
            }

            $map = [];
            foreach ($aliasRows as $row) {
                $pat = $normalize((string) ($row->pattern ?? ''));
                if ($pat === '') {
                    continue;
                }
                $map[(int) $row->vendor_id][] = [
                    'norm' => $pat,
                    'compact' => str_replace(' ', '', $pat),
                ];
            }
            $aliasCache[$aliasCacheKey] = $map;
        }
        $aliasMap = $aliasCache[$aliasCacheKey];

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

            // Token overlap bonus: each overlapping token adds weight
            if (!empty($ocrTokens) && !empty($vendorTokens)) {
                $overlap = array_intersect($ocrTokens, $vendorTokens);
                if (!empty($overlap)) {
                    // Give a small boost per exact token match
                    $score += min(20, count($overlap) * 5);
                }
            }

            // Dynamic alias boost based on vendor_transactions entries
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
     * Check if a receipt is a duplicate based on content and invoice number.
     */
    protected function isDuplicateReceipt(int $expense_id, string $receipt_html, $receipt_items): bool
    {
        // Decode receipt_items if it's a string
        if (is_string($receipt_items)) {
            $receipt_items = json_decode($receipt_items, true);
        }
        
        // Extract invoice number from receipt items
        $new_invoice_number = null;
        if (is_array($receipt_items) && isset($receipt_items['invoice_number'])) {
            $new_invoice_number = $receipt_items['invoice_number'];
        }
        
        // Get existing receipts for this expense
        $existing_receipts = ExpenseReceipts::where('expense_id', $expense_id)->get();
        
        foreach ($existing_receipts as $existing_receipt) {
            // Check for exact HTML content match
            if ($existing_receipt->receipt_html === $receipt_html) {
                return true;
            }
            
            // Check for invoice number match if both have invoice numbers
            if ($new_invoice_number && $existing_receipt->receipt_items) {
                $existing_items = is_string($existing_receipt->receipt_items) 
                    ? json_decode($existing_receipt->receipt_items, true) 
                    : (array) $existing_receipt->receipt_items;
                    
                if (isset($existing_items['invoice_number']) && 
                    $existing_items['invoice_number'] === $new_invoice_number) {
                    return true;
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
        $messageLimitDate = Carbon::now()->subDays(config('nylas.message_limit_days', 10));

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
        
        // Fetch and filter messages using NylasService
        $matchingMessages = $this->nylasService->getMessagesMatchingCriteria(
            $grantId, 
            $receiptCriteria, 
            $receivedAfter
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
        
        if ($vendorRegistrationDate) {
            return $messageLimitDate->greaterThan($vendorRegistrationDate) 
                ? $messageLimitDate 
                : $vendorRegistrationDate;
        }
        
        return $messageLimitDate;
    }
}
