<?php

namespace App\Http\Controllers;

use App\Jobs\TransactionVendorBulkMatchJob;
use App\Models\CompanyEmail;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use App\Services\NylasService;
// use App\Http\Requests\GetGiftCardRequest;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

use File;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Intervention\Image\Facades\Image;
use Nesk\Puphpeteer\Puppeteer;
use setasign\Fpdi\Fpdi;
use Symfony\Component\DomCrawler\Crawler;
use App\Support\ApiErrorFormatter;

class ReceiptController extends Controller
{
    private NylasService $nylasService;

    public function __construct(NylasService $nylasService)
    {
        $this->nylasService = $nylasService;
    }

    /**
     * Return latest Home Depot gift card redeem URL + screenshot path (JSON response).
     */
    // public function getHomeDepotMessages(GetGiftCardRequest $request)
    // {
    //     $companyEmailId = (int) $request->validated()['company_email_id'];
    //     $result = $this->giftCardService->captureLatest($companyEmailId);

    //     if (!$result['success']) {
    //         return response()->json($result, 422);
    //     }

    //     return response()->json($result);
    // }

    public function amazon_login()
    {
        $url = 'https://www.amazon.com/b2b/abws/oauth';

        $params = [
            'state' => '100',
            'redirect_uri' => env('AMAZON_REDIRECT_URI'),
            'applicationId' => env('AMAZON_APPLICATION_ID'),
        ];
        header('Location: '.$url.'?'.http_build_query($params));
    }

    public function amazon_auth_response()
    {
        if (isset(request()->query()['code'])) {
            $code = request()->query()['code'];
        } else {
            ///6-16-2023 return with error ... no code
            return redirect(route('company_emails.index'));
        }

        $guzzle = new Client;

        $url = 'https://api.amazon.com/auth/O2/token';
        $amazon_account_tokens = json_decode($guzzle->post($url, [
            'form_params' => [
                'client_id' => env('AMAZON_CLIENT_ID'),
                'client_secret' => env('AMAZON_CLIENT_SECRET'),
                'code' => $code,
                'redirect_uri' => env('AMAZON_REDIRECT_URI'),
                'grant_type' => 'authorization_code',
            ],
        ])->getBody()->getContents());

        $receipt_account = ReceiptAccount::where('vendor_id', 54)->first();

        //json
        $api_data = [
            'access_token' => $amazon_account_tokens->access_token,
            'refresh_token' => $amazon_account_tokens->refresh_token,
            //->setTimezone('America/Chicago')
            'expires_in' => Carbon::now()->addMinutes(55)->toIso8601String(),
            'token_type' => $amazon_account_tokens->token_type,
        ];

        $api_data = json_encode($api_data);

        $receipt_account->options = $api_data;
        $receipt_account->save();

        return redirect(route('company_emails.index'));
    }

