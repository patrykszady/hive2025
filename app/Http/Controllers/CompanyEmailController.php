<?php

namespace App\Http\Controllers;

use App\Models\CompanyEmail;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Receipt;
use App\Models\ReceiptAccount;

use App\Services\NylasService;
use App\Services\AzureDocumentService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Carbon\Carbon;
use Spatie\Browsershot\Browsershot;
use Intervention\Image\Facades\Image;

class CompanyEmailController extends Controller
{
    private $nylasService;
    protected $azureDocumentService;


    /**
     * Inject the NylasService into the controller.
     */
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
        } catch (\Exception $e) {
            Log::error(["Failed to retrieve Nylas authentication URL: ", $e->getMessage()]);
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
            Log::error(["Failed to nylasAuthResponse: ", $request->all()]);
            return redirect()->back()->withErrors(['error' => $request->query('error')]);
        }

        $code = $request->query('code');

        try {
            // Exchange auth code for a token
            $nylasAccount = $this->nylasService->exchangeAuthCodeForToken($code);

            if (isset($nylasAccount['email'])) {
                // Save the account to the database
                $this->saveAccountToDatabase($nylasAccount);

                // Ensure required folders exist and get their data
                $folders = $this->nylasService->ensureFoldersExist($nylasAccount['grant_id']);

                // Update the CompanyEmail record's api_json column with the folder data
                CompanyEmail::where('grant_id', $nylasAccount['grant_id'])
                    ->update(['api_json' => json_encode(['folders' => $folders])]);

                return redirect(route('company_emails.index'))->with('success', 'Nylas account connected successfully.');
            } else {
                return redirect()->back()->withErrors(['error' => 'Failed to retrieve account details from Nylas.']);
            }
        } catch (\Exception $e) {
            Log::error(["Failed to handle Nylas authentication response:", $e->getMessage()]);
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
            CompanyEmail::create([
                'email' => $nylasAccount['email'],
                'grant_id' => $nylasAccount['grant_id'],
                // 'api_json' => $nylasAccount, // Store all account details as JSON
                'vendor_id' => auth()->user()->vendor->id, // Associate with the authenticated user's vendor
            ]);
        }
    }

    /**
     * Fetch consolidated orders for all emails with grant_id.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchConsolidatedOrders()
    {
        // Fetch all CompanyEmail records with a grant_id
        $companyEmails = CompanyEmail::withoutGlobalScopes()->whereNotNull('grant_id')->get();

        $results = []; // Array to store responses

        foreach ($companyEmails as $companyEmail) {
            $grantId = $companyEmail->grant_id; // Extract the grant_id

            // Call the NylasService's method for each grant_id
            $consolidatedOrder = $this->nylasService->getConsolidatedOrder($grantId);

            dd($consolidatedOrder); // Debugging: dump the consolidated order
            // Append the result
            $results[] = [
                'email_id' => $companyEmail->id,
                'grant_id' => $grantId,
                'consolidated_order' => $consolidatedOrder,
            ];
        }

        // Return results as a JSON response
        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    public function fetchMessagesForGrantId()
    {
        // Retrieve all CompanyEmail records with a grant_id
        $companyEmails = CompanyEmail::withoutGlobalScopes()->whereNotNull('grant_id')->get();
        $receipts = Receipt::all();

        foreach ($companyEmails as $companyEmail) {
            $grantId = $companyEmail->grant_id; // Extract the grant_id

            // Define the folders to query based on the environment
            // $folders = env('APP_ENV') !== 'production'
            //     ? ['inbox', $companyEmail->api_json['folders']['Retry']] // Include production folders
            //     : [$companyEmail->api_json['folders']['Test']];          // Include test folders in non-production

            // $allMessages = []; // Array to store all messages

            // foreach ($folders as $folder) {
            //     // Define query parameters for the Nylas API


            //     // Merge messages from the current folder into the combined array
            //     $allMessages = array_merge($allMessages, $messages);
            // }
            $queryParams = [
                'limit' => 99,       // Fetch a limited number of messages
                'in' => 'inbox',     // Specify the folder to filter messages from
            ];

            // Fetch messages from the current folder using the NylasService
            $messages = $this->nylasService->getMessages($queryParams, $grantId);

            foreach($messages['data'] as $message) {
                $fromEmail = $message['from'][0]['email'];
                $subject = $message['subject'];
                $dateEmail = Carbon::parse($message['date'])->setTimezone('America/Chicago')->format('Y-m-d H:i:s');

                // Check if the 'from' email and 'subject' match any receipt
                $receipt = $receipts->first(function ($receipt) use ($fromEmail, $subject) {
                    return strcasecmp($receipt->from_address, $fromEmail) === 0
                        && stripos($subject, $receipt->from_subject) !== false; // Check if "Sale" appears in the subject
                });

                //If null, check if email was forwarded
                if(is_null($receipt)){
                    $emailBody = strip_tags($message['body']);
                    $emailBody = html_entity_decode($emailBody); // Clean up encoded characters

                    preg_match('/From:\s.*?<(.*?)>/', $emailBody, $fromMatch);
                    preg_match('/Sent:\s*(.+?)\s*To:/', $emailBody, $dateMatch);

                    $fromEmail = trim($fromMatch[1] ?? '');
                    $date = trim($dateMatch[1] ?? '');
                    $dateEmail = Carbon::parse($date)->setTimezone('America/Chicago')->format('Y-m-d H:i:s');

                    $receipt = $receipts->first(function ($receipt) use ($fromEmail, $subject) {
                        return strcasecmp($receipt->from_address, $fromEmail) === 0
                            && stripos($subject, $receipt->from_subject) !== false; // Check if "Sale" appears in the subject
                    });
                }

                if(!$receipt) {
                    // No matching receipt found, skip to the next message
                    $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['ERROR'], $grantId);
                    continue;
                }elseif ($receipt) {
                    $messageId = $message['id'];
                    $toEmail = $message['to'][0]['email'];
                    $string = $message['body'];

                    // Check if the body contains HTML
                    $bodyType = strip_tags($string) !== $string ? 'html' : 'text';

                    // Handle images
                    $image_email_url = null;
                    if (isset($receipt->options['receipt_image_regex'])) {
                        preg_match($receipt->options['receipt_image_regex'], $string, $matches);
                        $image_email_url = $matches[1][0] ?? null;
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

                    if (!isset($receipt->options['receipt_image_regex']) && !isset($receipt->options['pdf_html'])) {
                        $doc_type = 'pdf';
                        $ocr_filename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '.pdf';
                        // Render the view with proper data passing
                        $view = view('misc.create_pdf_receipt', [
                            'receipt_html_main' => $receipt_html_main,
                            'message_type' => $bodyType
                        ])->render();

                        $location = storage_path($ocr_path = 'files/_temp_ocr/' . $ocr_filename);

                        Browsershot::html($view)->newHeadless()->format('A4')->margins(20, 0, 20, 20)->save($location);
                    } elseif (isset($receipt->options['pdf_html'])) {
                        $doc_type = 'pdf';
                        if (!empty($message['attachments'])) {
                            $attachments = $message['attachments'];

                            // Process attachments
                            foreach ($attachments as $attachment) {
                                $ocr_filename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '.pdf';
                                $attachmentContent = $this->nylasService->downloadAttachment($attachment['id'], $grantId, $messageId);

                                // $attachment = collect($attachments)->first(function ($attachment_found, $loop) use ($receipt) {
                                //     if (isset($receipt->options['attachment_name'])) {
                                //         preg_match('/' . $receipt->options['attachment_name'] . '/', $attachment_found->getName(), $matches);
                                //         return !empty($matches) || array_key_last($attachments) === $loop;
                                //     }
                                //     return true;
                                // });

                                Storage::disk('files')->put('/_temp_ocr/' . $ocr_filename, $attachmentContent);
                                $location = storage_path($ocr_path = 'files/_temp_ocr/' . $ocr_filename);
                            }
                        } else {
                            // No attachments found
                            $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Error'], $grantId);
                            continue;
                        }
                    } else {
                        $doc_type = 'jpg';
                        Image::make($image_email_url)->save($location);

                        $ocr_filename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '.jpg';
                        $location = storage_path($ocr_path = 'files/_temp_ocr/' . $ocr_filename);
                    }


                    $document_model = $receipt->options['document_model'];
                    //ocr the file
                    $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)->azure_receipts($ocr_path, $doc_type, $document_model);
                    //pass receipt info to ocr_extract method
                    $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->ocr_extract($ocr_receipt_extracted, null, 'email');

                    $receipt_account =
                        ReceiptAccount::withoutGlobalScopes()
                            ->where('belongs_to_vendor_id', $companyEmail->vendor_id)
                            ->where('vendor_id', $receipt->vendor_id)
                            ->first();

                    //missing receipt_account..receipt and companyemail exist but receipt/companyemail combo does not
                    if (is_null($receipt_account)) {
                        $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Add'], $grantId);
                        continue;
                    }

                    //01-26-2023 pass rest of receipt info to ocr_extract method
                    if (! is_null($ocr_receipt_data['fields']['transaction_date'])) {
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
                    //1-18-2023 | 9/30/2023 NEED TO ACCOUNT FOR SAME VENDOR, AMOUNT, AND DATE being saved multiple of times (accounted for in old $duplicates in $this->dirty_work)
                    //maybe by adding date_TIME to 'date'? or checking time in the expense_receipt_data json?

                    $duplicates =
                        Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)->
                            where('vendor_id', $receipt->vendor_id)->
                            whereNull('deleted_at')->
                            where('amount', $amount)->
                            // where('invoice', $invoice)->
                            where('date', $date)->
                            // whereBetween('date', [Carbon::create($date)->subDay(), Carbon::create($date)->addDays(4)])->
                            get();

                    if ($duplicates->isNotEmpty()) {
                        // 1-22-2023! WHAT IF THERE IS MULTIPLE?! -- diff in days!
                        $duplicate_expense = $duplicates->first();

                        //ATTACHMENTS
                        $this->saveExpenseReceipt($duplicate_expense->id, $ocr_receipt_data, $ocr_filename);

                        //add po and add invoice from ocr
                        $duplicate_expense->invoice = $invoice;
                        $duplicate_expense->date = $date;
                        $duplicate_expense->save();

                        //move email receipt to Duplicate folder
                        $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Duplicate'], $grantId);
                        continue;
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
                        $this->saveExpenseReceipt($expense->id, $ocr_receipt_data, $ocr_filename);

                        $this->nylasService->moveEmailToFolder($messageId, $companyEmail->api_json['folders']['Saved'], $grantId);
                        continue;
                    }
                }
            }
        }
    }

    public function saveExpenseReceipt($expense_id, $ocr_receipt_data, $ocr_filename)
    {
        $filename = $expense_id.'-'.$ocr_filename;

        $expense_receipt = new ExpenseReceipts;
        $expense_receipt->expense_id = $expense_id;
        $expense_receipt->receipt_filename = $filename;
        $expense_receipt->receipt_html = $ocr_receipt_data['content'];
        $expense_receipt->receipt_items = $ocr_receipt_data['fields'];
        $expense_receipt->save();

        //move _temp_ocr file to /files/receipts
        Storage::disk('files')->move('/_temp_ocr/'.$ocr_filename, '/receipts/'.$filename);
    }
}
