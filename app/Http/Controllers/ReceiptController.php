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

// use Storage;
// use Response;

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

    public function nylas_get_api($url_endpoint)
    {
        $guzzle = new Client;
        $url = 'https://api.us.nylas.com/v3/grants/'.$url_endpoint;
        $result = $guzzle->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.env('NYLAS_API_KEY'),
                'Content-Type' => 'application/json',
            ],
        ])->getBody()->getContents();

        return $result = json_decode($result, true);
    }

    public function nylas_errors($error)
    {
        Log::channel('nylas_connection_errors')->error($error, [auth()->user()->first(), auth()->user()->vendor]);

        if ($error['error_code'] == 'exists') {
            $error_message = $error['error_description'];
        } else {
            $error_message = 'There was an error connecting. Please try again and if issue continues contact us with error #'.$error['error_code'].' "'.$error['error_description'].'"';
        }

        session()->flash('error', $error_message);

        if (auth()->user()->vendor->registration['registered'] == false) {
            return redirect(route('vendor_registration', auth()->user()->vendor));
        } else {
            return redirect(route('company_emails.index'));
        }
    }

    public function nylas_login()
    {
        $url = 'https://api.us.nylas.com/v3/connect/auth';
        $params = [
            'client_id' => env('NYLAS_CLIENT_ID'),
            'redirect_uri' => env('NYLAS_REDIRECT_URI'),
            'response_type' => 'code',
            'access_type' => 'online',
        ];

        header('Location: '.$url.'?'.http_build_query($params));
    }

    public function nylas_auth_response()
    {
        if (isset(request()->query()['error'])) {
            $error = request()->query();

            return $this->nylas_errors($error);
        } else {
            $code = request()->query()['code'];

            try {
                $guzzle = new Client;
                $url = 'https://api.us.nylas.com/v3/connect/token';
                $nylas_account =
                    json_decode($guzzle->post($url, [
                        'form_params' => [
                            'client_id' => env('NYLAS_CLIENT_ID'),
                            'client_secret' => env('NYLAS_API_KEY'),
                            'grant_type' => 'authorization_code',
                            'code' => $code,
                            'redirect_uri' => env('NYLAS_REDIRECT_URI'),
                            'code_verifier' => 'nylas',
                        ],
                    ])
                        ->getBody()
                        ->getContents()
                    );
            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $responseBody = $response->getBody()->getContents();
                    $error = $responseBody;
                } else {
                    $error = $e->getMessage();
                }
                $error = json_decode($error, true);

                return $this->nylas_errors($error);
            }

            $existing_company_email = CompanyEmail::withoutGlobalScopes()->where('email', $nylas_account->email)->first();

            if ($existing_company_email) {
                $error = [
                    'error_code' => 'exists',
                    'error_description' => 'Email '.$nylas_account->email.' already connected.',
                ];

                return $this->nylas_errors($error);
            } else {
                //create or confirm existance of HIVE folder in mailbox...
                //HIVE_CONTRACTORS_RECEIPTS
                //grant_id = nylas mailbox ID
                $grant_id = $nylas_account->grant_id;
                $url_endpoint = $grant_id.'/folders';
                sleep(1);
                $result = $this->nylas_get_api($url_endpoint);
                // $trash_folder = collect($result['data'])->where('name', 'Deleted Items')->first();
                // ->where('parent_id', '!=', $trash_folder['id'])
                $hive_email_folder = collect($result['data'])->where('name', 'HIVE_CONTRACTORS_RECEIPTS')->first();

                //create HIVE_CONTRACTORS_RECEIPTS and subfolders
                $guzzle = new Client;
                $url = 'https://api.us.nylas.com/v3/grants/'.$grant_id.'/folders';

                //CREATE PARENT HIVE MAILBOX FOLDER
                try {
                    $result = $guzzle->post($url, [
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer '.env('NYLAS_API_KEY'),
                        ],
                        'json' => [
                            'name' => 'HIVE_CONTRACTORS_RECEIPTS',
                        ],
                    ])->getBody()->getContents();

                    $result = json_decode($result, true);
                    $mailbox_hive_folders['hive_folder'] = $result['data']['id'];
                } catch (RequestException $e) {
                    if ($e->hasResponse()) {
                        $response = $e->getResponse();
                        $responseBody = $response->getBody()->getContents();
                        $error = $responseBody;
                    } else {
                        $error = $e->getMessage();
                    }
                    $error = json_decode($error, true);
                    //if 409 / conflict / folder already exists .. continue
                    if (in_array(($error['error']['provider_error']['error']['code'] ?? null), ['ErrorFolderExists', '409'])) {
                        $url_endpoint = $grant_id.'/folders';
                        $hive_email_folder = $this->nylas_get_api($url_endpoint);

                        $mailbox_hive_folders['hive_folder'] = collect($hive_email_folder['data'])->where('name', 'HIVE_CONTRACTORS_RECEIPTS')->first()['id'];
                    } else {
                        $this->nylas_errors($error);
                    }
                }

                //$mailbox_hive_folders['hive_folder'] MUST BE SET BY NOW
                //LOG ERROR otherwise

                //create sub-HIVE folders in HIVE_CONTRACTORS_RECEIPTS mailbox...
                $sub_folders = ['Saved', 'Duplicate', 'Error', 'Add', 'Retry', 'Test', 'LEADS'];
                foreach ($sub_folders as $folder) {
                    //CREATE CHILD HIVE MAILBOX FOLDER $folder
                    try {
                        $result = $guzzle->post($url, [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json',
                                'Authorization' => 'Bearer '.env('NYLAS_API_KEY'),
                            ],
                            'json' => [
                                'name' => $nylas_account->provider == 'microsoft' ? $folder : 'HIVE_CONTRACTORS_RECEIPTS/'.$folder,
                                'parent_id' => $nylas_account->provider == 'microsoft' ? $mailbox_hive_folders['hive_folder'] : null,
                            ],
                        ])->getBody()->getContents();

                        $result = json_decode($result, true);
                        $mailbox_hive_folders['hive_folder_'.strtolower($folder)] = $result['data']['id'];
                    } catch (RequestException $e) {
                        if ($e->hasResponse()) {
                            $response = $e->getResponse();
                            $responseBody = $response->getBody()->getContents();
                            $error = $responseBody;
                        } else {
                            $error = $e->getMessage();
                        }
                        $error = json_decode($error, true);
                        //if 409 / conflict / folder already exists .. continue
                        if (in_array(($error['error']['provider_error']['error']['code'] ?? null), ['ErrorFolderExists', '409'])) {
                            if ($nylas_account->provider == 'microsoft') {
                                $url_endpoint = $grant_id.'/folders?parent_id='.$mailbox_hive_folders['hive_folder'];
                                $child_folders = $this->nylas_get_api($url_endpoint);

                                $mailbox_hive_folders['hive_folder_'.strtolower($folder)] = collect($child_folders['data'])->where('name', $folder)->first()['id'];
                            } else {
                                $url_endpoint = $grant_id.'/folders';
                                $hive_email_folder = $this->nylas_get_api($url_endpoint);

                                $mailbox_hive_folders['hive_folder_'.strtolower($folder)] = collect($hive_email_folder['data'])->where('name', 'HIVE_CONTRACTORS_RECEIPTS/'.$folder)->first()['id'];
                            }
                        } else {
                            $this->nylas_errors($error);
                        }
                    }
                }

                $api_data = [
                    'provider' => $nylas_account->provider,
                    'grant_id' => $grant_id,
                ];

                $api_data = array_merge($api_data, $mailbox_hive_folders);

                //6-8-2023 Unique only
                CompanyEmail::create([
                    'email' => $nylas_account->email,
                    'vendor_id' => auth()->user()->vendor->id,
                    'api_json' => $api_data,
                ]);

                if (auth()->user()->vendor->registration['registered'] == false) {
                    return redirect(route('vendor_registration', auth()->user()->vendor));
                } else {
                    return redirect(route('company_emails.index'));
                }
            }
        }
    }

    public function nylas_read_email_receipts()
    {
        //6-28-2023 catch forwarded messages where From is in database table company_emails
        $company_emails = CompanyEmail::withoutGlobalScopes()->whereNotNull('api_json->grant_id')->get();
        $from_email_receipts = Receipt::withoutGlobalScopes();

        foreach ($company_emails as $company_email) {
            //8-10-2024 where messages NOT READ by THIS API (create a message category)
            $url_endpoint = $company_email->api_json['grant_id'].'/messages?limit=5&in='.$company_email->api_json['hive_folder_test'];
            //$result['data'] = Messages
            $result = $this->nylas_get_api($url_endpoint)['data'];
            //->whereIn('from.email', ['HomeDepot@order.homedepot.com'])
            $messages = collect($result);
            $filtered_messages = $messages->map(function ($item) {
                return (object) $item;
            });

            $filtered_messages = $filtered_messages->whereIn('from.0.email', $from_email_receipts->pluck('from_address')->unique()->toArray());
            // dd($filtered_messages);
            foreach ($filtered_messages as $key => $message) {
                $email_from = $message->from[0]['email'];
                $email_from_domain = substr($email_from, strpos($email_from, '@'));
                $email_subject = $message->subject;
                $email_date =
                    Carbon::parse($message->date)
                        ->setTimezone('America/Chicago')
                        ->format('Y-m-d');
                // dd($email_date, $email_subject);
                $string = $message->body;

                // SHOW HTML RENDERED
                print_r($string);
                dd();

                //SHOW EMAIL TEXT
                // print_r(htmlspecialchars($string));
                // dd();
            }
        }
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
            // $path = '/reports/2021-01-08/orders/113-5551823-8417801/';

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

            $path = '/reports/2021-01-08/orders/';

            //7-17-2023 find last amazon expenses date
            // '2023-10-14', '2023-10-14'
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
        $company_emails = CompanyEmail::withoutGlobalScopes()->whereNotNull('api_json->user_id')->get();
        // $messages = app(\App\Http\Controllers\LeadController::class)->ms_graph_auth($company_emails);

        foreach ($company_emails as $company_email) {
            //check if access_token is expired, if so get new access_token and refresh_token
            //12-31-2024  + 01/26/205..should be a Service we can reuse? check
            try {
                $guzzle = new Client;
                $url = 'https://login.microsoftonline.com/'.env('MS_GRAPH_TENANT_ID').'/oauth2/v2.0/token';
                $email_account_tokens = json_decode($guzzle->post($url, [
                    'form_params' => [
                        'client_id' => env('MS_GRAPH_CLIENT_ID'),
                        'scope' => env('MS_GRAPH_USER_SCOPES'),
                        'refresh_token' => $company_email->api_json['refresh_token'],
                        'redirect_uri' => env('MS_GRAPH_REDIRECT_URI'),
                        'grant_type' => 'refresh_token',
                        'client_secret' => env('MS_GRAPH_SECRET_ID'),
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

                $company_email->api_json += ['errors' => json_decode($error, true)];
                $company_email->save();

                //add to $company_email json ('api') errors
                Log::channel('company_emails_login_error')->error($error);

                continue;
            }

            //json
            $api_data = $company_email->api_json;
            $api_data['access_token'] = $email_account_tokens->access_token;
            $api_data['refresh_token'] = $email_account_tokens->refresh_token;

            $company_email->update([
                'api_json' => $api_data,
            ]);

            $this->ms_graph = new Graph;
            $this->ms_graph->setAccessToken($company_email->api_json['access_token']);

            // FOLDER name Test etc
            // $user_hive_folder =
            //     $this->ms_graph->createCollectionRequest("GET", "/me/mailFolders?filter=displayName eq 'Home Depot Rebates'&expand=childFolders")
            //         ->setReturnType(MailFolder::class)
            //         ->execute();
            // dd($user_hive_folder);

            if (env('APP_ENV') === 'production') {
                //6-12-2023 6-27-2023 6-6-2024 exclude ones already read ... save $message->getId() to a (temp) database/log file?...
                $messages_inbox = $this->ms_graph->createCollectionRequest('GET', '/me/mailFolders/inbox/messages?top=20')
                    ->setReturnType(Message::class)
                    ->execute();

                $messages_inbox_retry = $this->ms_graph->createCollectionRequest('GET', '/me/mailFolders/'.$company_email->api_json['hive_folder'].'/childFolders/'.$company_email->api_json['hive_folder_retry'].'/messages?top=20')
                    ->setReturnType(Message::class)
                    ->execute();

                $messages = Arr::collapse([$messages_inbox, $messages_inbox_retry]);
            } else {
                //if array key exists
                if (isset($company_email->api_json['hive_folder_test'])) {
                    $messages = $this->ms_graph->createCollectionRequest('GET', '/me/mailFolders/'.$company_email->api_json['hive_folder'].'/childFolders/'.$company_email->api_json['hive_folder_test'].'/messages?top=20')
                        ->setReturnType(Message::class)
                        ->execute();
                } else {
                    continue;
                }
            }
            // dd($messages);

            foreach ($messages as $key => $message) {
                if (! isset($message->getToRecipients()[0])) {
                    continue;
                }

                $email_from = $message->getFrom()->getEmailAddress()->getAddress();
                $email_from_domain = substr($email_from, strpos($email_from, '@'));
                //find the right Receipt:: that belongs to this email....
                // $email_to = strtolower($message->getToRecipients()[0]['emailAddress']['address']);
                $email_subject = $message->getSubject();
                $email_date =
                    Carbon::parse($message->getReceivedDateTime())
                        ->setTimezone('America/Chicago')
                        ->format('Y-m-d');
                // dd($message);
                // dd([$email_from, $email_from_domain, $email_subject, $email_date]);
                //find the right Receipt:: that belongs to this email....
                $from_email_receipts = Receipt::withoutGlobalScopes()->where('from_address', $email_from)->orWhere('from_address', $email_from_domain)->get();

                if ($from_email_receipts->isEmpty()) {
                    //if Email is Forwaded
                    //6-28-2023 catch forwarded messages where To is in database table company_emails (forward to KNOWN business company_email FROM ANY email) (oR ViveVersa..)
                    //06-17-2023 forwarded/redirected emails? if HIVE doesnt find them? let users forward emails
                    //use $email_to = strtolower($message->getToRecipients()[0]['emailAddress']['address']);
                    $re = '/(From:<\/b> |To:<\/b> )([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/';
                    $string = $message->getBody()->getContent();
                    preg_match_all($re, $string, $matches, PREG_SET_ORDER, 0);
                    // dd($matches);

                    if (isset($matches[0][1])) {
                        $email_from = $matches[0][2];
                        $email_from_domain = substr($email_from, strpos($email_from, '@'));

                        $from_email_receipts = Receipt::withoutGlobalScopes()->where('from_address', $email_from)->orWhere('from_address', $email_from_domain)->get();
                    } else {
                        //continue... email not a Receipt
                        continue;
                    }
                }

                if($from_email_receipts->isNotEmpty()){
                    foreach ($from_email_receipts as $email_receipt) {
                        if (strpos($email_subject, $email_receipt->from_subject) !== false) {
                            $receipt = $email_receipt;
                        } else {
                            //continue... email Subject not a Receipt
                            //move the failed email?
                            continue;
                        }
                    }
                }else{
                    continue;
                }

                //if is_null $receit, move to add_receipt folder
                if (! isset($receipt)) {
                    //email not a Receipt
                    continue;
                }

                //NOTE: $receipt MUST be set by now
                $receipt_account =
                    ReceiptAccount::withoutGlobalScopes()
                        ->where('belongs_to_vendor_id', $company_email->vendor_id)
                        ->where('vendor_id', $receipt->vendor_id)
                        ->first();

                // dd($receipt_account);

                //missing receipt_account..receipt and companyemail exist but receipt/companyemail combo does not
                //1-17-2023 6-27-2023 YES!~ should still process without #receipt_account? right?
                if (is_null($receipt_account)) {
                    //move to Add sub_folder.
                    $this->ms_graph->createRequest('POST', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId().'/move')
                        ->attachBody(
                            [
                                'destinationId' => $company_email->api_json['hive_folder_add'],
                            ]
                        )
                        ->execute();

                    continue;
                }

                //getContent = HTML or TEXT
                $string = $message->getBody()->getContent();

                //remove images
                //ONLY IF {"receipt_image_regex":}  is NOT set
                if (! isset($receipt->options['receipt_image_regex'])) {
                    $string = preg_replace("/<img[^>]+\>/i", '', $string);
                } else {
                    //FIND receipt email image in email html (eg. Floor and Decor)
                    $re = $receipt->options['receipt_image_regex'];
                    $str = $string;
                    preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE, 0);

                    //iamge/receipt url
                    $image_email_url = $matches[1][0];

                    //6-27-2023 error if cant find
                }

                // SHOW HTML RENDERED
                // print_r($string);
                // dd();

                //SHOW EMAIL TEXT
                // print_r(htmlspecialchars($string));
                // dd();

                if (isset($receipt->options['receipt_start'])) {
                    //if receipt_start = array
                    //if false, look for next.
                    if (is_array($receipt->options['receipt_start'])) {
                        foreach ($receipt->options['receipt_start'] as $key => $receipt_start_text) {
                            $receipt_start = strpos($string, $receipt_start_text);

                            if (is_numeric($receipt_start)) {
                                $receipt_start_text = $receipt_start_text;
                                break;
                            }
                        }
                    } else {
                        $receipt_start = strpos($string, $receipt->options['receipt_start']);
                        $receipt_start_text = $receipt->options['receipt_start'];
                    }

                    //include the "receipt_start" text or start receipt_html after the text
                    if (isset($receipt->options['receipt_start_offset'])) {
                        $receipt_start = strpos($string, $receipt_start_text) + strlen($receipt_start_text);
                    }
                } else {
                    $receipt_start = 0;
                }

                if (isset($receipt->options['receipt_end'])) {
                    //if receipt_end = array
                    //if false, look for next.
                    if (is_array($receipt->options['receipt_end'])) {
                        foreach ($receipt->options['receipt_end'] as $key => $receipt_end_text) {
                            $receipt_end = strpos($string, $receipt_end_text, $receipt_start);

                            if (is_numeric($receipt_end)) {
                                break;
                            }
                        }
                    } else {
                        $receipt_end = strpos($string, $receipt->options['receipt_end'], $receipt_start);
                    }

                    //if receipt_end = null, use last character of $string
                } else {
                    $receipt_end = strlen($string);
                }

                $receipt_position = $receipt_end - $receipt_start;
                $receipt_html_main = substr($string, $receipt_start, $receipt_position);

                //1-26-23 remove receipt text in the middle (Amazon)
                //1-28-23 multiple removals? foreach receipt_middle_texts?
                if (isset($receipt->options['receipt_middle_text'])) {
                    $re = $receipt->options['receipt_middle_text'];
                    $str = $string;
                    preg_match($re, $str, $matches);

                    if (! empty($matches)) {
                        $receipt_html_main = str_replace($matches[1], '', $receipt_html_main);
                    }
                }

                //PREVIEWS HTML RECEIPT
                // print_r($receipt_html_main);
                // dd();

                //create Expense
                if (! isset($image_email_url)) {
                    $image_email_url = null;
                }

                $move_type = $this->create_expense_from_email($company_email, $message, $receipt_account, $receipt, $receipt_html_main, $email_date, $image_email_url);

                //move message here...
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
                    // Log::channel('ms_form_amount_not_found')->info($ocr_receipt_extract_prefix);

                    $this->ms_graph->createRequest('POST', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId().'/move')
                        ->attachBody(
                            [
                                'destinationId' => $company_email->api_json['hive_folder_error'],
                            ]
                        )
                        ->execute();

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
    }

    public function auto_receipt()
    {
        //09/22/2023 EACH FILE SHOULD BE UPLOADED TO ONEDRIVE AND NOT VIA EMAIL!
        //get receipt from email/onedrive
        $company_emails = CompanyEmail::withoutGlobalScopes()->whereNotNull('api_json->user_id')->where('id', 17)->get();
        // dd($company_emails);
        foreach($company_emails as $company_email) {
            $email_vendor = $company_email->vendor;
            $email_vendor_bank_account_ids = $email_vendor->bank_accounts->pluck('id');

            //check if access_token is expired, if so get new access_token and refresh_token
            $guzzle = new Client;
            $url = 'https://login.microsoftonline.com/'.env('MS_GRAPH_TENANT_ID').'/oauth2/v2.0/token';
            $email_account_tokens = json_decode($guzzle->post($url, [
                'form_params' => [
                    'client_id' => env('MS_GRAPH_CLIENT_ID'),
                    'scope' => env('MS_GRAPH_USER_SCOPES'),
                    'refresh_token' => $company_email->api_json['refresh_token'],
                    'redirect_uri' => env('MS_GRAPH_REDIRECT_URI'),
                    'grant_type' => 'refresh_token',
                    'client_secret' => env('MS_GRAPH_SECRET_ID'),
                ],
            ])->getBody()->getContents());

            //json
            $api_data = $company_email->api_json;
            $api_data['access_token'] = $email_account_tokens->access_token;
            $api_data['refresh_token'] = $email_account_tokens->refresh_token;

            $company_email->update([
                'api_json' => $api_data,
            ]);

            $this->ms_graph = new Graph;
            $this->ms_graph->setAccessToken($company_email->api_json['access_token']);

            // $receipt_folder = $this->ms_graph->createRequest("GET", "/me/drive/root/children")
            //     ->addHeaders(["Content-Type" => "application/json"])
            //     ->setReturnType(DriveItem::class)
            //     ->execute();

            // dd($receipt_folder);

            //6-12-2023 6-27-2023 exclude ones already read ... save $message->getId() to a database...
            $receipts_emails =
                $this->ms_graph
                    ->createCollectionRequest('GET',
                        "/me/mailFolders/inbox/messages?filter=from/emailAddress/address eq 'noreply@print.epsonconnect.com' and subject eq 'Receipt Scans'")
                    ->setReturnType(Message::class)
                    ->execute();
            // dd($receipts_emails);
            foreach($receipts_emails as $index => $message){
                if($message->getHasAttachments()){
                    $attachments =
                        $this->ms_graph->createRequest('GET', '/me/messages/'.$message->getId().'/attachments')
                            ->setReturnType(Attachment::class)
                            ->execute();

                    foreach($attachments as $loop => $attachment_found){
                        //09/22/2023 EACH FILE SHOULD BE UPLOADED TO ONEDRIVE AND NOT VIA EMAIL!

                        //if is for testing only...
                        // if($loop == 1){
                        $attachment = $attachment_found;
                        // $result = AzureDI::make()->analyzeDocument($attachment);

                        // dd(response()->json($result->getPathname()));
                        // return response()->json($result);
                        // https://github.com/blue-hex/laravel-azure-di
                        $doc_type = 'pdf';
                        $ocr_filename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.'.$doc_type;
                        $content_bytes = array_values((array) $attachment)[0]['contentBytes'];
                        $contents = base64_decode($content_bytes);
                        Storage::disk('files')->put('/_temp_ocr/'.$ocr_filename, $contents);

                        $ocr_path = 'files/_temp_ocr/'.$ocr_filename;

                        $document_model = $this->azure_document_model($doc_type, $ocr_path);
                        $ocr_receipt_extracted = $this->azure_receipts($ocr_path, $doc_type, $document_model);
                        //pass receipt info from ocr_receipt_extracted to ocr_extract method
                        $ocr_receipt_data = $this->ocr_extract($ocr_receipt_extracted);

                        if(isset($ocr_receipt_data['error']) && $ocr_receipt_data['error'] == true){
                            //if error move this single $attachment to a folder for debug...
                            Storage::disk('files')->move('/_temp_ocr/'.$ocr_filename, '/auto_receipts_failed/'.$ocr_filename);

                            continue;
                        }

                        // match Vendor to MerchantName ... MerchantName = transaction_description ...
                        $start_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])->subDays(4)->format('Y-m-d');
                        $end_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])->addDays(5)->format('Y-m-d');

                        //find existing transaction
                        $transactions =
                            Transaction::whereIn('bank_account_id', $email_vendor_bank_account_ids)
                                ->whereNull('expense_id')
                                ->whereNull('check_number')
                                ->whereNull('deposit')
                                ->where('amount', $ocr_receipt_data['fields']['total'])
                                ->whereBetween('transaction_date', [$start_date, $end_date])
                                ->get();

                        //create expense with or without Vendor_id and attach receipt
                        if ($transactions->count() == 1) {
                            //create expense with $transaction->vendor_id and associate with this transaction
                            $transaction = $transactions->first();
                            // 12/13/23 WHYY if greather than ??
                        } elseif ($transactions->count() > 1) {
                            $transaction = null;

                            //find amount in string .. like partial receipts / multiple transactions per expense
                        } else {
                            //no merchant ... filter
                            $exisitng_transactions =
                                Transaction::whereIn('bank_account_id', $email_vendor_bank_account_ids)
                                    ->whereNull('expense_id')
                                    ->whereNull('check_number')
                                    ->whereNull('deposit')
                                    // ->where('amount', $ocr_receipt_data['fields']['total'])
                                    ->whereBetween('transaction_date', [$start_date, $end_date])
                                    ->get();

                            $vendor_found_transactions = collect();
                            $receipt_merchant_name = explode(',', $ocr_receipt_data['fields']['merchant_name'])[0];

                            foreach ($exisitng_transactions as $exisitng_transaction) {
                                //either by vendor or by amount found in receipt scan text
                                if (strpos($exisitng_transaction->plaid_merchant_name, $receipt_merchant_name) !== false) {
                                    //add this to vendor_found_transactions
                                    $vendor_found_transactions->push($exisitng_transaction);
                                }
                            }

                            if (! $vendor_found_transactions->isEmpty()) {
                                //closest date dateDiff
                                foreach ($vendor_found_transactions as $vendor_found_transaction) {
                                    $str = $ocr_receipt_data['content'];
                                    $re = '/\\D'.str_replace('.', "\.", trim($vendor_found_transaction->amount, '-')).'/m';
                                    preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE, 0);

                                    if (! empty($matches)) {
                                        $transaction = $vendor_found_transaction;
                                        $ocr_receipt_data['fields']['total'] = $transaction->amount;
                                    }
                                }

                                if (! isset($transaction)) {
                                    $transaction = null;
                                }

                                $vendor = null;
                            } else {
                                //find vendor that matches merchant_name
                                $transaction = null;
                                $vendor = Vendor::withoutGlobalScopes()->where('business_type', 'Retail')->where('business_name', 'LIKE', $receipt_merchant_name)->first();
                            }
                        }

                        $duplicate_start_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])->subDays(1)->format('Y-m-d');
                        $duplicate_end_date = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])->addDays(4)->format('Y-m-d');
                        //find duplicate expenses
                        $duplicates =
                            Expense::where('belongs_to_vendor_id', $email_vendor->id)->
                                //08-02-2023 when merchant name/ vendor_id isset... check vendor_id on expense table otherwise dont
                                // where('vendor_id', $receipt->vendor_id)->
                                with('receipts')->
                                whereNull('deleted_at')->
                                where('amount', $ocr_receipt_data['fields']['total'])->
                                //where Not 0.00
                                where('amount', '!=', '0.00')->
                                whereBetween('date', [$duplicate_start_date, $duplicate_end_date])->
                                get();
                        // if 1 duplicate attach expense_receipt info
                        if ($duplicates->count() >= 1) {
                            foreach ($duplicates as $duplicate) {
                                $duplicate->date_diff = Carbon::parse($ocr_receipt_data['fields']['transaction_date'])->floatDiffInDays($duplicate->date);
                            }
                            //create expense and associate with this transaction
                            $expense_duplicate = $duplicates->sortBy('date_diff')->first();

                            // if receipt_html exactly the same dont add new ExpenseReceipt
                            if (isset($expense_duplicate->receipts()->latest()->first()->receipt_html)) {
                                if ($expense_duplicate->receipts()->latest()->first()->receipt_html != $ocr_receipt_data['content']) {
                                    //12/13/23 $expense should be new?
                                    $expense = $expense_duplicate;
                                } else {
                                    continue;
                                }
                            } else {
                                $expense = $expense_duplicate;
                            }
                        } elseif ($duplicates->isEmpty()) {
                            if ($transaction) {
                                if ($transaction->vendor_id) {
                                    $transaction_vendor_id = $transaction->vendor_id;
                                } else {
                                    $transaction_vendor_id = null;
                                }
                            } else {
                                $transaction_vendor_id = null;
                            }

                            $expense_vendor_id = ! is_null($transaction_vendor_id) ? $transaction_vendor_id : (isset($vendor) ? $vendor->id : 0);

                            $expense = Expense::create([
                                'amount' => $ocr_receipt_data['fields']['total'],
                                'date' => $ocr_receipt_data['fields']['transaction_date'],
                                'project_id' => null,
                                'distribution_id' => null,
                                'vendor_id' => $expense_vendor_id,
                                'check_id' => null,
                                'paid_by' => null,
                                'belongs_to_vendor_id' => $email_vendor->id,
                                'created_by_user_id' => 0,
                                'invoice' => $ocr_receipt_data['fields']['invoice_number'] ? $ocr_receipt_data['fields']['invoice_number'] : null,
                            ]);
                        }

                        $filename = $expense->id.'-'.date('Y-m-d-H-i-s').'.'.$doc_type;

                        //SAVE expense_receipt_data for each attachment
                        $expense_receipt = new ExpenseReceipts;
                        $expense_receipt->expense_id = $expense->id;
                        $expense_receipt->receipt_filename = $filename;
                        $expense_receipt->receipt_html = $ocr_receipt_data['content'];
                        $expense_receipt->receipt_items = $ocr_receipt_data['fields'];
                        $expense_receipt->save();

                        if ($transaction) {
                            $transaction->expense_id = $expense->id;
                            $transaction->save();
                        }

                        Storage::disk('files')->copy('/_temp_ocr/'.$ocr_filename, '/receipts/'.$filename);
                        // } //if loop
                    }
                }
                //Delete/move email
                $this->ms_graph->createRequest('DELETE', '/users/'.$company_email->api_json['user_id'].'/messages/'.$message->getId())->execute();
            }
        }
    }

    public function create_expense_from_email($company_email, $message, $receipt_account, $receipt, $receipt_html_main, $email_date, $image_email_url = null)
    {
        $message_type = array_values((array) $message->getBody()->getContentType())[0];

        if (! isset($receipt->options['receipt_image_regex']) && ! isset($receipt->options['pdf_html'])) {
            $doc_type = 'pdf';
            $ocr_filename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.pdf';

            $view = view('misc.create_pdf_receipt', compact(['receipt_html_main', 'message_type']))->render();
            $ocr_path = 'files/_temp_ocr/'.$ocr_filename;
            $location = storage_path('files/_temp_ocr/'.$ocr_filename);

            Browsershot::html($view)
                ->newHeadless()
                ->format('A4')
                // ->margins($top, $right, $bottom, $left)
                ->margins(20, 0, 20, 20)
                ->save($location);
        } elseif (isset($receipt->options['pdf_html'])) {
            $doc_type = 'pdf';
            //if no email text, use pdf as html_receipt
            //use first attachment
            if ($message->getHasAttachments()) {
                $attachments =
                    $this->ms_graph->createRequest('GET', '/me/messages/'.$message->getId().'/attachments')
                        ->setReturnType(Attachment::class)
                        ->execute();
                foreach ($attachments as $loop => $attachment_found) {
                    if (isset($receipt->options['attachment_name'])) {
                        $re = '/'.$receipt->options['attachment_name'].'/';
                        $str = $attachment_found->getName();
                        preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE, 0);

                        if (! empty($matches)) {
                            $attachment = $attachment_found;
                            break;
                        } else {
                            if (array_key_last($attachments) == $loop) {
                                $attachment = $attachments[0];
                            } else {
                                continue;
                            }
                        }
                    } else {
                        $attachment = $attachment_found;
                    }
                }

                $ocr_filename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.pdf';
                $content_bytes = array_values((array) $attachment)[0]['contentBytes'];
                //file decoded
                $contents = base64_decode($content_bytes);
                Storage::disk('files')->put('/_temp_ocr/'.$ocr_filename, $contents);

                $ocr_path = 'files/_temp_ocr/'.$ocr_filename;
                $location = storage_path($ocr_path);
            } else {
                //move to add_receipt_info folder, no attachment in email. need attachment when isset($receipt->options['pdf_html'])
                $move_type = 'error';

                return $move_type;
            }
        } else {
            //image / jpg OR png
            $ocr_filename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.jpg';
            $ocr_path = 'files/_temp_ocr/'.$ocr_filename;
            $location = storage_path($ocr_path);

            Image::make($image_email_url)->save($location);
            $doc_type = 'jpg';
        }

        //ocr the file
        $document_model = $receipt->options['document_model'];
        $ocr_receipt_extracted = $this->azure_receipts($ocr_path, $doc_type, $document_model);

        //pass receipt info to ocr_extract method
        $ocr_receipt_data = $this->ocr_extract($ocr_receipt_extracted, null, 'email');
        // dd($ocr_receipt_data);

        if (isset($ocr_receipt_data['error'])) {
            $move_type = 'error';

            return $move_type;
        } else {
            //01-26-2023 pass rest of receipt info to ocr_extract method
            if (! is_null($ocr_receipt_data['fields']['transaction_date'])) {
                $date = $ocr_receipt_data['fields']['transaction_date'];
            } else {
                $date = $email_date;
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
        }

        //FIND duplicates
        //confirm expense does not yet exist
        //1-18-2023 | 9/30/2023 NEED TO ACCOUNT FOR SAME VENDOR, AMOUNT, AND DATE being saved multiple of times (accounted for in old $duplicates in $this->dirty_work)
        //maybe by adding date_TIME to 'date'? or checking time in the expense_receipt_data json?

        $duplicates =
            Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)->
                where('vendor_id', $receipt->vendor_id)->
                whereNull('deleted_at')->
                where('amount', $amount)->
                where('invoice', $invoice)->
                whereBetween('date', [Carbon::create($date)->subDay(), Carbon::create($date)->addDays(4)])->
                get();

        if (! $duplicates->isEmpty()) {
            // 1-22-2023! WHAT IF THERE IS MULTIPLE?! -- diff in days!
            $duplicate_expense = $duplicates->first();

            //ATTACHMENTS
            $attachments = $this->add_attachments_to_expense($duplicate_expense->id, $message, $ocr_receipt_data, $ocr_filename, $company_email);
            //add po and add invoice from ocr
            $duplicate_expense->invoice = $invoice;
            $duplicate_expense->date = $date;
            // $duplicate_expense->note = $purchase_order;
            $duplicate_expense->save();

            //move email receipt to Duplicate folder
            $move_type = 'duplicate';

            return $move_type;
        }

        //CREATE NEW Expense
        //If PO matches a project, use that project
        if (isset($receipt_account->project_id)) {
            if ($receipt_account->project_id === 0) {
                $receipt_account->project_id = null;
            } else {
                $receipt_account->project_id = $receipt_account->project_id;
            }

            $receipt_account->distribution_id = null;
        } elseif (isset($receipt_account->distribution_id)) {
            $receipt_account->distribution_id = $receipt_account->distribution_id;
            $receipt_account->project_id = null;
        } else {
            $receipt_account->distribution_id = null;
            $receipt_account->project_id = null;
        }

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
        //save ocr data and file/s
        $attachments = $this->add_attachments_to_expense($expense->id, $message, $ocr_receipt_data, $ocr_filename, $company_email);

        $move_type = 'new';

        return $move_type;
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
        // $result = AzureDI::make()->analyzeDocument('https://raw.githubusercontent.com/Azure-Samples/cognitive-services-REST-api-samples/master/curl/form-recognizer/rest-api/receipt.png');

        // dd(response()->json($result));
        // $endpoint = 'https://hive20251name.cognitiveservices.azure.com/documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=2024-11-30';
        // $apiKey = '5JcYKZ5a8D5YHlKnj783TWHgml7ZnjtlLWfxTiHASpHtvbt8fiOYJQQJ99BAACYeBjFXJ3w3AAALACOGN2aU';
        // $documentUrl = 'https://raw.githubusercontent.com/Azure-Samples/cognitive-services-REST-api-samples/master/curl/form-recognizer/rest-api/receipt.png';

        // $ch = curl_init();

        // curl_setopt($ch, CURLOPT_URL, $endpoint);
        // curl_setopt($ch, CURLOPT_POST, 1);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['urlSource' => $documentUrl]));
        // curl_setopt($ch, CURLOPT_HTTPHEADER, [
        //     'Content-Type: application/json',
        //     'Ocp-Apim-Subscription-Key: ' . $apiKey,
        // ]);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // $response = curl_exec($ch);

        // if (curl_errno($ch)) {
        //     echo 'Error:' . curl_error($ch);
        // } else {
        //     echo $response;
        // }

        // curl_close($ch);

        // dd();

        // $response = Http::withHeaders([
        //     'Content-Type' => 'application/json',
        //     'Ocp-Apim-Subscription-Key' => '5JcYKZ5a8D5YHlKnj783TWHgml7ZnjtlLWfxTiHASpHtvbt8fiOYJQQJ99BAACYeBjFXJ3w3AAALACOGN2aU',
        // ])->post('https://hive20251name.cognitiveservices.azure.com/documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=2024-11-30', [
        //     'urlSource' => 'https://raw.githubusercontent.com/Azure-Samples/cognitive-services-REST-api-samples/master/curl/form-recognizer/rest-api/receipt.png',
        // ]);

        // echo $response->body();
        // dd();

        // $file = file_get_contents(storage_path($file_location));

        // // Assuming the package has a method to analyze documents
        // $result = AzureDI::make()->analyzeDocument($file);

        // dd(response()->json($result));
        // return response()->json($result);
        // dd($file_location, $document_model, $doc_type);
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

        $file = file_get_contents(storage_path($file_location));

        // $endpoint = 'https://hive20251name.cognitiveservices.azure.com';
        // $subscriptionKey = '5JcYKZ5a8D5YHlKnj783TWHgml7ZnjtlLWfxTiHASpHtvbt8fiOYJQQJ99BAACYeBjFXJ3w3AAALACOGN2aU';
        // $receiptPath = 'https://raw.githubusercontent.com/Azure-Samples/cognitive-services-REST-api-samples/master/curl/form-recognizer/rest-api/receipt.png';

        // $client = new Client();
        // $headers = [
        //     'Content-Type' => 'application/octet-stream',
        //     'Ocp-Apim-Subscription-Key' => $subscriptionKey,
        // ];

        // try {
        //     // Submit the receipt for analysis
        //     $postResponse = $client->post($endpoint, [
        //         'headers' => $headers,
        //         'body' => fopen($receiptPath, 'r'),
        //     ]);

        //     $operationLocation = $postResponse->getHeader('Operation-Location')[0];

        //     // Polling the GET request to check the status
        //     do {
        //         $getResponse = $client->get($operationLocation, [
        //             'headers' => [
        //                 'Ocp-Apim-Subscription-Key' => $subscriptionKey,
        //             ],
        //         ]);
        //         $result = json_decode($getResponse->getBody(), true);
        //         sleep(5); // Wait for 5 seconds before checking again
        //     } while ($result['status'] !== 'succeeded');

        //     // Output the results
        //     echo json_encode($result, JSON_PRETTY_PRINT);
        // } catch (ClientException $e) {
        //     echo 'Error: ' . $e->getMessage();
        //     echo 'Status Code: ' . $e->getResponse()->getStatusCode();
        //     echo 'Response Body: ' . $e->getResponse()->getBody()->getContents();
        // }

        // dd('TOO LATE');

        //start OCR

        $ch = curl_init();

        $azure_api_key = env('AZURE_DI_API_KEY');
        $azure_api_version = env('AZURE_DI_VERSION');
        curl_setopt($ch, CURLOPT_URL, 'https://'.env('AZURE_DI_ENDPOINT').'/documentintelligence/documentModels/'.$document_model.':analyze?api-version='.$azure_api_version.'&features=queryFields&queryFields=PurchaseOrder');
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
        // dd($result);

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

        //PO NUMBER
        $purchase_order_number = $ocr_receipt_extract_prefix['PurchaseOrder']['valueString'] ?? null;

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

        // if(is_null($transaction_date)){
        //     $this->
        // }

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
            //if coming from creating email, allow $transaction_date to be NULL. if from auto_receipts, send error

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

        // dd($formatted_items);

        //AMOUNT
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
            //ONLY if coming from ExpensesNewForm, allow $amount above to be empty. ONLY
        } else {
            //if coming from ExpensesNewForm, allow $amount above to be empty.
            if (! is_null($expense_amount)) {
                $amount = $expense_amount;
            } else {
                $ocr_receipt_data = [
                    'error' => true,
                ];

                return $ocr_receipt_data;
            }
        }

        if (! isset($amount) && is_null($subtotal)) {
            $ocr_receipt_data = [
                'error' => true,
            ];

            return $ocr_receipt_data;
        } else {
            if (is_array($amount)) {
                $amount = $amount[0];
            } else {
                if ($amount == 0 && ! is_null($subtotal)) {
                    $amount = $subtotal;
                }

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
                    if ($attachment->getContentType() == 'application/pdf') {
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
    public function original_receipt($filename)
    {
        $path = storage_path('files/receipts/'.$filename);

        if (File::extension($filename) == 'pdf') {
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

        if (File::extension($filename) == 'pdf') {
            $response = Response::make(file_get_contents($path), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        } else {
            $response = Image::make($path)->response();
        }

        return $response;
    }

    //01-18-2023 transitioning all to $this->azure_receipts
    //06-21-2022 USING BOTH NEW_OCR AND OCR_SPACE.. why?.
    public function new_ocr_status()
    {
        //public function new_ocr($ocr_filename)
        //ocr_space($ocr_filename)

        //Show OCR left before buying more
        dd(exec('curl http://api.newocr.com/v1/key/status?key='.env('NEW_OCR_API')));
    }
}