    public function amazon_orders_api()
    {
        ini_set('max_execution_time', '4800');

        $receipt_accounts = ReceiptAccount::withoutGlobalScopes()->where('vendor_id', 54)->whereNotNull('options->refresh_token')->get();

        //Initialize the Credentials object.
        //access token and secret from AWS
        $credentials = new \Aws\Credentials\Credentials(env('AMAZON_AWS_ACCESS_TOKEN'), env('AMAZON_AWS_SECRET_TOKEN'));
        foreach ($receipt_accounts as $receipt_account) {
            //if NOW  is greater than > expires_in ... get new access_token
            //get new access_token valid for 1 hour and change 'expires_in' to 55 minutes from when submitted
            //ONLY if access token is expired....
            if (Carbon::now() > Carbon::parse($receipt_account->options['expires_in'])) {
                try {
                    $guzzle = new Client;
                    $url = 'https://api.amazon.com/auth/O2/token';
                    $amazon_account_tokens = json_decode($guzzle->post($url, [
                        'form_params' => [
                            'client_id' => env('AMAZON_CLIENT_ID'),
                            'client_secret' => env('AMAZON_CLIENT_SECRET'),
                            'refresh_token' => $receipt_account->options['refresh_token'],
                            'access_token' => $receipt_account->options['access_token'],
                            'grant_type' => 'refresh_token',
                        ],
                    ])->getBody()->getContents());
                } catch (RequestException $e) {
                    if ($e->hasResponse()) {
                        $response = $e->getResponse();
                        $responseBody = $response->getBody()->getContents();
                        $error = $responseBody;
                    } else {
                        $error = $e->getMessage();
                    }

                    $receipt_account->options += ['errors' => json_decode($error, true)];
                    $receipt_account->save();

                    Log::channel('company_emails_login_error')->error('Amazon token refresh failed', ApiErrorFormatter::format($e, [
                        'receipt_account_id' => $receipt_account->id,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        'has_response' => $e->hasResponse(),
                    ]));
                    continue;
                }

                $receipt_account->update([
                    'options->expires_in' => Carbon::now()->addMinutes(55)->toIso8601String(),
                    'options->access_token' => $amazon_account_tokens->access_token,
                ]);

                $receipt_account->fresh();
            }

            // Instantiate Client object with api key header.
            $client = new \GuzzleHttp\Client([
                'headers' => [
                    'host' => 'api.business.amazon.com',
                    'x-amz-access-token' => $receipt_account->options['access_token'],
                    'x-amz-date' => Carbon::now()->toIso8601String(),
                    'user-agent' => 'Hive Production/0.2 (Language=PHP;Platform=Linux)',
                ],
            ]);

            $url = 'https://na.business-api.amazon.com';

            // //FOR TESTING ONLY
            //INDIVIDUAL ORDER
            // $path = '/reports/2021-01-08/orders/113-2420373-2571468/';
            $path = '/reports/2021-01-08/orders/';
            // $params = array(
            //     'includeCharges' => 'true',
            //     'includeLineItems' => 'true',
            //     'includeShipments' => 'true',
            // );
            // // . '?' . http_build_query ($params)
            // $full_url = $url . $path . '?' . http_build_query ($params);

            // $request = new \GuzzleHttp\Psr7\Request('GET', $full_url);
            // //Intialize the signer.
            // $s4 = new \Aws\Signature\SignatureV4("execute-api", "us-east-1");
            // //Build the signed request using the Credentials object. This is required in order to authenticate the call.
            // $signedRequest = $s4->signRequest($request, $credentials);
            // //Send the (signed) API request.
            // $response = $client->send($signedRequest);
            // $result = collect(json_decode($response->getBody()->getContents(), true));

            // dd($result);

            //Incremental sync: fetch from last sync or default to 2 days ago
            //Nightly full 30-day sync catches order status changes (cancellations, returns)
            $lastFullSync = isset($receipt_account->options['amazon_orders_full_synced_at'])
                ? Carbon::parse($receipt_account->options['amazon_orders_full_synced_at'])
                : null;
            
            $needsFullSync = !$lastFullSync || $lastFullSync->lt(Carbon::today());
            
            if ($needsFullSync) {
                $startDate = Carbon::today()->subDays(30)->setTimezone('UTC');
            } else {
                $startDate = isset($receipt_account->options['amazon_orders_synced_at'])
                    ? Carbon::parse($receipt_account->options['amazon_orders_synced_at'])->setTimezone('UTC')
                    : Carbon::today()->subDays(2)->setTimezone('UTC');
            }
            
            $dates = CarbonPeriod::create($startDate, Carbon::today()->setTimezone('UTC'));

            foreach ($dates as $date) {
                $today = $date;

                $params = [
                    'startDate' => $today->startOfDay()->toIso8601String(),
                    'endDate' => $today->endOfDay()->toIso8601String(),
                    'includeCharges' => 'true',
                    'includeLineItems' => 'true',
                    'includeShipments' => 'true',
                ];

                $full_url = $url.$path.'?'.http_build_query($params);
                $request = new \GuzzleHttp\Psr7\Request('GET', $full_url);
                //Intialize the signer.
                $s4 = new \Aws\Signature\SignatureV4('execute-api', 'us-east-1');
                //Build the signed request using the Credentials object. This is required in order to authenticate the call.
                $signedRequest = $s4->signRequest($request, $credentials);
                
                //Send the (signed) API request with retry logic for rate limits
                try {
                    $response = $client->send($signedRequest);
                    $orders = collect(json_decode($response->getBody()->getContents(), true)['orders']);
                } catch (\GuzzleHttp\Exception\ClientException $e) {
                    // If rate limited (429), skip this date and continue
                    if ($e->getCode() === 429) {
                        Log::channel('amazon_orders')->warning('Rate limited, skipping date', [
                            'date' => $today->toDateString(),
                            'receipt_account_id' => $receipt_account->id,
                        ]);
                        continue;
                    }
                    throw $e;
                }
                
                // Add delay between requests to avoid rate limits (2 seconds)
                sleep(2);

                //Log all orders for this date
                Log::channel('amazon_orders')->info('Orders fetched', [
                    'date' => $today->toDateString(),
                    'receipt_account_id' => $receipt_account->id,
                    'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                    'order_count' => $orders->count(),
                    'is_full_sync' => $needsFullSync,
                ]);

                foreach ($orders as $orders_key => $order) {
                    //Log full order payload
                    Log::channel('amazon_orders')->debug('Processing order', [
                        'receipt_account_id' => $receipt_account->id,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        'order_id' => $order['orderId'],
                        'order_date' => $order['orderDate'],
                        'order_status' => $order['orderStatus'],
                        'order_amount' => $order['orderNetTotal']['amount'],
                        'full_payload' => $order,
                    ]);
                    $order_date = Carbon::parse($order['orderDate'])->setTimezone('America/Chicago')->format('Y-m-d');

                    //check for expense duplicates
                    $duplicates =
                        Expense::withoutGlobalScopes()->
                            where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)->
                            where('vendor_id', 54)-> //54 = AMAZON
                            // whereNull('deleted_at')->
                            where('invoice', $order['orderId'])->
                            // where('amount', $order['orderNetTotal']['amount'])->
                            where('amount', 'NOT LIKE', '-%')->
                            where('date', $order_date)->
                            get();

                    //7-17-2023 duplicate by Invoice/ Order # only... see if Order status changed
                    if ($duplicates->isEmpty()) {
                        //create expense
                        $bulkMatch = TransactionBulkMatch::findMatchForAmount(54, (float) $order['orderNetTotal']['amount']);
                        $expense = Expense::create([
                            'amount' => $order['orderNetTotal']['amount'],
                            'date' => $order_date,
                            'project_id' => null,
                            'distribution_id' => $bulkMatch?->distribution_id,
                            'created_by_user_id' => 0, //automated
                            'invoice' => $order['orderId'],
                            'vendor_id' => 54, //54 = AMAZON
                            'note' => null,
                            'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        ]);
                        $bulkMatch?->applySplits($expense, (float) $order['orderNetTotal']['amount']);
                    } else {
                        $expense = $duplicates->first();
                        if ($order['orderStatus'] == 'CANCELLED') {
                            $expense->amount = 0.00;
                            $expense->save();

                            $transactions = Transaction::withoutGlobalScopes()->where('expense_id', $expense->id)->get();
                            foreach ($transactions as $transaction) {
                                $transaction->expense_id = null;
                                $transaction->save();
                            }

                            $expense->delete();
                        } else {
                            if ($expense->amount != $order['orderNetTotal']['amount']) {
                                $expense->amount = $order['orderNetTotal']['amount'];
                                $expense->save();
                            }
                        }

                        //CHARGES
                        $charges = [];
                        foreach ($order['charges'] as $charges_key => $charge) {
                            $charges[$charges_key]['transactionDate'] = $charge['transactionDate'];
                            $charges[$charges_key]['transactionId'] = $charge['transactionId'];
                            $charges[$charges_key]['amount'] = $charge['amount']['amount'];
                            $charges[$charges_key]['paymentInstrumentLast4Digits'] = $charge['paymentInstrumentLast4Digits'];
                        }

                        $receipt = $expense->receipts()->latest()->first();

                        if (! is_null($receipt)) {
                            $items = $receipt->receipt_items;
                            $items['charges'] = $charges;

                            $receipt->receipt_items = $items;
                            $receipt->save();
                        }

                        continue;
                    }

                    // dd($expense);
                    //only runs/continues below IF
                    //$expense makes it here / doenst "continue" in the else above

                    //create expense_receipt_data
                    //ITEMS
                    $items = [];
                    foreach ($order['lineItems'] as $items_key => $item) {
                        // if(!isset($item['purchasedPricePerUnit']['amount'])){
                        //     dd($item, $order);
                        // }else{
                        //     dd($item, $order);
                        // }

                        $items[$items_key]['Price'] = $item['purchasedPricePerUnit']['amount'];
                        $items[$items_key]['Quantity'] = $item['itemQuantity'];
                        $items[$items_key]['TotalPrice'] = $item['itemSubTotal']['amount'] ?? 0.00;
                        $items[$items_key]['Description'] = $item['title'];
                        $items[$items_key]['ProductCode'] = $item['asin'];
                    }

                    //CHARGES
                    $charges = [];
                    foreach ($order['charges'] as $charges_key => $charge) {
                        $charges[$charges_key]['transactionDate'] = $charge['transactionDate'];
                        $charges[$charges_key]['transactionId'] = $charge['transactionId'];
                        $charges[$charges_key]['amount'] = $charge['amount']['amount'];
                        $charges[$charges_key]['paymentInstrumentLast4Digits'] = $charge['paymentInstrumentLast4Digits'];
                    }

                    //items array!
                    $expense_receipt_data = [
                        'items' => $items,
                        'total' => $order['orderNetTotal']['amount'],
                        'subtotal' => $order['orderSubTotal']['amount'],
                        'total_tax' => $order['orderTax']['amount'],
                        'invoice_number' => $order['orderId'],
                        'purchase_order' => $order['purchaseOrderNumber'],
                        'transaction_date' => $order_date,
                        'charges' => $charges,
                    ];

                    ExpenseReceipts::create([
                        'expense_id' => $expense->id,
                        'receipt_html' => null,
                        'receipt_items' => $expense_receipt_data,
                        'receipt_filename' => null,
                    ]);
                }
                // sleep(1);
            }

            //Update orders sync timestamp after successful processing
            $updates = ['options->amazon_orders_synced_at' => Carbon::now()->toIso8601String()];
            
            //If this was a full sync, update the full sync timestamp
            if ($needsFullSync) {
                $updates['options->amazon_orders_full_synced_at'] = Carbon::now()->toIso8601String();
            }
            
            $receipt_account->update($updates);

            //Incremental sync: fetch transactions from last sync or default to 7 days ago
            $transactionStartDate = isset($receipt_account->options['amazon_transactions_synced_at'])
                ? Carbon::parse($receipt_account->options['amazon_transactions_synced_at'])
                : Carbon::now()->subDays(7);

            $path = '/reconciliation/2021-01-08/transactions';
            $params = [
                'feedStartDate' => $transactionStartDate->toIso8601String(),
                'feedEndDate' => Carbon::now()->toIso8601String(),
            ];

            $full_url = $url.$path.'?'.http_build_query($params);
            $request = new \GuzzleHttp\Psr7\Request('GET', $full_url);
            //Intialize the signer.
            $s4 = new \Aws\Signature\SignatureV4('execute-api', 'us-east-1');
            //Build the signed request using the Credentials object. This is required in order to authenticate the call.
            $signedRequest = $s4->signRequest($request, $credentials);
            //Send the (signed) API request.
            $response = $client->send($signedRequest);

            $transactions = collect(json_decode($response->getBody()->getContents(), true));
            $transactions = collect($transactions['transactions'])->where('transactionType', '!=', 'CHARGE');

            foreach ($transactions as $transaction) {
                $order_date = Carbon::create($transaction['transactionDate'])->format('Y-m-d');
                $order_id = $transaction['transactionLineItems'][0]['orderId'];
                // $invoice_numbers = [];
                // foreach($transaction['transactionLineItems'] as $key => $line_item){
                //     $invoice_numbers[$key]['orderId'] = $line_item['orderId'];
                //     $invoice_numbers[$key]['orderLineItemId'] = $line_item['orderLineItemId'];
                //     $invoice_numbers[$key]['shipmentId'] = $line_item['shipmentId'];
                // }
                // dd($invoice_numbers);
                //check for expense duplicates
                // dd($transaction);

                $duplicates =
                    Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)->
                        where('vendor_id', 54)-> //54 = AMAZON
                        whereNull('deleted_at')->
                        where('invoice', $order_id)->
                        // where('amount', $order['orderNetTotal']['amount'])->
                        where('amount', 'LIKE', '-%')->
                        where('date', $order_date)->
                        get();

                //7-17-2023 duplicate by Invoice/ Order # only... see if Order status changed
                if ($duplicates->isEmpty()) {
                    //create expense Model
                    //CREATE expense
                    $refundAmount = (float) $transaction['amount']['amount'];
                    $bulkMatch = TransactionBulkMatch::findMatchForAmount(54, $refundAmount);
                    $expense = Expense::create([
                        'amount' => '-'.$transaction['amount']['amount'],
                        'date' => $order_date,
                        'project_id' => null,
                        'distribution_id' => $bulkMatch?->distribution_id,
                        'created_by_user_id' => 0, //automated
                        'invoice' => $order_id,
                        'vendor_id' => 54, //54 = AMAZON
                        'note' => null,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                    ]);
                    $bulkMatch?->applySplits($expense, $refundAmount);

                    //find associated expense and link
                    $associated =
                        Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)->
                            where('vendor_id', 54)-> //54 = AMAZON
                            whereNull('deleted_at')->
                            where('invoice', $order_id)->
                            // where('amount', $order['orderNetTotal']['amount'])->
                            where('amount', 'NOT LIKE', '-%')->
                            // where('date', $order_date)->
                            first();

                    if ($associated) {
                        $associated->parent_expense_id = $expense->id;
                        $associated->save();
                    }

                    //create expense_receipt_data
                    //ITEMS
                    $items = [];
                    foreach ($transaction['transactionLineItems'] as $transaction_key => $item) {
                        $items[$transaction_key]['Price'] = $item['principalAmount']['amount'];
                        $items[$transaction_key]['Quantity'] = $item['itemQuantity'];
                        $items[$transaction_key]['TotalPrice'] = $item['totalAmount']['amount'];
                        $items[$transaction_key]['Description'] = $item['productTitle'];
                        $items[$transaction_key]['ProductCode'] = $item['asin'];
                    }

                    //CHARGES
                    $charges = [];

                    $charges[0]['transactionDate'] = $order_date;
                    $charges[0]['transactionId'] = $transaction['transactionId'];
                    $charges[0]['amount'] = '-'.$transaction['amount']['amount'];
                    $charges[0]['paymentInstrumentLast4Digits'] = $transaction['paymentInstrumentLast4Digits'];

                    //items array!
                    $expense_receipt_data = [
                        'items' => $items,
                        'total' => '-'.$transaction['amount']['amount'],
                        'subtotal' => null,
                        'total_tax' => null,
                        'invoice_number' => $order_id,
                        'purchase_order' => $transaction['transactionLineItems'][0]['purchaseOrderNumber'],
                        'transaction_date' => $order_date,
                        'charges' => $charges,
                    ];

                    ExpenseReceipts::create([
                        'expense_id' => $expense->id,
                        'receipt_html' => null,
                        'receipt_items' => $expense_receipt_data,
                        'receipt_filename' => null,
                    ]);
                } else {
                    // $expense = $duplicates->first();

                    // if($expense->amount != '-' . $transaction['amount']['amount']){
                    //     $expense->amount = '-' . $transaction['amount']['amount'];
                    //     $expense->save();
                    // }else{

                    // }
                    continue;
                }
            }

            //Update transactions sync timestamp after successful processing
            $receipt_account->update([
                'options->amazon_transactions_synced_at' => Carbon::now()->toIso8601String(),
            ]);

            sleep(1);
        }

