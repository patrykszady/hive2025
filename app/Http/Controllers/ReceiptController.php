<?php

namespace App\Http\Controllers;

use App\Models\CompanyEmail;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\Transaction;
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
                //Send the (signed) API request.
                $response = $client->send($signedRequest);
                $orders = collect(json_decode($response->getBody()->getContents(), true)['orders']);

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
                        $expense = Expense::create([
                            'amount' => $order['orderNetTotal']['amount'],
                            'date' => $order_date,
                            // /$receipt_account->project_id
                            'project_id' => null,
                            'distribution_id' => $receipt_account->distribution_id,
                            'created_by_user_id' => 0, //automated
                            'invoice' => $order['orderId'],
                            'vendor_id' => 54, //54 = AMAZON
                            'note' => null,
                            'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        ]);
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
                    $expense = Expense::create([
                        'amount' => '-'.$transaction['amount']['amount'],
                        'date' => $order_date,
                        // $receipt_account->project_id
                        'project_id' => null,
                        'distribution_id' => $receipt_account->distribution_id,
                        'created_by_user_id' => 0, //automated
                        'invoice' => $order_id,
                        'vendor_id' => 54, //54 = AMAZON
                        'note' => null,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                    ]);

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


    public function azure_docs_api($file_location, $document_model, $doc_type)
    {
        if (in_array(strtolower($doc_type), ['jpg', 'jpeg'])) {
            $doc_content_type = 'Content-Type: image/jpeg';
        } elseif (strtolower($doc_type) == 'pdf') {
            $doc_content_type = 'Content-Type: application/pdf';
        } elseif (strtolower($doc_type) == 'png') {
            $doc_content_type = 'Content-Type: image/png';
        }

        $file = Storage::disk('files')->get($file_location);

        //start OCR
        $ch = curl_init();

        $azure_api_key = env('AZURE_DI_API_KEY');
        $azure_api_version = env('AZURE_DI_VERSION');

        //,JobName
        // .'&features=queryFields&queryFields=PurchaseOrder'
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
        curl_close($ch);

        $re = '/(\d|\D){8}-(\d|\D){4}-(\d|\D){4}-(\d|\D){4}-(\d|\D){12}/m';
        $str = $location_result;
        preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE, 0);
        $operation_location_id = $matches[0][0];

        //get OCR result
        //&pages=[1]d
        $uri = env('AZURE_DI_ENDPOINT').'/documentintelligence/documentModels/'.$document_model.'/analyzeResults/'.$operation_location_id.'?api-version='.$azure_api_version.'" -H "Ocp-Apim-Subscription-Key: '.$azure_api_key.'"';
        $result = exec('curl -v -X GET "https://'.$uri);
        $result = json_decode($result, true);

        //2024-12-25 ..if $result is error...LOG and inform user
        if (!is_array($result) || !isset($result['status'])) {
            Log::channel('vendor_docs')->error('Azure API returned invalid response', [
                'operation_location_id' => $operation_location_id,
                'raw_result' => $result,
            ]);
            throw new \Exception('Azure Document Intelligence API returned invalid response');
        }

        //wait but go as soon as done.
        while ($result['status'] == 'running' || $result['status'] == 'notStarted') {
            sleep(1);
            $result = exec('curl -v -X GET "https://'.$uri);
            $result = json_decode($result, true);
            
            if (!is_array($result) || !isset($result['status'])) {
                Log::channel('vendor_docs')->error('Azure API polling returned invalid response', [
                    'operation_location_id' => $operation_location_id,
                    'raw_result' => $result,
                ]);
                throw new \Exception('Azure Document Intelligence API polling failed');
            }
        }

        return $result;
    }

    //send receipt location, document_model_type
    public function azure_receipts($ocr_path, $doc_type, $document_model)
    {
        $result = $this->azure_docs_api($ocr_path, $document_model, $doc_type);

        $all_fields = [];
        foreach ($result['analyzeResult']['documents'] as $document) {
            $all_fields = array_merge_recursive($all_fields, $document['fields']);
        }

        $result['analyzeResult']['document'] = $all_fields;

        return $result['analyzeResult'];
    }

    public function ocr_extract($ocr_receipt_extracted, $expense_amount = null, $email = null)
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

        //TOTAL TAX
        if (isset($ocr_receipt_extract_prefix['TotalTax'])) {
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
                $transaction_date = $transaction_date->year(now()->format('Y'));
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
        if (isset($ocr_receipt_extract_prefix['SubTotal'])) {
            $subtotal = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['SubTotal']);
        } elseif (isset($ocr_receipt_extract_prefix['Subtotal'])) {
            $subtotal = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['Subtotal']);
        } else {
            $subtotal = null;
        }

        //ITEMS
        if(isset($ocr_receipt_extract_prefix['Items'])){
            $items = $ocr_receipt_extract_prefix['Items']['valueArray'];

            $formatted_items = [];
            foreach($items as $key => $line_item) {
                // Guard against unexpected shapes
                if (!isset($line_item['valueObject']) || !is_array($line_item['valueObject'])) {
                    continue;
                }

                $line = $line_item['valueObject'];

                $formatted_items[$key]['Description'] = $line['Description']['valueString'] ?? null;
                $formatted_items[$key]['ProductCode'] = $line['ProductCode']['valueString'] ?? null;

                // TotalPrice / Amount with robust fallbacks
                if (isset($line['TotalPrice'])) {
                    $formatted_items[$key]['TotalPrice'] = $this->extractCurrencyAmount($line['TotalPrice']);
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
        }else{
            $formatted_items = null;
        }

        //AMOUNT
        $amount = NULL;
        if (isset($ocr_receipt_extract_prefix['Total'])) {
            $amount = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['Total']);
        } elseif (isset($ocr_receipt_extract_prefix['InvoiceTotal'])) {
            $amount = $this->extractCurrencyAmount($ocr_receipt_extract_prefix['InvoiceTotal']);
        } elseif (isset($ocr_receipt_extract_prefix['SubTotal']) && isset($ocr_receipt_extract_prefix['TotalTax'])) {
            $amount = (float) ($this->extractCurrencyAmount($ocr_receipt_extract_prefix['SubTotal']) ?? 0) + (float) ($this->extractCurrencyAmount($ocr_receipt_extract_prefix['TotalTax']) ?? 0);
        } elseif (isset($key_value_pairs)) {
            if (! $key_value_pairs->where('key.content', 'Authorized Amount:')->isEmpty()) {
                $amount = $key_value_pairs->where('key.content', 'Authorized Amount:')->first()->value->content;
            }
        } else {
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
