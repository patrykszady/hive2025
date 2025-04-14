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
use Microsoft\Graph\Graph;
use Microsoft\Graph\Http;
use Microsoft\Graph\Model;
use Microsoft\Graph\Model\Attachment;
use Microsoft\Graph\Model\MailFolder;
use Microsoft\Graph\Model\Message;
use Nesk\Puphpeteer\Puppeteer;
use setasign\Fpdi\Fpdi;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class ReceiptController extends Controller
{
    public function verifyWorkersComp()
    {
        $puppeteer = new Puppeteer;
        $browser = $puppeteer->launch();

        $page = $browser->newPage();
        $page->goto('https://google.com');
        $page->screenshot(['path' => 'example.png']);

        $browser->close();

        dd('saved');
        // Example usage
        $employerName = 'Faza';
        // $results = verifyWorkersComp($employerName);

        // foreach ($results as $result) {
        //     echo $result . PHP_EOL;
        // }
        // $url = ;

        $client = new Client;
        $url = 'http://www.ewccv.com/cvs/'; // Replace with the URL you want to fetch

        // Send a GET request to the URL
        $response = $client->get($url);
        // dd($response->getBody());
        // Get the HTML content of the response
        $htmlContent = (string) $response->getBody();
        print_r($htmlContent);
        dd();

        $puppeteer = new Puppeteer;
        $browser = $puppeteer->launch();
        $page = $browser->newPage();
        $page->goto('https://www.homedepotrebates11percent.com/#/home');
        $page->waitForTimeout(500);
        $page->screenshot(['path' => 'example.png']);
        dd('here');

        $client = new Client;
        // Replace with the actual URL

        // Send a POST request to the search form
        $response = $client->post($url, [
            'form_params' => [
                'employer' => $employerName, // Replace with the actual form field name
            ],
        ]);

        // Get the HTML content of the response
        $html = (string) $response->getBody();

        // Parse the HTML using Symfony DOMCrawler
        $crawler = new Crawler($html);

        // Extract relevant information from the results
        $results = $crawler->filter('.result-class')->each(function (Crawler $node, $i) { // Replace with the actual CSS selector
            return $node->text();
        });

        dd($results);

        return $results;
    }

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

                    //add to $company_email json ('api') errors
                    Log::channel('company_emails_login_error')->error($error);

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

            //7-17-2023 find last amazon expenses date
            // '2023-10-14', '2023-10-14'
            // $dates = CarbonPeriod::create(Carbon::today()->subDays(14)->setTimezone('UTC'), Carbon::today()->setTimezone('UTC'));
            $dates = CarbonPeriod::create(Carbon::today()->subDays(14)->setTimezone('UTC'), Carbon::today()->setTimezone('UTC'));

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

                foreach ($orders as $orders_key => $order) {
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
                            $items->charges = $charges;

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

            $path = '/reconciliation/2021-01-08/transactions';
            $params = [
                'feedStartDate' => Carbon::now()->subDays(60)->toIso8601String(),
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

            sleep(1);
            // usleep(500000);
        }
    }

    //foreach outlook/microsoft email get and process message...
    public function ms_graph_email_api()
    {
        foreach ($company_emails as $company_email) {
            //move message here...
            $move_type = $this->create_expense_from_email($company_email, $message, $receipt_account, $receipt, $receipt_html_main, $email_date, $image_email_url);
            if ($move_type == 'duplicate') {
                //move to duplicate folder
                $this->ms_graph->createRequest('POST', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId().'/move')
                    ->attachBody(
                        [
                            //1-17-2023 or is send to "receipts@hive.contractors? .. Remove...
                            'destinationId' => $company_email->api_json['hive_folder_duplicate'],
                        ]
                    )
                    ->execute();

                continue;
            } elseif ($move_type == 'error') {
                $this->ms_graph->createRequest('POST', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId().'/move')
                    ->attachBody(
                        [
                            'destinationId' => $company_email->api_json['hive_folder_error'],
                        ]
                    )
                    ->execute();

                Log::channel('ms_message_error_folder')->error((array) $message);

                continue;
            } else {
                //move email to Saved folder
                $this->ms_graph->createRequest('POST', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId().'/move')
                    ->attachBody(
                        [
                            //1-17-2023 or is send to "receipts@hive.contractors? .. Remove...
                            'destinationId' => $company_email->api_json['hive_folder_saved'],
                        ]
                    )
                    ->execute();

                continue;
            }
        } //foreach messages
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
        //['jpg', 'jpeg] ?
        if (strtolower($doc_type) == 'jpg') {
            $doc_content_type = 'Content-Type: image/jpeg';
        } elseif (strtolower($doc_type) == 'pdf') {
            $doc_content_type = 'Content-Type: application/pdf';
        } elseif (strtolower($doc_type) == 'png') {
            $doc_content_type = 'Content-Type: image/png';
        } else {
            //Should never be here. VendorDocCreate validates: file must be pdf, jpg, png
        }

        $file = Storage::disk('files')->get($file_location);
        // $file = file_get_contents(storage_path($file_location));
        //start OCR
        $ch = curl_init();

        $azure_api_key = env('AZURE_DI_API_KEY');
        $azure_api_version = env('AZURE_DI_VERSION');

        curl_setopt($ch, CURLOPT_URL, 'https://'.env('AZURE_DI_ENDPOINT').'/documentintelligence/documentModels/'.$document_model.':analyze?api-version='.$azure_api_version.'&features=queryFields&queryFields=PurchaseOrder,JobName');
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
        // dd($matches);
        $operation_location_id = $matches[0][0];

        //get OCR result
        //&pages=[1]d
        $uri = env('AZURE_DI_ENDPOINT').'/documentintelligence/documentModels/'.$document_model.'/analyzeResults/'.$operation_location_id.'?api-version='.$azure_api_version.'" -H "Ocp-Apim-Subscription-Key: '.$azure_api_key.'"';
        $result = exec('curl -v -X GET "https://'.$uri);
        $result = json_decode($result, true);

        //2024-12-25 ..if $result is error...LOG and inform user

        //wait but go as soon as done.
        while ($result['status'] == 'running' || $result['status'] == 'notStarted') {
            sleep(1);
            $result = exec('curl -v -X GET "https://'.$uri);
            $result = json_decode($result, true);
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
            $transaction_date = $ocr_receipt_extract_prefix['InvoiceDate']['valueDate'];

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
            $subtotal = $ocr_receipt_extract_prefix['SubTotal']['valueCurrency']['amount'];
        } elseif (isset($ocr_receipt_extract_prefix['Subtotal'])) {
            if (isset($ocr_receipt_extract_prefix['Subtotal']['valueCurrency'])) {
                $subtotal = $ocr_receipt_extract_prefix['Subtotal']['valueCurrency']['amount'];
            } else {
                $subtotal = null;
            }
        } else {
            $subtotal = null;
        }

        //ITEMS
        if(isset($ocr_receipt_extract_prefix['Items'])){
            $items = $ocr_receipt_extract_prefix['Items']['valueArray'];

            $formatted_items = [];
            foreach($items as $key => $line_item) {
                // if($key == 1){
                // dd($line_item['valueObject']);
                $formatted_items[$key]['Description'] = $line_item['valueObject']['Description']['valueString'] ?? null;
                $formatted_items[$key]['ProductCode'] = $line_item['valueObject']['ProductCode']['valueString'] ?? null;

                if(isset($line_item['valueObject']['TotalPrice'])){
                    $formatted_items[$key]['TotalPrice'] = $line_item['valueObject']['TotalPrice']['valueCurrency']['amount'];
                }elseif(isset($line_item['valueObject']['Amount'])){
                    $formatted_items[$key]['TotalPrice'] = $line_item['valueObject']['Amount']['valueCurrency']['amount'];
                }else{
                    $formatted_items[$key]['TotalPrice'] = NULL;
                }

                if (isset($line_item['valueObject']['Quantity'])) {
                    $formatted_items[$key]['Quantity'] = $line_item['valueObject']['Quantity']['valueNumber'];
                } else {
                    $formatted_items[$key]['Quantity'] = 1;
                }

                //price each
                if(isset($line_item['valueObject']['Price'])){
                    $formatted_items[$key]['Price'] = $line_item['valueObject']['Price']['valueCurrency']['amount'];
                }elseif(isset($line_item['valueObject']['UnitPrice'])){
                    $formatted_items[$key]['Price'] = $line_item['valueObject']['UnitPrice']['valueCurrency']['amount'];
                }else{
                    $formatted_items[$key]['Price'] = $formatted_items[$key]['TotalPrice'];
                }
                // }
                // if(isset($line_item['valueObject']['Quantity'])){
                //     if($key == 1){
                //         $quantity = $line_item['valueObject']['Quantity']['valueNumber'];

                //         if(isset($line_item['valueObject']['Price']['valueNumber'])){
                //             $line_item_price = $line_item['valueObject']['Price']['valueNumber'];
                //         }elseif(isset($line_item['valueObject']['UnitPrice'])){
                //             $line_item_price = $line_item['valueObject']['UnitPrice']['valueCurrency']['amount'];
                //         }else{
                //             $line_item_price = 0;
                //         }

                //         if(isset($line_item['valueObject']['TotalPrice'])){
                //             $total_price = $line_item['valueObject']['TotalPrice']['valueCurrency']['amount'];
                //         }elseif(isset($line_item['valueObject']['Amount'])){
                //             $total_price = $line_item['valueObject']['Amount']['valueCurrency']['amount'];
                //         }else{
                //             $total_price = 0;
                //         }

                //         if($line_item_price == "0" && $total_price == "0"){
                //             $items[$key]['valueObject']['TotalPrice']['valueCurrency']['amount'] = "0.00";
                //         }else{
                //             if($line_item_price != "0"){
                //                 $line_item_total = $quantity * $line_item_price;
                //                 if($line_item_total != $total_price){
                //                     $items[$key]['valueObject']['TotalPrice']['valueCurrency']['amount'] = $line_item_total;
                //                 }
                //             }
                //         }
                //     }
                // }
            }
        }else{
            $formatted_items = null;
        }

        //AMOUNT
        $amount = NULL;
        if (isset($ocr_receipt_extract_prefix['Total'])) {
            $amount = $ocr_receipt_extract_prefix['Total']['valueCurrency']['amount'];
        } elseif (isset($ocr_receipt_extract_prefix['InvoiceTotal'])) {
            $amount = $ocr_receipt_extract_prefix['InvoiceTotal']['valueCurrency']['amount'];
        } elseif (isset($ocr_receipt_extract_prefix['SubTotal']) && isset($ocr_receipt_extract_prefix['TotalTax'])) {
            $amount = $ocr_receipt_extract_prefix['SubTotal']['valueCurrency']['amount'] + $ocr_receipt_extract_prefix['TotalTax']['valueCurrency']['amount'];
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
            if (is_array($amount)) {
                $amount = $amount[0];
            } else {
                if ($amount === NULL && (!is_null($subtotal) && !is_null($total_tax))) {
                    $amount = $subtotal + $total_tax;
                }

                // else{
                //     $ocr_receipt_data = [
                //         'error' => true,
                //     ];

                //     return $ocr_receipt_data;
                // }
                // if(!is_null($tip_amount)){
                //     dd([$amount, $ocr_receipt_extract_prefix]);
                // }
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

    public function add_attachments_to_expense($expense_id, $message, $ocr_receipt_data, $ocr_filename)
    {
        $filename = $expense_id.'-'.$ocr_filename;

        if (! is_null($message)) {
            if ($message->getHasAttachments()) {
                $attachments =
                    $this->ms_graph->createRequest('GET', '/me/messages/'.$message->getId().'/attachments')
                        ->setReturnType(Attachment::class)
                        ->execute();
                //Add Email Attachments
                foreach ($attachments as $key => $attachment) {
                    if (in_array($attachment->getContentType(), ['application/pdf', 'application/octet-stream'])) {
                        $filename_attached = $expense_id.'-'.$key.'-'.$ocr_filename;
                        $content_bytes = array_values((array) $attachment)[0]['contentBytes'];
                        //file decoded
                        $contents = base64_decode($content_bytes);
                        Storage::disk('files')->put('/receipts/'.$filename_attached, $contents);

                        //SAVE expense_receipt_data for each attachment
                        $expense_receipt = new ExpenseReceipts;
                        $expense_receipt->expense_id = $expense_id;
                        $expense_receipt->receipt_filename = $filename_attached;
                        $expense_receipt->receipt_html = $ocr_receipt_data['content'];
                        $expense_receipt->receipt_items = $ocr_receipt_data['fields'];
                        $expense_receipt->save();
                    }
                }
            } else {
                //use created file from ocr
                //SAVE expense_receipt_data for each attachment
                $expense_receipt = new ExpenseReceipts;
                $expense_receipt->expense_id = $expense_id;
                $expense_receipt->receipt_filename = $filename;
                $expense_receipt->receipt_html = $ocr_receipt_data['content'];
                $expense_receipt->receipt_items = $ocr_receipt_data['fields'];
                $expense_receipt->save();
            }
        } else {
            //use created file from ocr
            //SAVE expense_receipt_data for each attachment
            $expense_receipt = new ExpenseReceipts;
            $expense_receipt->expense_id = $expense_id;
            $expense_receipt->receipt_filename = $filename;
            $expense_receipt->receipt_html = $ocr_receipt_data['content'];
            $expense_receipt->receipt_items = $ocr_receipt_data['fields'];
            $expense_receipt->save();
        }

        //move _temp_ocr file to /files/receipts
        Storage::disk('files')->move('/_temp_ocr/'.$ocr_filename, '/receipts/'.$filename);

        $complete = true;

        return $complete;
    }

    //1-18-2023 combine the next 2 functions into one. Pass type = original or temp
    //Show full-size receipt to anyone with a link
    // No Middleware or Policies
    //PUBLIC AS FUCK! BE CAREFUL!
    public function original_receipt($folder, $filename)
    {
        $filename = strtolower($filename);
        $path = storage_path('files/' . $folder . '/'.$filename);

        if (strtolower(File::extension($filename)) === 'pdf') {
            $response = Response::make(file_get_contents($path), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        } else {
            $response = Image::make($path)->response();
        }

        return $response;
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