        // Queue bulk match job to immediately process any new expenses with matching rules
        TransactionVendorBulkMatchJob::dispatch();
    }

    public function azure_document_model($doc_type, $ocr_path)
    {
        if ($doc_type == 'pdf') {
            //if $width under 180mm($width), prebuilt-receipt, otherwise if wider, use prebuilt-invoice
            $pdf = new Fpdi;
            $pdf->setSourceFile(storage_path($ocr_path));
            $pageId = $pdf->importPage(1);

            $width = $pdf->getTemplateSize($pageId)['width'];

            //$document_model = based on file dimensions. receipt vs invoice
            if ($width < 180) {
                $document_model = 'prebuilt-receipt';
            } else {
                $document_model = 'prebuilt-invoice';
            }
        } else {
            //12/13/23 if img file is invoice v/s receipt!
            $document_model = 'prebuilt-invoice';
        }

        return $document_model;
    }


    public function azure_docs_api($file_location, $document_model, $doc_type, string $logChannel = 'vendor_docs')
    {
        $file = Storage::disk('files')->get($file_location);

        if (empty($file)) {
            Log::channel($logChannel)->error('Azure DI: File is empty or could not be read', [
                'file_location' => $file_location,
                'document_model' => $document_model,
            ]);
            throw new \Exception('Azure Document Intelligence: file is empty or unreadable');
        }

        // Auto-detect actual file type from magic bytes and correct the doc_type if mismatched
        $header = substr($file, 0, 8);
        $detectedType = null;
        if (str_starts_with($header, '%PDF-')) {
            $detectedType = 'pdf';
        } elseif (str_starts_with($header, "\x89PNG")) {
            $detectedType = 'png';
        } elseif (str_starts_with($header, "\xFF\xD8\xFF")) {
            $detectedType = 'jpg';
        }

        if ($detectedType && strtolower($doc_type) !== $detectedType && !($detectedType === 'jpg' && strtolower($doc_type) === 'jpeg')) {
            Log::channel($logChannel)->warning('Azure DI: File content type mismatch, correcting', [
                'file_location' => $file_location,
                'declared_type' => $doc_type,
                'detected_type' => $detectedType,
            ]);
            $doc_type = $detectedType;
        }

        if (in_array(strtolower($doc_type), ['jpg', 'jpeg'])) {
            $doc_content_type = 'Content-Type: image/jpeg';
        } elseif (strtolower($doc_type) == 'pdf') {
            $doc_content_type = 'Content-Type: application/pdf';
        } elseif (strtolower($doc_type) == 'png') {
            $doc_content_type = 'Content-Type: image/png';
        }

        //start OCR
        $ch = curl_init();

        $azure_api_key = env('AZURE_DI_API_KEY');
        $azure_api_version = env('AZURE_DI_VERSION');

        curl_setopt($ch, CURLOPT_URL, 'https://'.env('AZURE_DI_ENDPOINT').'/documentintelligence/documentModels/'.$document_model.':analyze?api-version='.$azure_api_version);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            $doc_content_type,
            "Ocp-Apim-Subscription-Key: $azure_api_key",
        ]);

        $location_result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Validate curl execution succeeded
        if ($location_result === false) {
            Log::channel($logChannel)->error('Azure DI: curl POST failed', [
                'file_location' => $file_location,
                'document_model' => $document_model,
                'curl_error' => $curlError,
            ]);
            throw new \Exception('Azure Document Intelligence: POST request failed - ' . $curlError);
        }

        // Validate HTTP 202 Accepted (Azure DI returns 202 when analysis is accepted)
        if ($httpCode !== 202) {
            // Separate headers and body to log the error response
            $headerSize = strpos($location_result, "\r\n\r\n");
            $responseBody = $headerSize !== false ? substr($location_result, $headerSize + 4) : '';
            Log::channel($logChannel)->error('Azure DI: POST returned unexpected HTTP status', [
                'file_location' => $file_location,
                'document_model' => $document_model,
                'http_code' => $httpCode,
                'response_body' => substr($responseBody, 0, 2000),
                'file_size' => strlen($file),
            ]);
            throw new \Exception("Azure Document Intelligence: POST returned HTTP {$httpCode} instead of 202");
        }

        // Extract operation ID from the Operation-Location header
        $operation_location_id = null;
        if (preg_match('/Operation-Location:.*?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $location_result, $matches)) {
            $operation_location_id = $matches[1];
        }

        if (empty($operation_location_id)) {
            Log::channel($logChannel)->error('Azure DI: Could not extract operation ID from POST response headers', [
                'file_location' => $file_location,
                'document_model' => $document_model,
                'response_headers' => substr($location_result, 0, 2000),
            ]);
            throw new \Exception('Azure Document Intelligence: missing Operation-Location in POST response');
        }

        //get OCR result
        $uri = env('AZURE_DI_ENDPOINT').'/documentintelligence/documentModels/'.$document_model.'/analyzeResults/'.$operation_location_id.'?api-version='.$azure_api_version.'" -H "Ocp-Apim-Subscription-Key: '.$azure_api_key.'"';

        // Allow Azure time to register the analysis before first poll
        sleep(2);

        //wait but go as soon as done. Retry up to 60 times (~ 60 seconds).
        $maxRetries = 60;
        $retries = 0;
        $result = null;

        while ($retries < $maxRetries) {
            $result = exec('curl -v -X GET "https://'.$uri);
            $result = json_decode($result, true);

            // If the result is valid and analysis is complete (succeeded/failed), break out
            if (is_array($result) && isset($result['status']) && !in_array($result['status'], ['running', 'notStarted'])) {
                break;
            }

            // If the result is valid but still processing, just wait and retry
            if (is_array($result) && isset($result['status'])) {
                $retries++;
                sleep(1);
                continue;
            }

            // Invalid response (e.g. NotFound "Analyze result does not exist") — retry with backoff
            $retries++;
            if ($retries >= $maxRetries) {
                Log::channel($logChannel)->error('Azure API returned invalid response after max retries', [
                    'operation_location_id' => $operation_location_id,
                    'raw_result' => $result,
                    'retries' => $retries,
                ]);
                throw new \Exception('Azure Document Intelligence API returned invalid response');
            }

            Log::channel($logChannel)->info('Azure API result not ready yet, retrying', [
                'operation_location_id' => $operation_location_id,
                'retry' => $retries,
                'raw_result' => $result,
            ]);
            sleep(2);
        }

        return $result;
    }

    //send receipt location, document_model_type
    public function azure_receipts($ocr_path, $doc_type, $document_model)
    {
        $result = $this->azure_docs_api($ocr_path, $document_model, $doc_type, 'nylas');

        $all_fields = [];
        foreach ($result['analyzeResult']['documents'] as $document) {
            $all_fields = array_merge_recursive($all_fields, $document['fields']);
        }

        $result['analyzeResult']['document'] = $all_fields;

        return $result['analyzeResult'];
    }

    public function ocr_extract($ocr_receipt_extracted, $expense_amount = null, $email = null, ?Receipt $receipt = null)
    {
        if (isset($ocr_receipt_extracted['document'])) {
            $ocr_receipt_extract_prefix = $ocr_receipt_extracted['document'];
        } else {
            $ocr_receipt_data = [
                'error' => true,
            ];

            return $ocr_receipt_data;
        }

        if (isset($ocr_receipt_extracted['keyValuePairs'])) {
            $key_value_pairs = $ocr_receipt_extracted['keyValuePairs'];
            $key_value_pairs = collect(json_decode(json_encode($key_value_pairs)));
        }

        // ®
        $ocr_receipt_extracted['content'] = htmlentities($ocr_receipt_extracted['content']);
        //TIP AMOUNT
        // if(isset($ocr_receipt_extract_prefix['Tip'])){
        //     $tip_amount = $ocr_receipt_extract_prefix['Tip']['valueNumber'];
        // }else{
        //     $tip_amount = NULL;
        // }

        // dd($ocr_receipt_extracted);

        //HANDWRITTEN
        $handwritten_notes = [];
        if ($ocr_receipt_extracted['styles']) {
            foreach ($ocr_receipt_extracted['styles'] as $key => $handwritten) {
                if ($handwritten['isHandwritten'] == true && $handwritten['confidence'] > 0.6) {
                    foreach ($handwritten['spans'] as $span_key => $span) {
                        $offset = $handwritten['spans'][$span_key]['offset'];
                        $length = $handwritten['spans'][$span_key]['length'];
                        $handwritten_notes[] = substr($ocr_receipt_extracted['content'], $offset, $length);
                    }
                }
            }
        }

        //MERCHANT / VENDOR NAME
        if (isset($ocr_receipt_extract_prefix['MerchantName'])) {
            if (isset($ocr_receipt_extract_prefix['MerchantName']['valueString'])) {
                $merchant_name = $ocr_receipt_extract_prefix['MerchantName']['valueString'];
            } elseif ($ocr_receipt_extract_prefix['MerchantName']['content']) {
                $merchant_name = $ocr_receipt_extract_prefix['MerchantName']['content'];
            } else {
                $merchant_name = null;
            }
        } elseif (isset($ocr_receipt_extract_prefix['VendorName'])) {
            if (isset($ocr_receipt_extract_prefix['VendorName']['valueString'])) {
                $merchant_name = $ocr_receipt_extract_prefix['VendorName']['valueString'];
            } else {
                $merchant_name = null;
            }
        } else {
            $merchant_name = null;
        }

        $merchant_name = str_replace("\n", '', $merchant_name);

        //INVOICE NUMBER/ID
        if (isset($ocr_receipt_extract_prefix['InvoiceId'])) {
            $invoice_number = $ocr_receipt_extract_prefix['InvoiceId']['valueString'];
        } elseif (isset($ocr_receipt_extract_prefix['invoice_number'])) {
            $invoice_number = $ocr_receipt_extract_prefix['invoice_number'];
        } elseif (isset($ocr_receipt_extract_prefix['OrderNumber'])) {
            $invoice_number = $ocr_receipt_extract_prefix['OrderNumber']['valueString'];
        } else {
            $invoice_number = null;
        }

        //PO NUMBER / JOB NAME
        $purchaseOrder = trim($ocr_receipt_extract_prefix['PurchaseOrder']['valueString'] ?? '');
        $jobName       = trim($ocr_receipt_extract_prefix['JobName']['valueString'] ?? '');

        $values = array_filter([$purchaseOrder, $jobName], function ($value) {
            return $value !== '';
        });

        $purchase_order_number = count($values) > 1 ? implode(', ', $values) : implode('', $values);

        // Fallback: attempt PO extraction from receipt text when Azure doesn't map it.
        // Prefer receipt template options->po_regex when present, otherwise use a generic PO/Job pattern.
        if ($purchase_order_number === '' && isset($ocr_receipt_extracted['content']) && is_string($ocr_receipt_extracted['content'])) {
            $content = $ocr_receipt_extracted['content'];

            $poRegex = null;
            if ($receipt instanceof Receipt) {
                $poRegex = Arr::get($receipt->options ?? [], 'po_regex');
            }

            if (! is_string($poRegex) || $poRegex === '') {
                $poRegex = '/(?:PO\s*\/\s*JOB\s*NAME|PO\s*NUMBER|PO\s*#|P\.?O\.?\s*#?|JOB\s*NAME|PRO\s*JobName)\s*:\s*([^\r\n]{1,80})/i';
            }

            if (preg_match($poRegex, $content, $matches)) {
                $candidate = trim($matches[1] ?? $matches[0] ?? '');

                // Clean common trailing fragments
                $candidate = preg_replace('/\s{2,}.*/', '', $candidate) ?? $candidate;

                if ($candidate !== '') {
                    $purchase_order_number = $candidate;
                }
            }
        }
        
        //TOTAL TAX
        if (isset($ocr_receipt_extract_prefix['TotalTaxAmount'])) {
            $total_tax = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['TotalTaxAmount']);
        } elseif (isset($ocr_receipt_extract_prefix['TotalTax'])) {
            if (isset($ocr_receipt_extract_prefix['TotalTax']['valueCurrency'])) {
                $total_tax = $ocr_receipt_extract_prefix['TotalTax']['valueCurrency']['amount'];
            } elseif (isset($ocr_receipt_extract_prefix['TotalTax']['valueNumber'])) {
                $total_tax = $ocr_receipt_extract_prefix['TotalTax']['valueNumber'];
            } else {
                $total_tax = null;
            }
        } else {
            $total_tax = null;
        }

        //TRANSACTION DATE
        if (isset($ocr_receipt_extract_prefix['TransactionDate'])) {
            if (isset($ocr_receipt_extract_prefix['TransactionDate']['valueDate'])) {
                $transaction_date = $ocr_receipt_extract_prefix['TransactionDate']['valueDate'];
            } elseif (isset($ocr_receipt_extract_prefix['TransactionDate']['content'])) {
                $transaction_date = $ocr_receipt_extract_prefix['TransactionDate']['content'];
            } else {
                $transaction_date = null;
            }
        } elseif (isset($ocr_receipt_extract_prefix['DepartureDate'])) {
            $transaction_date = $ocr_receipt_extract_prefix['DepartureDate']['valueDate'];
        } elseif (isset($ocr_receipt_extract_prefix['InvoiceDate'])) {
            $transaction_date = $ocr_receipt_extract_prefix['InvoiceDate']['valueDate'] ?? null;
            //use analyze options for "Order Date" if no InvoiceDate...
        } elseif (isset($key_value_pairs)) {
            if (! $key_value_pairs->where('key.content', 'Order Date')->isEmpty()) {
                $transaction_date = $key_value_pairs->where('key.content', 'Order Date')->first()->value->content;
            } elseif (! $key_value_pairs->where('key.content', 'Completed Date:')->isEmpty()) {
                $transaction_date = $key_value_pairs->where('key.content', 'Completed Date:')->first()->value->content;
            } elseif (! $key_value_pairs->where('key.content', 'ORDER DATE')->isEmpty()) {
                $transaction_date = $key_value_pairs->where('key.content', 'ORDER DATE')->first()->value->content;
            } else {
                $transaction_date = null;
            }
        } else {
            $transaction_date = null;
        }

        //change year
        if ($transaction_date != null) {
            //if transaction date has letters
            if (is_array($transaction_date)) {
                $transaction_date = $transaction_date[0];
            }
            // $transaction_date = preg_replace("/[^0-9]/", "", $transaction_date);
            $transaction_date = Carbon::parse($transaction_date);
            if ($transaction_date->year < date('Y', strtotime('-8 years'))) {
                $transaction_date = $transaction_date->year(now()->year);
            }

            $transaction_date = $transaction_date->format('Y-m-d');
        } else {
            if($expense_amount){
                $transaction_date = NULL;
            }else{
                $ocr_receipt_data = [
                    'error' => true,
                ];

                return $ocr_receipt_data;
            }

            //if coming from creating email, allow $transaction_date to be NULL.

            //if coming from UPDATE EXPENSE ... allow.... otherwire deny.
            // if($email == NULL){
            //     $ocr_receipt_data = [
            //         'error' => true,
            //     ];

            //     return $ocr_receipt_data;
            // }
        }

        //SUBTOTAL
        if (isset($ocr_receipt_extract_prefix['SubtotalAmount'])) {
            $subtotal = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['SubtotalAmount']);
        } elseif (isset($ocr_receipt_extract_prefix['SubTotal'])) {
            $subtotal = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['SubTotal']);
        } elseif (isset($ocr_receipt_extract_prefix['Subtotal'])) {
            $subtotal = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['Subtotal']);
        } else {
            $subtotal = null;
        }

        //ITEMS
        if (isset($ocr_receipt_extract_prefix['Items'])) {
            $items = $ocr_receipt_extract_prefix['Items']['valueArray'] ?? [];
        } elseif (isset($ocr_receipt_extract_prefix['LineItems'])) {
            $items = $ocr_receipt_extract_prefix['LineItems']['valueArray'] ?? [];
        } else {
            $items = [];
        }

        if (! empty($items)) {

            $formatted_items = [];
            foreach($items as $key => $line_item) {
                // Guard against unexpected shapes
                if (!isset($line_item['valueObject']) || !is_array($line_item['valueObject'])) {
                    continue;
                }

                $line = $line_item['valueObject'];

                $formatted_items[$key]['Description'] = $line['Description']['valueString'] ?? null;
                $formatted_items[$key]['ProductCode'] = $this->sanitizeProductCode($line['ProductCode']['valueString'] ?? null);

                // TotalPrice / Amount with robust fallbacks
                if (isset($line['TotalPrice'])) {
                    $formatted_items[$key]['TotalPrice'] = $this->extractCurrencyAmount($line['TotalPrice']);
                } elseif (isset($line['TotalAmount'])) {
                    $formatted_items[$key]['TotalPrice'] = $this->extractCurrencyAmount($line['TotalAmount']);
                } elseif (isset($line['Amount'])) {
                    $formatted_items[$key]['TotalPrice'] = $this->extractCurrencyAmount($line['Amount']);
                } else {
                    $formatted_items[$key]['TotalPrice'] = null;
                }

                if (isset($line['Quantity'])) {
                    $formatted_items[$key]['Quantity'] = $line['Quantity']['valueNumber'] ?? $this->extractCurrencyAmount($line['Quantity']);
                } else {
                    $formatted_items[$key]['Quantity'] = 1;
                }

                // price each with fallbacks
                if (isset($line['Price'])) {
                    $formatted_items[$key]['Price'] = $this->extractCurrencyAmount($line['Price']);
                } elseif (isset($line['UnitPrice'])) {
                    $formatted_items[$key]['Price'] = $this->extractCurrencyAmount($line['UnitPrice']);
                } else {
                    $formatted_items[$key]['Price'] = $formatted_items[$key]['TotalPrice'];
                }
            }
        } else {
            $formatted_items = null;
        }

        //AMOUNT
        $amount = NULL;
        if (isset($ocr_receipt_extract_prefix['TotalAmount'])) {
            $amount = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['TotalAmount']);
        } elseif (isset($ocr_receipt_extract_prefix['Total'])) {
            $amount = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['Total']);
        } elseif (isset($ocr_receipt_extract_prefix['InvoiceTotal'])) {
            $amount = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['InvoiceTotal']);
        } elseif (isset($ocr_receipt_extract_prefix['SubTotal']) && isset($ocr_receipt_extract_prefix['TotalTax'])) {
            $amount = (float) ($this->extractCurrencyAmount($ocr_receipt_extract_prefix['SubTotal']) ?? 0) + (float) ($this->extractCurrencyAmount($ocr_receipt_extract_prefix['TotalTax']) ?? 0);
        } elseif (isset($ocr_receipt_extract_prefix['SubtotalAmount']) && isset($ocr_receipt_extract_prefix['TotalTaxAmount'])) {
            $amount = (float) ($this->extractCurrencyAmount($ocr_receipt_extract_prefix['SubtotalAmount']) ?? 0) + (float) ($this->extractCurrencyAmount($ocr_receipt_extract_prefix['TotalTaxAmount']) ?? 0);
        } elseif (isset($key_value_pairs)) {
            if (! $key_value_pairs->where('key.content', 'Authorized Amount:')->isEmpty()) {
                $amount = $key_value_pairs->where('key.content', 'Authorized Amount:')->first()->value->content;
            }
        }

        if ($amount === NULL && is_array($formatted_items)) {
            $lineItemsTotal = $this->sumLineItemTotals($formatted_items);
            if (! is_null($lineItemsTotal)) {
                $amount = $lineItemsTotal;
            }
        }

        $contentTotal = null;
        if (isset($ocr_receipt_extracted['content']) && is_string($ocr_receipt_extracted['content'])) {
            $contentTotal = $this->extractTotalFromContent($ocr_receipt_extracted['content']);
        }

        if ($amount === NULL && ! is_null($contentTotal)) {
            $amount = $contentTotal;
        }

        if (! is_null($contentTotal) && ! is_null($amount)) {
            if ($amount < 1 || $contentTotal > ($amount * 10)) {
                $amount = $contentTotal;
            }
        }

        if ($amount === NULL) {
            //if coming from ExpensesNewForm, allow $amount above to be empty.
            if (! is_null($expense_amount)) {
                $amount = $expense_amount;
            }
        }

        if ($amount === NULL && is_null($subtotal)) {
            $ocr_receipt_data = [
                'error' => true,
            ];

            return $ocr_receipt_data;
        } else {
            // Normalize values to ensure they're floats, not arrays
            if (is_array($amount)) {
                $amount = $amount[0];
            }
            
            if (is_array($subtotal)) {
                $subtotal = $subtotal[0];
            }
            
            if (is_array($total_tax)) {
                $total_tax = $total_tax[0];
            }
            
            // Calculate amount if null
            if ($amount === NULL && (!is_null($subtotal) && !is_null($total_tax))) {
                $amount = $subtotal + $total_tax;
            }
        }

        if (empty($total_tax) && empty($subtotal) && isset($amount)) {
            $subtotal = $amount;
        }

        if (! is_null($amount) && ! is_null($subtotal) && $subtotal < 1 && $amount >= 1 && empty($total_tax)) {
            $subtotal = $amount;
        }

        // Fix OCR error: if subtotal equals total but tax exists, determine which field to correct
        if (!is_null($subtotal) && !is_null($amount) && !is_null($total_tax) && 
            $subtotal == $amount && $total_tax > 0) {
            
            // If expense_amount is provided, use it to determine which field is correct
            if (!is_null($expense_amount)) {
                $expenseAmount = (float) $expense_amount;
                $totalMatchesExpense = abs($amount - $expenseAmount) < 0.01;
                
                if ($totalMatchesExpense) {
                    // Total is correct, fix subtotal: subtotal = total - tax
                    $subtotal = $amount - $total_tax;
                } else {
                    // Subtotal is correct, fix total: total = subtotal + tax
                    $amount = $subtotal + $total_tax;
                }
            } else {
                // No expense amount to compare against, assume subtotal is correct
                $amount = $subtotal + $total_tax;
            }
        }

        $ocr_receipt_data = [
            'content' => $ocr_receipt_extracted['content'],
            'fields' => [
                'items' => $formatted_items,
                'subtotal' => $subtotal,
                'total' => $amount,
                'total_tax' => $total_tax,
                'transaction_date' => $transaction_date,
                'merchant_name' => $merchant_name,
                'invoice_number' => $invoice_number,
                'merchant_name' => $merchant_name,
                'purchase_order' => $purchase_order_number,
                'handwritten_notes' => $handwritten_notes,
            ],
        ];

        return $ocr_receipt_data;
    }

    /**
     * Safely extract a currency/number amount from a flexible OCR field shape.
     * Accepts arrays like ['valueCurrency' => ['amount' => 12.34]],
     * ['valueNumber' => 12.34], ['valueString' => '$12.34'], ['content' => '12,34'],
     * or raw numeric/string values.
     */
    private function extractCurrencyAmount(mixed $field): ?float
    {
        if (is_array($field)) {
            if (isset($field['valueObject']) && is_array($field['valueObject'])) {
                $valueObject = $field['valueObject'];

                if (isset($valueObject['Amount'])) {
                    return $this->extractCurrencyAmount($valueObject['Amount']);
                }

                if (isset($valueObject['amount'])) {
                    return $this->extractCurrencyAmount($valueObject['amount']);
                }
            }

            if (isset($field['Amount'])) {
                return $this->extractCurrencyAmount($field['Amount']);
            }

            if (isset($field['amount'])) {
                return $this->extractCurrencyAmount($field['amount']);
            }

            if (isset($field['valueCurrency']['amount'])) {
                return (float) $field['valueCurrency']['amount'];
            }

            if (isset($field['valueNumber'])) {
                return is_numeric($field['valueNumber']) ? (float) $field['valueNumber'] : $this->parseAmountFromString((string) $field['valueNumber']);
            }

            if (isset($field['valueString'])) {
                return $this->parseAmountFromString((string) $field['valueString']);
            }

            if (isset($field['content'])) {
                return $this->parseAmountFromString((string) $field['content']);
            }
        } elseif (is_numeric($field)) {
            return (float) $field;
        } elseif (is_string($field)) {
            return $this->parseAmountFromString($field);
        }

        return null;
    }

    private function sumLineItemTotals(array $items): ?float
    {
        $total = 0.0;
        $found = false;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lineTotal = $item['TotalPrice'] ?? null;
            if (is_numeric($lineTotal)) {
                $total += (float) $lineTotal;
                $found = true;
            }
        }

        return $found ? $total : null;
    }

    private function extractTotalFromContent(string $content): ?float
    {
        $patterns = [
            '/\b(?:TOTAL|AMOUNT\s+DUE|AMOUNT|BALANCE|CHARGE|PAYMENT)\b[^0-9]{0,20}(\d{1,3}(?:,\d{3})*(?:\.\d{2}))/i',
            '/\b(\d{1,3}(?:,\d{3})*(?:\.\d{2}))\s+(?:master\s*card|visa|amex|discover|card|debit|credit|mc)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches) && ! empty($matches[1])) {
                $candidate = $matches[1][count($matches[1]) - 1] ?? null;
                if (is_string($candidate)) {
                    $amount = $this->parseAmountFromString($candidate);
                    if (! is_null($amount)) {
                        return $amount;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse a numeric amount from a string, handling commas, currency symbols,
     * and parentheses for negatives.
     */
    private function parseAmountFromString(string $value): ?float
    {
        $value = trim($value);
        $negative = false;

        // Handle parentheses indicating negatives, e.g., (12.34)
        if (preg_match('/^\((.*)\)$/', $value, $m)) {
            $negative = true;
            $value = $m[1];
        }

        // Strip everything except digits, comma, dot, and minus
        $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '';

        if ($value === '' || $value === '-' || $value === '.') {
            return null;
        }

        // If both comma and dot present, assume comma is thousands sep
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace(',', '', $value);
        } elseif (str_contains($value, ',') && ! str_contains($value, '.')) {
            // If only comma present, assume it's the decimal separator
            $value = str_replace(',', '.', $value);
        } else {
            // No commas or only dot: ensure no stray commas
            $value = str_replace(',', '', $value);
        }

        $number = (float) $value;
        return $negative ? -$number : $number;
    }

    /**
     * Sanitize a ProductCode extracted from OCR.
     * Removes trailing suffixes like "-4.5" that are incorrectly appended by OCR.
     * Product codes should only contain digits (UPC/EAN barcodes).
     */
    private function sanitizeProductCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $code = trim($code);

        // Remove anything after a hyphen (e.g., "081099015861-4.5" → "081099015861")
        if (str_contains($code, '-')) {
            $code = explode('-', $code)[0];
        }

        // Remove any non-digit characters (barcodes are numeric)
        $code = preg_replace('/[^0-9]/', '', $code);

        return $code !== '' ? $code : null;
    }

    //1-18-2023 combine the next 2 functions into one. Pass type = original or temp
    //Show full-size receipt to anyone with a link
    public function original_receipt($folder, $filename)
    {
        // Build candidate paths preserving case and with common fallbacks
        $candidates = [
            $filename,
            strtolower($filename),
            strtoupper($filename),
        ];

        $resolvedPath = null;
        foreach ($candidates as $name) {
            $try = storage_path('files/'.$folder.'/'.$name);
            if (file_exists($try)) {
                $resolvedPath = $try;
                $filename = $name; // normalize for extension checks
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

    public function temp_receipt($filename)
    {
        $path = storage_path('files/_temp_ocr/'.$filename);

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
