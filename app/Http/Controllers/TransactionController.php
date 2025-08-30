<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\ReceiptAccount;

use App\Models\Vendor;
use App\Models\VendorTransaction;

use App\Services\PlaidService;

use Carbon\Carbon;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TransactionController extends Controller
{
    protected $plaidService;

    public function __construct(PlaidService $plaidService)
    {
        $this->plaidService = $plaidService;
    }

    private function syncBankTransactions(Bank $bank, $cursor = null)
    {
        $accessToken = $bank->plaid_access_token;
        $cursor = $cursor ? $bank->plaid_options['next_cursor'] : null;
        $count = 200;

        return $this->plaidService->syncTransactions($accessToken, $cursor, $count);
    }

    //TEST ONLY //FOR DEVELOPER EXECUTION ONLY
    //only needed for test purposes...transactions update from Plaid.com webhooks
    //For use when Plaid API isn't acting as expected and can always be executed manually...

    // public function plaid_transactions_scheduled()
        // {
        //     $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get();

        //     foreach($banks as $bank){
        //         $data = array(
        //             "client_id" => env('PLAID_CLIENT_ID'),
        //             "secret" => env('PLAID_SECRET'),
        //             "access_token" => $bank->plaid_access_token,
        //             "webhook_type" => 'TRANSACTIONS',
        //             "webhook_code" => 'DEFAULT_UPDATE', //TRANSACTIONS_REMOVED
        //             "new_transactions"=> 899
        //         );

        //         $this->plaid_transactions($bank, $data);
        //     }
        //     // return Log::channel('plaid_institution_info')->info('finished plaid_transactions_scheduled');
    // }

    public function plaid_item_status()
    {
        $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get();

        foreach ($banks as $bank) {
            $accessToken = $bank->plaid_access_token;
            $result = $this->plaidService->getItem($accessToken);

            if (isset($result['item']['error'])) {
                $error = ['error' => $result['item']['error']];
            } else {
                $last_failed_update = Carbon::parse($result['status']['transactions']['last_failed_update']);
                $last_successful_update = Carbon::parse($result['status']['transactions']['last_successful_update']);

                $difference = $last_failed_update->diff($last_successful_update);
                $difference = ['before' => $difference->invert, 'diff_in_days' => $difference->days];

                if ($difference['before'] === 1 && $difference['diff_in_days'] > 3) {
                    $error = ['error' => ['error_type' => 'ITEM_ERROR', 'error_code' => 'NO_TRANSACTIONS', 'error_message' => 'No New Transactions in over 3 days. Please UPDATE BANK.']];
                } else {
                    $error = ['error' => false];
                }
            }

            //if error is false, check for errors on the bank transactions
            if (!$error['error']) {
                $result_bank_transactions = $this->syncBankTransactions($bank);

                if (isset($result_bank_transactions['error_code'])) {
                    $error = ['error' => $result_bank_transactions];
                    $result = [];
                } else {
                    $error = ['error' => false];
                }
            }

            // elseif (empty($result['accounts'])) {
            //     $error = ['error' => ['error_type' => 'ITEM_ERROR', 'error_code' => 'ACCOUNT_CHANGED', 'error_message' => 'Account Numbers Changed. Update Bank Account']];
            //     $result = [];
            // }

            // dd($result);

            $bank->plaid_options = array_merge($bank->plaid_options ?? [], $error, $result);
            $bank->save();
        }
    }

    public function plaid_statements_list()
    {
        dd($plaid_statements_list);
        try {
            $client = new Client;
            $response = $client->post('https://'.env('PLAID_ENV').'.plaid.com/statements/list', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'client_id' => env('PLAID_CLIENT_ID'),
                    'secret' => env('PLAID_SECRET'),
                    'access_token' => 'access-production-ee3181e2-45b1-430a-a202-8d881aa1ff7c',
                ],
            ]);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $responseBody = $response->getBody()->getContents();
                $error = $responseBody;
            } else {
                $error = $e->getMessage();
            }
            $error = json_decode($error, true);
            Log::channel('plaid_statements')->error($error);
        }

        $body = $response->getBody()->getContents();
        dd($response);
        $statement_id = json_decode($body, true)['accounts'][0]['statements'][1]['statement_id'];

        $client = new Client;
        $response = $client->post('https://'.env('PLAID_ENV').'.plaid.com/statements/download', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'client_id' => env('PLAID_CLIENT_ID'),
                'secret' => env('PLAID_SECRET'),
                'access_token' => 'access-production-b19234d9-d3d1-475f-9a02-7db2c88259a5',
                'statement_id' => $statement_id,
            ],
        ]);

        return Storage::disk('files')->put('/_temp_ocr/TESTSTATEMENT12.pdf', $response->getBody()->getContents());
        dd();
        // dd($response->getBody()->getContents());
        print_r($response->getBody()->getContents());
        dd();
        dd($response);

        $new_data = [
            'client_id' => env('PLAID_CLIENT_ID'),
            'secret' => env('PLAID_SECRET'),
            'access_token' => 'access-production-b19234d9-d3d1-475f-9a02-7db2c88259a5',
            'statement_id' => $statement_id,
        ];

        $new_data = json_encode($new_data);
        //initialize session
        $ch = curl_init('https://'.env('PLAID_ENV').'.plaid.com/statements/download');
        //set options
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $new_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //execute session
        $result = curl_exec($ch);
        //close session
        curl_close($ch);

        // echo $result;
        // dd();

        // dd($result);
        // $result = json_decode($result, true);
        // print_r($result);
        // dd();

        // dd($result);
        $contents = base64_decode($result);
        dd($contents);

        return Storage::disk('files')->put('/_temp_ocr/TESTSTATEMENT12.pdf', $contents);
    }

    public function plaid_transactions_refresh()
    {
        dd('plaid_transactions_refresh');
        $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get();

        foreach ($banks as $bank) {
            $new_data = [
                'client_id' => env('PLAID_CLIENT_ID'),
                'secret' => env('PLAID_SECRET'),
                'access_token' => $bank->plaid_access_token,
            ];

            $new_data = json_encode($new_data);
            //initialize session
            $ch = curl_init('https://'.env('PLAID_ENV').'.plaid.com/transactions/refresh');
            //set options
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $new_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //execute session
            $result = curl_exec($ch);
            //close session
            curl_close($ch);

            $result = json_decode($result, true);
        }
    }

    public function plaid_transactions_sync()
    {
        // ->where('id', 22)
        $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get();

        //if not in error state...
        foreach ($banks as $bank) {
            if($bank->plaid_options['error']['error_code'] ?? false){
                continue;
            }else{
                $this->plaid_transactions_sync_bank($bank);
            }
        }
    }

    public function plaid_transactions_sync_bank(Bank $bank)
    {
        $result = $this->syncBankTransactions($bank, true);

        // Update bank account balances from Plaid response
        if (!array_key_exists('error_code', $result) && isset($result['accounts'])) {
            $this->updateBankAccountBalances($result['accounts']);
        }
        
        // Continue with your existing code below
        $bank_account_ids = $bank->accounts->pluck('id')->toArray();
    
        if($result['transactions_update_status'] ?? 'HISTORICAL_UPDATE_COMPLETE') {
            $transactions_last_date = Transaction::whereIn('bank_account_id', $bank_account_ids)->latest()->first()->transaction_date->subWeeks(3)->format('Y-m-d');
        }else{
            $transactions_last_date = '2025-01-01';
        }

        if (!empty($result['added']) or !empty($result['modified']) or !empty($result['removed']) or isset($result['error_code'])) {
            Log::channel('plaid_adds')->info([[$bank->getAttributes(), $bank->plaid_options], $result]);
        }

        //if not in error state...
        if (! array_key_exists('error_code', $result)) {
            $plaidOptions = $bank->plaid_options;
            $plaidOptions['next_cursor'] = $result['next_cursor'];
            $plaidOptions['accounts'] = $result['accounts'];
            $bank->plaid_options = $plaidOptions;
            $bank->save();

            if ($result['has_more'] == true) {
                $this->plaid_transactions_sync_bank($bank);
            }

            //ADDED
            foreach ($result['added'] as $index => $new_transaction) {
                if ($new_transaction['date'] <= $transactions_last_date) {
                    continue;
                } else {
                    //make sure transaction_id does not exist yet.. if it does..update..
                    if (Transaction::whereNotNull('plaid_transaction_id')->where('plaid_transaction_id', $new_transaction['pending_transaction_id'])->get()->isNotEmpty()) {
                        $transaction = Transaction::where('plaid_transaction_id', $new_transaction['pending_transaction_id'])->first();
                    } elseif (Transaction::whereNotNull('plaid_transaction_id')->where('plaid_transaction_id', $new_transaction['transaction_id'])->get()->isNotEmpty()) {
                        $transaction = Transaction::where('plaid_transaction_id', $new_transaction['transaction_id'])->first();
                    } elseif (Transaction::whereDate('posted_date', $new_transaction['date'])->whereNotNull('plaid_transaction_id')->where('owner', $new_transaction['account_owner'])->where('amount', $new_transaction['amount'])->get()->isNotEmpty()) {
                        //11/14/2024 ...used in multiple places on this Controller
                        //->where('plaid_transaction_id', $new_transaction['transaction_id'])
                        $existing_transactions = Transaction::whereDate('posted_date', $new_transaction['date'])->whereIn('bank_account_id', $bank_account_ids)->where('owner', $new_transaction['account_owner'])->where('amount', $new_transaction['amount'])->get();

                        if ($existing_transactions->count() === 1) {
                            $transaction = $existing_transactions->first();
                        } else {
                            if ($existing_transactions->isEmpty()) {
                                $transaction = new Transaction;
                            } else {
                                //LOG
                                //DiffInDays / Carbon
                                Log::channel('plaid_adds')->error(['ADDED in TransactionController' => [$new_transaction, $existing_transactions], $result]);
                            }
                        }
                    } else {
                        $transaction = new Transaction;
                    }

                    $this->plaid_add_transaction($transaction, $new_transaction);
                }
            }

            //MODIFIED  / SYNC
            foreach ($result['modified'] as $new_transaction) {
                //make sure transaction_id does not exist yet.. if it does..update..
                if (Transaction::whereDate('transaction_date', '>=', '2023-01-01')->whereNotNull('plaid_transaction_id')->where('plaid_transaction_id', $new_transaction['pending_transaction_id'])->get()->isNotEmpty()) {
                    $transaction = Transaction::whereDate('transaction_date', '>=', '2023-01-01')->where('plaid_transaction_id', $new_transaction['pending_transaction_id'])->first();
                } elseif (Transaction::whereDate('transaction_date', '>=', '2023-01-01')->whereNotNull('plaid_transaction_id')->where('plaid_transaction_id', $new_transaction['transaction_id'])->get()->isNotEmpty()) {
                    $transaction = Transaction::whereDate('transaction_date', '>=', '2023-01-01')->where('plaid_transaction_id', $new_transaction['transaction_id'])->first();
                //same bank, different bank_account_id
                } elseif (Transaction::whereDate('transaction_date', $new_transaction['authorized_date'])->where('amount', $new_transaction['amount'])->whereNotNull('plaid_transaction_id')->whereNot('plaid_transaction_id', $new_transaction['transaction_id'])->get()->isNotEmpty()) {
                    $transaction = Transaction::whereDate('transaction_date', $new_transaction['authorized_date'])->where('amount', $new_transaction['amount'])->whereNotNull('plaid_transaction_id')->whereNot('plaid_transaction_id', $new_transaction['transaction_id'])->first();
                } else {
                    Log::channel('plaid_adds')->error(['MODIFIED in TransactionController' => [$new_transaction], $result]);
                    continue;
                }

                if ($new_transaction['check_number'] != null) {
                    $transaction->check_number = $new_transaction['check_number'];
                } else {
                    $transaction->check_number = null;
                }

                //dates
                if ($new_transaction['pending'] == true) {
                    $transaction->posted_date = null;
                } else {
                    $transaction->posted_date = $new_transaction['date'];
                }

                if ($new_transaction['authorized_date'] == null) {
                    $transaction->transaction_date = $new_transaction['date'];
                } else {
                    if (isset($transaction->transaction_date)) {

                    } else {
                        $transaction->transaction_date = $new_transaction['authorized_date'];
                    }
                }

                //if $transaction['merchant_name'] empty, use $new_transaction['name']
                if (isset($new_transaction['merchant_name'])) {
                    $transaction->plaid_merchant_name = $new_transaction['merchant_name'];
                } else {
                    // $transaction->plaid_merchant_name = $new_transaction['name'];
                    $transaction->plaid_merchant_name = null;
                }

                $transaction->amount = $new_transaction['amount'];
                $transaction->plaid_merchant_description = $new_transaction['name'];
                $transaction->plaid_transaction_id = $new_transaction['transaction_id'];
                $transaction->bank_account_id = $bank->accounts->where('plaid_account_id', $new_transaction['account_id'])->first()->id;
                $transaction->details = $new_transaction;
                $transaction->save();
            }

            //REMOVED
            foreach ($result['removed'] as $old_transaction) {
                //make sure transaction_id does not exist yet.. if it does..update..
                $transaction = Transaction::whereDate('transaction_date', '>=', '2023-01-01')->whereNotNull('plaid_transaction_id')->where('plaid_transaction_id', $old_transaction['transaction_id'])->first();

                if (! is_null($transaction)) {
                    //transaction has payments ...disassociate
                    $transaction->payments()->get()->each(function ($payment) {
                        $payment->transaction()->dissociate();
                        $payment->save();
                    });

                    $transaction->deleted_at = now();
                    $transaction->save();

                    Log::channel('plaid_transaction_removal')->info([$transaction->id, $transaction->plaid_transaction_id]);
                }
            }
        } else {
            return;
        }
    }

    private function updateBankAccountBalances(array $accountsData): void
    {
        if (empty($accountsData)) {
            return;
        }

        foreach ($accountsData as $accountData) {
            // Find the bank account by its Plaid account_id
            $bankAccount = BankAccount::where('plaid_account_id', $accountData['account_id'])->first();
            
            if (!$bankAccount) {
                continue;
            }
            
            // Get current options as object or initialize empty object
            $options = $bankAccount->options ?? new \stdClass();
            $balancesChanged = false;
            
            // Check if balances have changed by comparing with existing data
            if (!isset($options->balances) || 
                $options->balances->available != $accountData['balances']['available'] || 
                $options->balances->current != $accountData['balances']['current']) {
                $balancesChanged = true;
            }
            
            // Update the balances data - convert array to object
            $options->balances = json_decode(json_encode($accountData['balances']));
            
            // Only update timestamp if balances have changed
            if ($balancesChanged) {
                $options->last_balance_update = now()->toDateTimeString();
            }
            
            // Save updated options back to the bank account
            $bankAccount->options = $options;
            $bankAccount->save();
        }
    }

    //03-07-2025 use after updating an Item so transactions are in sync between different bank_account_ids for each/same bank
    public function plaid_transactions_get()
    {
        $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get();

        foreach ($banks as $bank) {
            $accessToken = $bank->plaid_access_token;
            $startDate = '2025-04-28';
            $endDate = '2025-05-15';
            $result = $this->plaidService->getTransactions($accessToken, $startDate, $endDate);

            $bank_account_ids = $bank->accounts->pluck('id')->toArray();
            // Process transactions as needed

            if(isset($result['transactions'])){
                foreach($result['transactions'] as $index => $new_transaction){
                    $existing_transaction =
                        Transaction::
                            whereDate('posted_date', $new_transaction['date'])
                            ->whereIn('bank_account_id', $bank_account_ids)
                            ->where('owner', $new_transaction['account_owner'])
                            ->where('plaid_merchant_description', $new_transaction['name'])
                            ->where('amount', $new_transaction['amount'])
                            ->first();

                    if($existing_transaction){
                        continue;
                    }else{
                        $existing_transaction = new Transaction;
                        $this->plaid_add_transaction($existing_transaction, $new_transaction);
                    }
                }
            }
        }
    }

    private function plaid_add_transaction($transaction, $new_transaction)
    {
        //dates
        if ($new_transaction['pending'] == true) {
            $transaction->posted_date = null;
        } else {
            $transaction->posted_date = $new_transaction['date'];
        }

        //11/14/2024 ...used in multiple places on this Controller
        if ($new_transaction['authorized_date'] == null) {
            $transaction->transaction_date = $new_transaction['date'];
        } else {
            if (isset($transaction->transaction_date)) {

            } else {
                $transaction->transaction_date = $new_transaction['authorized_date'];
            }
        }

        //if $transaction['merchant_name'] empty, use $new_transaction['name']
        if (isset($new_transaction['merchant_name'])) {
            $transaction->plaid_merchant_name = $new_transaction['merchant_name'];
        }

        $transaction->amount = $new_transaction['amount'];
        $transaction->plaid_merchant_description = $new_transaction['name'];
        $transaction->plaid_transaction_id = $new_transaction['transaction_id'];

        // if(!$bank_accounts->where('plaid_account_id', $new_transaction['account_id'])->first()->id){
        //     dd($bank_accounts->where('plaid_account_id', $new_transaction['account_id'])->first());
        // }
        // dd($new_transaction['account_id']);
        $transaction->bank_account_id = BankAccount::where('plaid_account_id', $new_transaction['account_id'])->first()->id;
        if ($new_transaction['check_number'] != null) {
            $transaction->check_number = $new_transaction['check_number'];
        }

        $transaction->owner = $new_transaction['account_owner'];
        $transaction->details = $new_transaction;
        $transaction->save();
    }


    public function plaid_transactions_enrich()
    {
        ini_set('max_execution_time', '9900000');
        $start_date = Carbon::now()->subDays(450);
        $end_date = Carbon::now();
        $offset = 0;
        $count = 99;
        $transactions_count = Transaction::where('bank_account_id', 10)->whereNull('details')->whereBetween('posted_date', [$start_date, $end_date])->orderBy('id', 'DESC')->get();

        $total_transactions_count = $transactions_count->count();
        // dd($total_transactions_count);
        // for loop. Count, Offset
        for ($offset = $offset; $offset <= $total_transactions_count; $offset += $count) {
            $transactions = Transaction::where('bank_account_id', 10)->whereNull('details')->whereBetween('posted_date', [$start_date, $end_date])->orderBy('id', 'DESC')->get()->take(99);

            $array_transactions = [];
            foreach ($transactions as $index => $transaction) {
                //if MINUS - then INFLOW, otherwise OUTFOLW
                $negative = substr($transaction['amount'], 0, 1);
                if ($negative == '-') {
                    $direction = 'INFLOW';
                } else {
                    $direction = 'OUTFLOW';
                }

                if ($transaction->vendor) {
                    if ($transaction->vendor->business_name != 'No Vendor') {
                        $business_name = $transaction->vendor->business_name;
                    } else {
                        if ($transaction->plaid_merchant_name) {
                            $business_name = $transaction->plaid_merchant_name;
                        } else {
                            $business_name = null;
                        }
                    }
                } else {
                    $business_name = $transaction->plaid_merchant_name;
                }

                $business_name = str_replace('&', 'And', $business_name);
                //where $business_name not in plaid_merchant_description
                if (str_contains(strtolower($transaction['plaid_merchant_description']), strtolower($business_name))) {
                    $business_name = null;
                }

                $array_transactions[$index]['id'] = (string) $transaction['id'];
                $array_transactions[$index]['description'] = ltrim($business_name.' '.$transaction['plaid_merchant_description']);
                $array_transactions[$index]['amount'] = (float) str_replace('-', '', $transaction['amount']);
                $array_transactions[$index]['direction'] = $direction;
                $array_transactions[$index]['iso_currency_code'] = 'USD';
                $array_transactions[$index]['date_posted'] = $transaction['posted_date']->format('Y-m-d');
            }

            $new_data = [
                'client_id' => env('PLAID_CLIENT_ID'),
                'secret' => env('PLAID_SECRET'),
                'account_type' => 'depository',
                'transactions' => $array_transactions,
            ];

            $new_data = json_encode($new_data);

            //initialize session
            $ch = curl_init('https://'.env('PLAID_ENV').'.plaid.com/transactions/enrich');
            //set options
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $new_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //execute session
            $result = curl_exec($ch);
            //close session
            curl_close($ch);

            $transactions_enriched = json_decode($result, true);
            $transactions_enriched = $transactions_enriched['enriched_transactions'];
            foreach ($transactions_enriched as $transaction_enriched) {
                // dd($transaction_enriched);
                $attach_transaction = Transaction::where('bank_account_id', 10)->whereNull('details')->whereBetween('posted_date', [$start_date, $end_date])->orderBy('id', 'DESC')->findOrFail($transaction_enriched['id']);
                // $attach_transaction = $transactions->findOrFail($transaction_enriched['id']);
                // dd($attach_transaction);
                $attach_transaction['details'] = $transaction_enriched['enrichments'];
                $attach_transaction->timestamps = false;
                $attach_transaction->save();
            }
        }
    }

    // public function plaid_transactions_get_connect($bank, $count, $offset = 0)
    // {
        //     $new_data = [
        //         'client_id' => env('PLAID_CLIENT_ID'),
        //         'secret' => env('PLAID_SECRET'),
        //         //bank access token
        //         'access_token' => $bank->plaid_access_token,
        //         'options' => [
        //             'count' => $count,
        //             'offset' => $offset,
        //         ],
        //     ];

        //     // $start_date = Carbon::parse('2024-01-01')->toDateString();
        //     // $nend_date = Carbon::parse('2024-01-05')->toDateString();
        //     $start_date = Carbon::now()->subDays(450);
        //     $end_date = Carbon::now();

        //     $new_data['start_date'] = $start_date->toDateString();
        //     $new_data['end_date'] = $end_date->toDateString();

        //     $new_data = json_encode($new_data);

        //     //initialize session
        //     $ch = curl_init('https://'.env('PLAID_ENV').'.plaid.com/transactions/get');
        //     //set options
        //     curl_setopt($ch, CURLOPT_HTTPHEADER, [
        //         'Content-Type: application/json',
        //     ]);
        //     curl_setopt($ch, CURLOPT_POST, true);
        //     curl_setopt($ch, CURLOPT_POSTFIELDS, $new_data);
        //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //     //execute session
        //     $result = curl_exec($ch);
        //     //close session
        //     curl_close($ch);

        //     return json_decode($result, true);
    // }

    public function add_category_to_expense()
    {
        $hive_vendors = Vendor::hiveVendors()->get();
        $categories = Category::all();
        foreach ($hive_vendors as $hive_vendor) {
            $hive_vendor_bank_account_ids = $hive_vendor->bank_accounts->pluck('id');
            $vendors_with_category = Vendor::withoutGlobalScopes()->whereHas('category')->get();

            $transactions =
                Transaction::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                    ->whereHas('expense', function ($query) {
                        return $query->whereDoesntHave('category');
                    })
                    ->with(['expense.vendor.category'])
                    ->get();

            foreach ($transactions as $transaction) {
                if (!$transaction->expense) { continue; }

                // Prefer the vendor's category if present; otherwise leave for next pass
                $vendorCategory = optional(optional($transaction->expense)->vendor)->category;
                if ($vendorCategory) {
                    $transaction->expense->category()->associate($vendorCategory);
                    $transaction->expense->timestamps = false;
                    $transaction->expense->save();
                }
            }

            $transactions =
                Transaction::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                    ->whereNotNull('details')
                    ->whereHas('expense', function ($query) {
                        return $query->whereDoesntHave('category');
                    })
                    ->with(['expense.vendor.category'])
                    ->get();

            foreach ($transactions as $transaction) {
                if ($transaction->expense && ! $transaction->expense->category) {
                    // 1) Use vendor's category if available
                    $vendorCategory = optional(optional($transaction->expense)->vendor)->category;
                    if ($vendorCategory) {
                        $transaction->expense->category()->associate($vendorCategory);
                        $transaction->expense->timestamps = false;
                        $transaction->expense->save();
                        continue;
                    }

                    // 2) Otherwise, map from Plaid detailed category
                    $transaction_category = $transaction->details['personal_finance_category']['detailed'] ?? null;
                    if ($transaction_category) {
                        $category = $categories->where('detailed', $transaction_category)->first();
                        if ($category) {
                            $transaction->expense->category()->associate($category);
                            $transaction->expense->timestamps = false;
                            $transaction->expense->save();
                        }
                    }
                }

                if ($transaction->check) {
                    foreach ($transaction->check->expenses as $expense) {
                        if ($expense->category) { continue; }

                        // Prefer the expense vendor category; else fallback to the transaction's expense category; else Plaid mapping
                        $expenseVendorCategory = optional($expense->vendor)->category;
                        if ($expenseVendorCategory) {
                            $expense->category()->associate($expenseVendorCategory);
                            $expense->timestamps = false;
                            $expense->save();
                            continue;
                        }

                        if ($transaction->expense && $transaction->expense->category) {
                            $expense->category()->associate($transaction->expense->category);
                            $expense->timestamps = false;
                            $expense->save();
                            continue;
                        }

                        $transaction_category = $transaction->details['personal_finance_category']['detailed'] ?? null;
                        if ($transaction_category) {
                            $category = $categories->where('detailed', $transaction_category)->first();
                            if ($category) {
                                $expense->category()->associate($category);
                                $expense->timestamps = false;
                                $expense->save();
                            }
                        }
                    }
                }
            }

            $vendors_expenses =
                Expense::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where('belongs_to_vendor_id', $hive_vendor->id)
                    ->whereBetween('date', ['2021-01-01', Carbon::now()->subDays(6)->format('Y-m-d')])
                    ->whereDoesntHave('category')
                    ->get()
                    ->groupBy('vendor_id');

            foreach ($vendors_expenses as $vendor_id => $vendor_expenses) {
                $expenses =
                    Expense::withoutGlobalScopes()
                        ->where('belongs_to_vendor_id', $hive_vendor->id)
                        ->whereBetween('date', ['2021-01-01', Carbon::now()->subDays(6)->format('Y-m-d')])
                        ->whereDoesntHave('category')
                        ->where('vendor_id', $vendor_id);
                // $expenses = $vendors_expenses[$vendor_id];
                // get $cagetory id of most used category for this vendor
                $category =
                    Expense::withoutGlobalScopes()
                        ->where('vendor_id', $vendor_id)
                        ->whereHas('category')
                        ->get()
                        ->groupBy('category_id')
                        ->map(function ($category) {
                            return $category->count();
                        })
                        ->sort()->keys()->last();
                $expenses->timestamps = false;
                $expenses->update(['category_id' => $category]);
            }
        }
    }

    public function add_vendor_to_transactions()
    {
        // Query BankAccount once and load the related Bank
        $bankAccounts = BankAccount::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->with('bank') // Load the related Bank
            ->get();

        // Extract BankAccount IDs
        $bankAccountIds = $bankAccounts->pluck('id')->toArray();
        // Extract unique plaid_ins_id values from the related Bank
        $bankInsIds = $bankAccounts->pluck('bank.plaid_ins_id')->unique()->toArray();

        $transactions = Transaction::TransactionsSinVendor()->whereIn('bank_account_id', $bankAccountIds)->get()->groupBy('plaid_merchant_name');
        $vendors = Vendor::withoutGlobalScopes()->where('business_type', 'Retail')->get();

        foreach ($transactions as $merchant_name => $merchant_transactions) {
            //find vendor where vendor->business_name is contained in $merchant_name
            $vendor_match = app(\App\Http\Controllers\CompanyEmailController::class)->fuzzyMatchVendor($merchant_name, $vendors);

            if ($vendor_match) {
                foreach ($merchant_transactions as $transaction) {
                    $transaction->vendor_id = $vendor_match->id;
                    $transaction->save();
                }
                //USED IN MULTIPLE OF PLACES MatchVendor@store, ExpesnesForm@createExpenseFromTransaction, below in CHECK VendorTransaction code in this function as well
                //add vendor if vendor is not part of the currently logged in vendor
                // if (! $transaction->bank_account->vendor->vendors->contains($transaction->vendor_id)) {
                //     $transaction->bank_account->vendor->vendors()->attach($transaction->vendor_id);
                // }
            }else{
                continue;
                // $vendor_transaction = VendorTransaction::whereNull('deposit_check')->where('desc', $merchant_name)->first();
                // if($vendor_transaction){
                //     foreach ($merchant_transactions as $key => $transaction) {
                //         dd(strtolower($transaction->plaid_merchant_description) === strtolower($merchant_name));
                //         dd($transaction);
                //         $transaction->vendor_id = $vendor_transaction->vendor_id;
                //         $transaction->save();
                //     }

                    // if (! $transaction->bank_account->vendor->vendors->contains($transaction->vendor_id)) {
                    //     $transaction->bank_account->vendor->vendors()->attach($transaction->vendor_id);
                    // }
                // }
            }
        }

        $transactions = Transaction::TransactionsSinVendor()->whereIn('bank_account_id', $bankAccountIds)->get()->groupBy('plaid_merchant_description');
        $vendor_transactions = VendorTransaction::whereNull('deposit_check')->orderByRaw('LENGTH(`desc`) ASC')->get();

        foreach ($vendor_transactions as $vendor_transaction) {
            //get all BankAccount where bank_account_id
            //get plaid_inst_id of bank_account_ids on transactions table

            //Alter $transactions variable/results based on the if statement below
            foreach ($transactions as $merchant_desc => $plaid_name_transactions) {
                //decode json on VendorTrasaction Model
                $preg = json_decode($vendor_transaction->options);
                preg_match('/'.$vendor_transaction->desc.$preg, $merchant_desc, $matches, PREG_UNMATCHED_AS_NULL);

                if (! empty($matches)) {
                    foreach ($plaid_name_transactions as $transaction) {
                        $transaction->vendor_id = $vendor_transaction->vendor_id;
                        $transaction->save();

                        if ($transaction->expense) {
                            $expense = $transaction->expense;
                            $expense->vendor_id = $transaction->vendor_id;
                            $expense->save();
                        }

                        //USED IN MULTIPLE OF PLACES MatchVendor@store, above in original Vendor find code in this function as well
                        //add vendor if vendor is not part of the currently logged in vendor
                        // if (! $transaction->bank_account->vendor->vendors->contains($transaction->vendor_id)) {
                        //     $transaction->bank_account->vendor->vendors()->attach($transaction->vendor_id);
                        // }
                    }
                }
            }
        }
    }

    public function add_check_deposit_to_transactions()
    {
        $institutions = VendorTransaction::whereNotNull('plaid_inst_id')->groupBy('plaid_inst_id')->pluck('plaid_inst_id');

        //split by institution
        foreach ($institutions as $institution) {
            //06/29/2021 NEED TO SHARE THIS WITH TrancationController@store_csv_array.. same code x2
            $institution_bank_ids = Bank::withoutGlobalScopes()->where('plaid_ins_id', $institution)->pluck('id');
            $institution_bank_ids = BankAccount::whereIn('bank_id', $institution_bank_ids)->pluck('id');
            $deposit_check_types = VendorTransaction::groupBy('deposit_check')->where('plaid_inst_id', $institution)->pluck('deposit_check');

            //split by check_type of each institution (multiple of bank_ids)
            foreach ($deposit_check_types as $index => $deposit_check_type) {
                //same for type 2 and 3 (check and transfer)
                $transaction_check_desc = VendorTransaction::where('deposit_check', $deposit_check_type)->where('plaid_inst_id', $institution)->pluck('desc');

                $transactions = Transaction::where('expense_id', null)
                    ->where('vendor_id', null)
                    ->where('check_number', null)
                    // ->where('check_id', NULL)
                    ->where('deposit', null)
                    ->whereNotNull('transaction_date')
                    ->whereIn('bank_account_id', $institution_bank_ids)
                    //Same where clause used $this->createVendorTransactions 6/10/2021
                    ->where(function ($query) use ($transaction_check_desc) {
                        for ($i = 0; $i < count($transaction_check_desc); $i++) {
                            //  dd($transaction_check_desc[$i]);
                            //first or whitespace(need to implement 6/10/2021) before query only 6/10/21..instead of preg loop
                            $query->orWhere('plaid_merchant_description', 'like', '%'.$transaction_check_desc[$i].'%');
                            //'like', '%' . $transaction_check_desc[$i]
                        }
                    })
                    ->get();

                foreach ($transactions as $transaction) {
                    //preg here after $transactions are gathered or should it be before?...trying to do this in the LIKE statement above instead 6/10/2021
                    //NEED A WAY TO INCLUDE BILL PAY (6) IN THIS CODE

                    //CHECK
                    if ($deposit_check_type === 2) {
                        //if transaction_desc = "CHECK" and no number...it saves as check_number "0"..need to change.. but we account for this in $this->add_check_id_to_transactions 06/23/2021
                        $re = '/\d{3,}/';
                        $str = $transaction->plaid_merchant_description;
                        preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE, 0);

                        if (isset($matches[0][0])) {
                            $check_number = $matches[0][0];
                            $transaction->check_number = $check_number;

                            if ($check_number != '0000') {
                                $transaction->check_number = $check_number;
                            }
                        }

                        //TRANSFER
                    } elseif ($deposit_check_type === 3) {
                        $transaction->check_number = '1010101';

                        //DEPOSIT
                    } elseif ($deposit_check_type === 1) {
                        $transaction->deposit = 1; //yes, transaction has a deposit

                        //CASH
                    } elseif ($deposit_check_type === 4) {
                        $transaction->check_number = '2020202';
                    } else {
                        continue;
                    }

                    $transaction->save();
                }
            }
        }
    }

    public function add_expense_to_transactions()
    {
        $hive_vendors = Vendor::hiveVendors()->get();

        foreach ($hive_vendors as $hive_vendor) {
            $hive_vendor_bank_account_ids = $hive_vendor->bank_accounts->pluck('id');

            $expenses = Expense::with('transactions')
                ->with('receipts')
                ->whereNull('deleted_at')
                ->where('belongs_to_vendor_id', $hive_vendor->id)
                ->whereNotNull('vendor_id')
                //where transacitons->sum != $expense(item)->sum  \\ whereNull checked_at (transactions add up to expense)
                ->whereDate('date', '>=', Carbon::now()->subMonths(12))
                ->get();

            foreach ($expenses as $expense) {
                $start_date = $expense->date->subDays(7)->format('Y-m-d');
                $end_date = $expense->date->addDays(21)->format('Y-m-d');

                if (! $expense->transactions->isEmpty()) {
                    //transaction->amount cannot be more than expense->amount
                    $transaction_amount_outstanding = $expense->amount - $expense->transactions->sum('amount');

                    //if amount = full expense amount...
                    if ($transaction_amount_outstanding == 0) {
                        continue;
                    }
                } else {
                    $transaction_amount_outstanding = $expense->amount;
                }

                $transaction_amount_outstanding = (float) $transaction_amount_outstanding;
     
                $transactions = Transaction::whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                    ->whereNull('expense_id')
                    ->whereNull('deleted_at')
                    ->where('amount', '!=', '0.00')
                    //03-22 -2023 when negative, ignore vendor_id
                    // ->when(substr($expense->amount, 0, 1) == '-', function ($query) {
                    //     dd($vendor_id);
                    //     $query->whereNull('vendor_id')->where('deposit', 1);
                    // }, function ($query) use ($vendor_id) {
                    //     dd($vendor_id);
                    //     $query->where('vendor_id', $vendor_id);
                    // })
                    //whereDoesntHave payments
                    ->doesntHave('payments')
                    ->whereNull('check_number')
                    ->whereBetween('transaction_date', [$start_date, $end_date])

                    //03/08/2023 floatDiffInDays dateDiff? orderBy faster i think?
                    ->orderBy('transaction_date', 'DESC');

                //if expense vendor_id == expense belongs
                //where Greg pays deposit to GS (expense_id 17637)
                if ($expense->vendor_id == $expense->belongs_to_vendor_id) {
                    $transactions = $transactions->whereNull('vendor_id')->where('deposit', 1);
                } else {
                    $transactions = $transactions->where('vendor_id', $expense->vendor_id);
                }

                // if($expense->amount == 0){
                //     $transactions = $transactions->where('amount', '!=', 0)->get();
                // //if negative
                // }elseif (substr($expense->amount, 0, 1) == '-') {
                //     $transactions = $transactions->where('amount', '>=', $transaction_amount_outstanding)->where('amount', 'LIKE', '-%')->get();
                // } else {
                //     $transactions = $transactions->where('amount', '<=', $transaction_amount_outstanding)->where('amount', 'NOT LIKE', '-%')->get();
                // }

                $transactions = $transactions->get();

                //finds correct transaction
                if (!$transactions->isEmpty()) {
                    foreach ($transactions as $transaction) {
                        $transaction->date_diff = $transaction->transaction_date->floatDiffInDays($expense->date);
                    }

                    $transactions_full_amount = $transactions->where('amount', $transaction_amount_outstanding);

                    if (!$transactions_full_amount->isEmpty()) {
                        // dd($transaction->makeHidden('date_diff'));
                        $transaction = Transaction::findOrFail($transactions_full_amount->sortBy('date_diff')->first()->id);
                        $transaction->expense()->associate($expense);
                        $transaction->save();
                        //where amount != $expense->amount
                    } else {
                        if (!$expense->receipts->isEmpty()) {
                            foreach ($transactions as $transaction) {
                                //find $transaction->amount in $receipt_text. If expense receipt has items .. offset the last item
                                if ($expense->vendor_id === $transaction->vendor_id) {
                                    $receipt = $expense->receipts->last();

                                    if ($receipt->receipt_html) {
                                        if ($receipt->receipt_items->items) {
                                            $last_item_str = htmlspecialchars(end($receipt->receipt_items->items)->Description);
                                            $last_item_str_length = strlen($last_item_str);
                                            $offset_chars = stripos($receipt->receipt_html, $last_item_str) + $last_item_str_length;
                                            $str = substr($receipt->receipt_html, $offset_chars);
                                            //if no items extracted on receipt search for AMOUNT on entire receipt (but can incorrectly find a line item Amount as Receipt total)
                                        } else {
                                            $str = $receipt->receipt_html;
                                        }

                                        $re = '/\\D'.str_replace('.', "\.", trim($transaction->amount, '-')).'/m';
                                        preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE, 0);

                                        if (!empty($matches)) {
                                            $transaction = Transaction::findOrFail($transaction->id);
                                            $transaction->expense()->associate($expense);
                                            $transaction->save();
                                        }
                                    } else {
                                        if (isset($receipt->receipt_items->charges)) {
                                            $matches = collect($receipt->receipt_items->charges)->where('amount', $transaction->amount);
                                            if (! $matches->isEmpty()) {
                                                $transaction = Transaction::findOrFail($transaction->id);
                                                $transaction->expense()->associate($expense);
                                                $transaction->save();
                                            }
                                        }
                                    }
                                }
                            }

                            //summy
                            //clear array before next foreach statement
                            $transaction_results = [];

                            $transaction_ids = $transactions->pluck('id')->toArray();
                            $transaction_plucked = $transactions->pluck('amount')->toArray();

                            $arr = array_values(array_filter($transaction_plucked));
                            $n = count($arr);
                            $ids = $transaction_ids;

                            $results = collect($this->subsetSums($arr, $n, $ids, 'transaction'))->sortBy('sum');

                            foreach ($results as $key => $result) {
                                $sum = number_format($result['sum'], 2, '.', '');
                                //this can happen multiple of times.. eg transaction_id 6230

                                //is this Transaction a RETURN CHECK "DEPOSIT"?
                                if ($sum == $expense->amount) {
                                    $transaction_results = $result;
                                }
                            }

                            if (isset($transaction_results['transactions'])) {
                                $transaction_results = collect($transaction_results['transactions']);

                                foreach ($transaction_results as $transaction) {
                                    $transaction = Transaction::findOrFail($transaction['transaction_id']);
                                    $transaction->expense()->associate($expense);
                                    $transaction->save();
                                }
                            }
                        }
                    }
                } else {
                    //associate Expenses...
                }
            } //foreach $expenses
        }
    }

    public function add_transaction_to_multi_expenses()
    {
        dd('in add_transaction_to_multi_expenses');
        $hive_vendors = Vendor::hiveVendors()->get();
        foreach ($hive_vendors as $hive_vendor) {
            $hive_vendor_bank_account_ids = $hive_vendor->bank_accounts->pluck('id');

            //find Expenses per Vendor that have at least 2 expenses sin Transactions
            //associate expenses.. each Expense has the same Transaction
            $transactions = Transaction::
                // where('id', 20541)
                whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                    ->whereNull('expense_id')
                //whereDoesntHave payments
                    ->doesntHave('payments')
                    ->whereNull('check_number')
                // ->whereBetween('transaction_date', [$start_date, $end_date])

                //03/08/2023 floatDiffInDays dateDiff? orderBy faster I think?
                    ->orderBy('transaction_date', 'desc')
                    ->get();

            foreach ($transactions as $transaction) {
                $start_date = $transaction->transaction_date->subDays(7)->format('Y-m-d');
                $end_date = $transaction->transaction_date->addDays(7)->format('Y-m-d');

                $expenses =
                    Expense::whereNull('deleted_at')
                        ->where('belongs_to_vendor_id', $hive_vendor->id)
                        ->where('vendor_id', $transaction->vendor_id)
                        ->whereNull('paid_by')
                        ->whereDoesntHave('transactions')
                        ->whereBetween('date', [$start_date, $end_date])
                        ->get();

                //run subsetSums here, if any combination equals $transaction->amount, use those!
                if ($expenses->count() >= 2) {
                    //summy
                    //clear array before next foreach statement
                    $expense_resluts = [];

                    $expenses_ids = $expenses->pluck('id')->toArray();
                    $expenses_plucked = $expenses->pluck('amount')->toArray();

                    $arr = array_values(array_filter($expenses_plucked));
                    $n = count($arr);
                    $ids = $expenses_ids;

                    //model
                    $results = collect($this->subsetSums($arr, $n, $ids, 'expense'))->sortBy('sum');

                    foreach ($results as $key => $result) {
                        $sum = number_format($result['sum'], 2, '.', '');
                        //this can happen multiple of times.. eg transaction_id 6230

                        //is this Transaction a RETURN CHECK "DEPOSIT"?
                        if ($sum == $transaction->amount) {
                            $expense_resluts[] = $result;
                        }
                    }

                    $expense_resluts = collect($expense_resluts);

                    if (! $expense_resluts->isEmpty()) {
                        $expense_array = $expense_resluts[0]['expenses'];

                        foreach ($expense_array as $expense) {
                            // $transaction
                            $save_expense = Expense::findOrFail($expense['expense_id']);
                            $save_expense->transaction_id = $transaction->id;
                            $save_expense->save();
                        }
                    }
                }
            }
        }
    }

    public function add_check_id_to_transactions()
    {
        $checks =
            Check::withoutGlobalScopes()
                ->whereDoesntHave('transactions')
                ->whereNull('deleted_at')
                ->where('date', '>', '2021-01-01')
                ->orderBy('date', 'DESC')
                ->get();

        foreach ($checks as $check) {
            if ($check->check_type == 'Transfer') {
                $check_number = '1010101';
                $add_days = 14;
            } elseif ($check->check_type == 'Check') {
                $check_number = $check->check_number;
                $add_days = 180;
            } elseif ($check->check_type == 'Cash') {
                $check_number = '2020202';
                $add_days = 14;
            } else {
                Log::channel('add_check_id_to_transactions')->info($check);
                continue;
            }

            $bank_account_ids = $check->bank_account_id ? $check->bank_account->bank->accounts->pluck('id') : NULL;

            //$transactions match the check amount.
            $transactions = Transaction::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereNull('check_id')
                ->whereNull('expense_id')
                ->where('check_number', $check_number)
                ->when($bank_account_ids, function ($query, $bank_account_ids) {
                    return $query->whereIn('bank_account_id', $bank_account_ids);
                })
                ->whereBetween('transaction_date', [
                    $check->date->subDays(7)->format('Y-m-d'),
                    $check->date->addDays($add_days)->format('Y-m-d'),
                ])
                ->where('amount', $check->amount)
                ->orderBy('id', 'DESC')
                ->get();

            //if amount matches and is only one, that's the one
            if ($transactions->count() === 1) {
                $transactions->first()->check()->associate($check)->save();
            } elseif ($transactions->count() > 1) {
                // Pick the closest-by-days without mutating attributes
                $closest = $transactions
                    ->sortBy(fn ($t) => $t->transaction_date->diffInDays($check->date))
                    ->first();

                $closest?->check()->associate($check)->save();
                continue; // done with this check
            } else {
                if ($check->check_type === 'Transfer') {
                    $transactions_by_name = Transaction::withoutGlobalScopes()
                        ->whereNull('deleted_at')
                        // cannot use whereDoesntHave with withoutGlobalScopes
                        // ->whereDoesntHave('check')
                        ->whereNull('check_id')
                        ->where('check_number', $check_number)
                        //per hive vendor... checks table foreach bank_account_id
                        ->when($bank_account_ids, function ($query, $bank_account_ids) {
                            return $query->whereIn('bank_account_id', $bank_account_ids);
                        })
                        ->whereBetween('transaction_date', [
                            $check->date->subDays(7)->format('Y-m-d'),
                            $check->date->addDays($add_days)->format('Y-m-d'),
                        ])
                        ->orderBy('id', 'DESC')
                        ->get()
                        ->each(function ($transaction, $key) {
                            $transaction->transfer_name = substr($transaction->plaid_merchant_description, strpos($transaction->plaid_merchant_description, 'ORG ID') + 7);
                        })
                        ->groupBy('transfer_name');
            
                    foreach ($transactions_by_name as $transactions) {
                        //summy
                        //clear array before next foreach statement
                        if($check->user){
                            if (stristr($transactions[0]['transfer_name'], strtolower($check->user->first_name))) {

                                $transaction_results = [];
                                $transaction_ids = $transactions->pluck('id')->toArray();
                                $transaction_plucked = $transactions->pluck('amount')->toArray();

                                $arr = array_values(array_filter($transaction_plucked));
                                $n = count($arr);
                                $ids = $transaction_ids;

                                $results = collect($this->subsetSums($arr, $n, $ids, 'transaction'))->sortBy('sum');

                                foreach ($results as $key => $result) {
                                    $sum = number_format($result['sum'], 2, '.', '');
                                    //this can happen multiple of times.. eg transaction_id 6230

                                    //is this Transaction a RETURN CHECK "DEPOSIT"?
                                    if ($sum == $check->amount) {
                                        $transaction_results = $result;
                                    }
                                }

                                if (isset($transaction_results['transactions'])) {
                                    $transaction_results = collect($transaction_results['transactions']);

                                    foreach ($transaction_results as $transaction) {
                                        // $transaction = Transaction::findOrFail($transaction['transaction_id']);
                                        $transaction->check()->associate($check);
                                        $transaction->save();
                                    }
                                }
                            } else {
                                continue;
                            }
                        }else{
                            continue;
                        }
                    }
                } elseif ($check->check_type === 'Check') {
                    $transactions = Transaction::withoutGlobalScopes()
                        ->whereNull('deleted_at')
                        ->whereNull('check_id')
                        //per hive vendor... checks table foreach bank_account_id
                        ->when($bank_account_ids, function ($query, $bank_account_ids) {
                            return $query->whereIn('bank_account_id', $bank_account_ids);
                        })
                        ->whereBetween('transaction_date', [
                            $check->date->subDays(90)->format('Y-m-d'),
                            $check->date->addDays($add_days)->format('Y-m-d'),
                        ])
                        ->where('check_number', $check_number)
                        // ->where('amount', $check->amount)
                        ->orderBy('id', 'DESC')
                        ->get();

                    // dd($transactions);

                    foreach ($transactions as $transaction) {
                        //if $check->check_number is inside of $transaction->check_number, associate $check with $transaction
                        // if (strpos($transaction->check_number, $check->check_number) !== false) {
                        //     $transaction->check()->associate($check)->save();
                        // }
                        $transaction->check()->associate($check)->save();
                    }
                }
            }
        }

        $processedData = $this->getProcessedChecks();
        $processedIds = $processedData['processed_ids'];
        $lastProcessedId = $processedData['last_processed_id'];

        $checks = Check::withoutGlobalScopes()
            ->where(function ($query) use ($lastProcessedId, $processedIds) {
                $query->where('id', '>', $lastProcessedId) // Include checks with id > lastProcessedId
                    ->orWhereIn('id', $processedIds);    // Include previously processed IDs
            })
            ->whereHas('transactions') // Ensure the Check has related transactions
            ->where('date', '>', '2021-01-01')
            ->withSum('transactions', 'amount') // Calculate the sum of the related transactions' amount
            ->get()
            ->filter(function ($check) {
                return $check->transactions_sum_amount != $check->amount; // Filter in PHP
            });

        // Process the checks
        foreach ($checks as $check) {
            if ($check->check_type == 'Transfer') {
                $check_number = '1010101';
                $add_days = 14;
            } elseif ($check->check_type == 'Check') {
                $check_number = $check->check_number;
                $add_days = 180;
            } elseif ($check->check_type == 'Cash') {
                $check_number = '2020202';
                $add_days = 14;
            } else {
                Log::channel('add_check_id_to_transactions')->info($check);
                continue;
            }

            $bank_account_ids = $check->bank_account_id ? $check->bank_account->bank->accounts->pluck('id') : NULL;

            $transactions = Transaction::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereNull('check_id')
                ->whereNull('expense_id')
                ->whereNull('deposit')
                //11/23/2024 per hive vendor... checks table foreach bank_account_id
                // ->whereIn('bank_account_id', $check->bank_account_id ? $check->bank_account->bank->accounts->pluck('id') : [NULL])
                ->when($bank_account_ids, function ($query, $bank_account_ids) {
                    return $query->whereIn('bank_account_id', $bank_account_ids);
                })
                ->whereBetween('transaction_date', [
                    $check->date->format('Y-m-d'),
                    $check->date->addDays($add_days)->format('Y-m-d'),
                ])
                ->where('amount', $check->amount_difference)
                ->get();

            if($transactions->isEmpty()){
                // Add the check ID to the processed IDs only if it's not already there
                if (!in_array($check->id, $processedIds)) {
                    $processedIds[] = $check->id;
                }
            } else {
                foreach ($transactions as $transaction) {
                    $transaction->check()->associate($check)->save();
                }

                // Remove the check ID from processed IDs if transaction was found and processed successfully
                $processedIds = array_filter($processedIds, function($id) use ($check) {
                    return $id !== $check->id;
                });
                // Re-index the array to maintain proper structure
                $processedIds = array_values($processedIds);
            }
        }

        // Save the processed IDs and the last processed ID
        if ($checks->isNotEmpty()) {
            $lastProcessedId = $checks->last()->id;
        }

        // Remove duplicates from processedIds before saving
        $processedIds = array_unique($processedIds);
        $processedIds = array_values($processedIds); // Re-index array

        $this->saveProcessedChecks($processedIds, $lastProcessedId);
    }

    function getProcessedChecks()
    {
        // Check if the file exists
        if (!Storage::exists('processed_checks.json')) {
            // Create the file with an empty structure if it doesn't exist
            Storage::put('processed_checks.json', json_encode(['processed_ids' => [], 'last_processed_id' => 0]));
        }

        // Read and decode the JSON file
        return json_decode(Storage::get('processed_checks.json'), true);
    }

    function saveProcessedChecks(array $processedIds, $lastProcessedId)
    {
        // Prepare the data to save
        $data = [
            'processed_ids' => $processedIds,
            'last_processed_id' => $lastProcessedId,
        ];

        // Write the data to the file
        Storage::put('processed_checks.json', json_encode($data));
    }

    public function add_payments_to_transaction()
    {
        //where doesnt have clientpayment
        //1-26-2023 why does 2019/older transactions/client_payments not work?
        $transactions = Transaction::where('transaction_date', '>', '2019-01-01')
            // ->where('deposit', 1)
            ->whereDoesntHave('payments')
            ->whereNull('expense_id')
            ->where('amount', 'LIKE', '-%') // Only get negative transactions
            ->orderBy('transaction_date', 'DESC')
            ->take(3)
            ->get();

        foreach ($transactions as $transaction) {
            $vendor_id = $transaction->bank_account->bank->vendor_id;

            $payments = Payment::
                whereBetween('date', [$transaction->transaction_date->subDays(21), $transaction->transaction_date->addDays(4)])
                //where bank_id belongs_to same vendor_id as this payment
                    ->where('belongs_to_vendor_id', $vendor_id)
                    ->whereNull('transaction_id');

            //06-21-2021 json store which $transactions have been checked against which $payments so it doesnt check again?
            //where parent_client_payment_id is not in json for this $transaction
            // ->groupBy('parent_client_payment_id');

            // if first character is -
            $single_payments = $payments->where('amount', is_numeric(substr($transaction->amount, 0, 1)) ? '-'.$transaction->amount : substr($transaction->amount, 1))->orderBy('date', 'DESC')->get();

            if ($single_payments->isNotEmpty()) {
                //closest date. diffInDays
                $save_payment = $single_payments->first(); // Just use the first match without date comparison
                
                // Associate and save in one step using the relationship
                $transaction->payments()->save($save_payment);
                
                //so Searchable gets send to Scout/TypeSense
                $transaction->save();
            } else {
                $payments = Payment::whereBetween('date', [$transaction->transaction_date->subDays(21), $transaction->transaction_date->addDays(4)])
                    //where bank_id belongs_to same vendor_id as this payment
                    ->where('belongs_to_vendor_id', $vendor_id)
                    ->where('transaction_id', null)
                    ->get();
                // dd($payments);
                if (! $payments->isEmpty()) {
                    //try any of $payments->payment_total ($payment->sum('amount')) == $transaction->amount? if so and only one result..that's our guy.

                    //clear array before next foreach statement
                    $payment_results = [];

                    $client_payment_ids = $payments->pluck('id')->toArray();
                    $client_payments_plucked = $payments->pluck('amount')->toArray();

                    $arr = array_values(array_filter($client_payments_plucked));
                    $n = count($arr);
                    $ids = $client_payment_ids;

                    $results = collect($this->subsetSums($arr, $n, $ids, 'client_payment'))->sortBy('sum');
                    // dd($results);

                    foreach ($results as $key => $result) {
                        $sum = number_format($result['sum'], 2, '.', '');
                        //this can happen multiple of times.. eg transaction_id 6230

                        //is this Transaction a RETURN CHECK "DEPOSIT"?
                        if ($sum === substr($transaction->amount, 1) or $sum === '-'.$transaction->amount) {
                            $payment_results[] = $result;
                        } else {
                            //06/10/2021 if not found... create json array for $transaction with all parent_client_payment_id s so that we dont have to run this heavy program for those payments again.
                            //06/10/2021 we do the above line already with add_transactions_to_expenses... data is put into database... need it here too
                        }
                    }

                    $payment_results = collect($payment_results);
                    // dd($payment_results);

                    if (! $payment_results->isEmpty()) {
                        $payment_array = $payment_results[0]['client_payments'];

                        foreach ($payment_array as $payment) {
                            $save_payment = Payment::findOrFail($payment['client_payment_id']);
                            $save_payment->transaction_id = $transaction->id;
                            $save_payment->save();

                            //so Searchable gets send to Scout/TypeSense
                            $transaction->save();
                            // $payments->fresh();
                        }
                    }
                }
            }
        }
    }

    //find expenses with NO VENDOR that match transactions
    public function add_transaction_to_expenses_sin_vendor()
    {
        $expenses = Expense::with('receipts')->where('vendor_id', 0)->get();
        $vendor_desc = VendorTransaction::all();

        foreach ($expenses as $expense) {
            $receipt = $expense->receipts()->latest()->first();
            if (isset($receipt->receipt_items->merchant_name)) {
                $merchant_name = $receipt->receipt_items->merchant_name;
                $vendor = $vendor_desc->where('desc', $merchant_name)->first();

                if ($vendor) {
                    $expense->vendor_id = $vendor->vendor_id;
                    $expense->save();
                }else{
                    $vendors = Vendor::withoutGlobalScopes()->where('business_type', 'Retail')->get();
                    $vendor_match = app(\App\Http\Controllers\CompanyEmailController::class)->fuzzyMatchVendor($merchant_name, $vendors);

                    if ($vendor_match) {
                        $expense->vendor_id = $vendor_match->id;
                        $expense->save();
                    }
                }
            }

            $matching_transaction =
                Transaction::where('amount', $expense->amount)
                    ->whereNull('expense_id')
                    ->get()
                    ->each(function ($item) use ($expense) {
                        $item->date_diff = $expense->date->floatDiffInDays($item->transaction_date);
                    })
                    ->sortBy('date_diff')
                    ->first();

            if (isset($matching_transaction->vendor_id)) {
                $expense->vendor_id = $matching_transaction->vendor_id;
                $expense->save();

                $matching_transaction = Transaction::findOrFail($matching_transaction->id);
                $matching_transaction->expense()->associate($expense);
                $matching_transaction->save();
            }
        }
    }

    public function transactions_sum_not_expense_amount()
    {
        dd('in transactions_sum_not_expense_amount');
        ini_set('max_execution_time', '480000');
        $expenses =
            Expense::whereHas('transactions')->withSum('transactions', 'amount')->get();

        foreach ($expenses as $key => $expense) {
            if ($expense->amount == $expense->transactions_sum_amount) {
                $expenses->forget($key);
            }
        }

        dd($expenses->pluck('id'));

        $expenses = Expense::withWhereHas('transactions', function ($query) {
            $query->whereNotNull('check_id');
        })->get();
        dd($expenses);
    }

    // Iterative PHP program to print
    // sums of all possible subsets.
    // Prints sums of all subsets of array
    public function subsetSums($arr, $n, $ids, $model)
    {
        // dd([$arr, $n, $ids, $model]);
        ini_set('max_execution_time', 600000);
        // There are totoal 2^n subsets
        $total = 1 << $n;
        // $sums = array();

        // Consider all numbers
        // from 0 to 2^n - 1
        for ($i = 0; $i < $total; $i++) {
            $sum = 0;
            $summy = [];
            // Consider binary reprsentation of
            // current i to decide which elements
            // to pick.
            for ($j = 0; $j < $n; $j++) {
                if ($i & (1 << $j)) {
                    $sum += $arr[$j];
                    //1/3/24 client_payment_id should be id of Model being send here
                    $summy[] = ['subtotal' => $arr[$j], $model.'_id' => $ids[$j]];
                }
            }

            // Print sum of picked elements.
            // echo $sum , " ";
            if ($sum != 0) {
                $summys[] = ['sum' => $sum, $model.'s' => $summy];
            }
        }

        return $summys;
    }

    public function find_credit_payments_on_debit()
    {
        //group bank_accounts per vendor
        $vendors_credit_bank_accounts =
            BankAccount::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereIn('type', ['Credit', 'Credit Card'])
                ->get()
                ->groupBy('vendor_id');

        foreach ($vendors_credit_bank_accounts as $vendor_id => $vendor_bank_accounts) {
            $vendor = Vendor::findOrFail($vendor_id);
            $vendor_office_distribution_id = Distribution::withoutGlobalScopes()->where('vendor_id', $vendor_id)->where('name', 'OFFICE')->first()->id;
            $bank_account_ids = $vendors_credit_bank_accounts[$vendor_id]->pluck('id');

            $vendors_debit_bank_accounts =
            BankAccount::withoutGlobalScopes()
                ->where('vendor_id', $vendor_id)
                ->where('deleted_at', null)
                ->where('type', 'Checking')
                ->get();

            $credit_transactions =
                Transaction::where('check_id', null)
                    // ->where('id', 25137)
                    ->where('expense_id', null)
                    ->where('check_number', null)
                    ->where('deposit', null)
                    ->whereNotNull('vendor_id')
                    ->whereDate('transaction_date', '>=', '2022-10-07')
                    //where bank_id_account is a credit card for this user
                    ->whereIn('bank_account_id', $bank_account_ids)
                    ->orderBy('transaction_date', 'ASC')
                    ->get();
            // dd($credit_transactions);

            foreach ($credit_transactions as $credit_transaction) {
                $debit_transactions =
                    Transaction::where('amount', substr($credit_transaction->amount, 1))
                        ->where('vendor_id', $credit_transaction->vendor_id)
                        ->whereIn('bank_account_id', $vendors_debit_bank_accounts->pluck('id')) //and where bank_type = DEBIT
                        ->whereNull('expense_id')
                        //->subDays(2)
                        ->whereBetween('transaction_date', [$credit_transaction->transaction_date, $credit_transaction->transaction_date->addDays(10)])
                        //where what else?
                        ->get();
                // dd($debit_transactions);

                //can we add a Model attribute in the above Where statement?! --I dont think so
                foreach ($debit_transactions as $debit_transaction) {
                    $debit_transaction->date_diff = $credit_transaction->transaction_date->floatDiffInDays($debit_transaction->transaction_date);
                }

                $debit_transaction = $debit_transactions->sortBy('date_diff')->first();

                if (is_null($debit_transaction)) {
                    // continue;
                } else {
                    //create new expenses with associated_expense_id
                    //CREDIT CARD TRANSACTION
                    $credit_expense = Expense::create([
                        'distribution_id' => $vendor_office_distribution_id,
                        'created_by_user_id' => 0,
                        'amount' => $credit_transaction->amount,
                        'date' => $credit_transaction->transaction_date,
                        'vendor_id' => $credit_transaction->vendor_id,
                        'belongs_to_vendor_id' => $vendor->id,
                    ]);

                    $credit_transaction->expense()->associate($credit_expense);
                    $credit_transaction->save();

                    //DEBIT CARD TRANSACTION
                    $debit_expense = Expense::create([
                        'distribution_id' => $vendor_office_distribution_id,
                        'created_by_user_id' => 0,
                        'amount' => $debit_transaction->amount,
                        'date' => $debit_transaction->transaction_date,
                        'vendor_id' => $debit_transaction->vendor_id,
                        'belongs_to_vendor_id' => $vendor->id,
                    ]);

                    $debit_expense->parent_expense_id = $credit_expense->id;
                    $debit_expense->save();

                    $debit_transaction = Transaction::find($debit_transaction->id);
                    $debit_transaction->expense()->associate($debit_expense);
                    $debit_transaction->save();
                }
            }
        }
    }

    public function transaction_vendor_bulk_match_splits($match, $expense, $amount)
    {
        $all_previous_splits = [];
        if (isset($match->options['splits'])) {
            foreach (collect($match->options['splits']) as $index => $split) {
                if ($split['amount_type'] == '%') {
                    $percent = $split['amount']; //2 decimals required
                    $percent_amount = $amount * $percent;

                    if ($index === array_key_last($match->options['splits'])) {
                        // dd(collect($test_splits)->sum('amount'));
                        // // $split_amount = round($per_cent, 2);
                        // dd(collect($all_previous_splits)->sum('amount'));
                        $split_amount = round($amount - collect($all_previous_splits)->sum('amount'), 2);
                    } else {
                        $split_amount = round($percent_amount, 2);
                        $all_previous_splits[$index]['amount'] = $split_amount;
                    }
                } else {
                    $split_amount = $split['amount'];
                }

                $split = ExpenseSplits::create([
                    'amount' => $split_amount,
                    'expense_id' => $expense->id,
                    'project_id' => null,
                    'distribution_id' => $split['distribution_id'],
                    'reimbursment' => null,
                    'note' => null,
                    'belongs_to_vendor_id' => $match->belongs_to_vendor_id,
                    'created_by_user_id' => 0,
                ]);
            }
        }
    }

    public function transaction_vendor_bulk_match()
    {
        $vendor_receipt_accounts = ReceiptAccount::withoutGlobalScopes()->with('vendor')->get()->groupBy('belongs_to_vendor_id');

        foreach($vendor_receipt_accounts as $vendor_id => $receipt_accounts){
            // Retrieve the bank_account_ids once for the vendor
            $bank_account_ids = $receipt_accounts->first()->belongs_to_vendor->bank_accounts->pluck('id')->toArray();

            foreach($receipt_accounts as $receipt_account){
                //dont create if receiptaccount has receipts (email/api receipt capture)
                // if($receipt_account->vendor->receipts->isNotEmpty()){
                //     continue;
                // }

                //if no bulk matches for this vendor dont create an expense from just a transaction, wait for a bulk match or expense with receipt.
                if($receipt_account->vendor->transactions_bulk_match->isEmpty()){
                    // $transactions =
                    //     Transaction::withoutGlobalScopes()
                    //         ->whereNull('deleted_at')
                    //         ->whereIn('bank_account_id', $bank_account_ids)
                    //         ->where('vendor_id', $receipt_account->vendor->id)
                    //         //when $receipt_account->vendor->receipts->isNotEmpty()
                    //         ->whereDoesntHave('expense')

                    //         ->whereNull('check_number')
                    //         ->get();
                    // dd($transactions);

                    // foreach($transactions as $transaction){
                    //     //Find Duplicates $expense = $duplicate
                    //     //date diff
                    //     $duplicate_start_date = $transaction->transaction_date->subDays(1)->format('Y-m-d');
                    //     $duplicate_end_date = $transaction->transaction_date->addDays(4)->format('Y-m-d');

                    //         //     //find duplicate expenses
                    //     $duplicates =
                    //         Expense::where('belongs_to_vendor_id', $transaction->bank_account->bank->vendor_id)->
                    //             whereNull('deleted_at')->
                    //             where('amount', $transaction->amount)->
                    //             whereBetween('date', [$duplicate_start_date, $duplicate_end_date])->
                    //             get();

                    //     if ($duplicates->count() >= 1) {
                    //         foreach ($duplicates as $duplicate) {
                    //             $duplicate->date_diff = $transaction->transaction_date->floatDiffInDays($duplicate->date);
                    //         }

                    //         $expense_duplicate = $duplicates->sortBy('date_diff')->first();
                    //         $expense = $expense_duplicate;
                    //     } else {
                    //         $expense = Expense::create([
                    //             'amount' => $transaction->amount,
                    //             'date' => $transaction->transaction_date,
                    //             'project_id' => null,
                    //             //if splits distribution_id = NULL
                    //             'distribution_id' => $receipt_account->distribution_id,
                    //             'vendor_id' => $receipt_account->vendor_id,
                    //             'check_id' => null,
                    //             'paid_by' => null,
                    //             'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                    //             'created_by_user_id' => 0,
                    //         });
                    //     }

                    //     $transaction->expense_id = $expense->id;
                    //     $transaction->save();
                    // }
                }else{
                    foreach($receipt_account->vendor->transactions_bulk_match as $match){
                        $transactions =
                            Transaction::withoutGlobalScopes()
                                ->whereNull('deleted_at')
                                ->whereIn('bank_account_id', $bank_account_ids)
                                ->where('vendor_id', $match->vendor_id)
                                ->whereDoesntHave('expense')
                                ->whereNull('check_number')
                                ->when($match->amount != null, function ($query) use ($match) {
                                    return $query->where('amount', isset($match->options['amount_type']) ? $match->options['amount_type'] : '=', $match->amount);
                                })
                                ->when(isset($match->options['desc']), function ($query) use ($match) {
                                    return $query->where('plaid_merchant_description', $match->options['desc']);
                                })
                                ->get();

                        //create new expense foreach transaction
                        foreach($transactions as $transaction){
                            //Find Duplicates $expense = $duplicate
                            //date diff
                            $duplicate_start_date = $transaction->transaction_date->subDays(1)->format('Y-m-d');
                            $duplicate_end_date = $transaction->transaction_date->addDays(4)->format('Y-m-d');

                            //find duplicate expenses
                            $duplicates =
                                Expense::where('belongs_to_vendor_id', $transaction->bank_account->bank->vendor_id)->
                                    whereNull('deleted_at')->
                                    where('amount', $transaction->amount)->
                                    whereBetween('date', [$duplicate_start_date, $duplicate_end_date])->
                                    get();

                            if ($duplicates->count() >= 1) {
                                foreach ($duplicates as $duplicate) {
                                    $duplicate->date_diff = $transaction->transaction_date->floatDiffInDays($duplicate->date);
                                }

                                $expense_duplicate = $duplicates->sortBy('date_diff')->first();
                                $expense = $expense_duplicate;
                            } else {
                                $expense = Expense::create([
                                    'amount' => $transaction->amount,
                                    'date' => $transaction->transaction_date,
                                    'project_id' => null,
                                    //if splits distribution_id = NULL
                                    'distribution_id' => $match->distribution_id,
                                    'vendor_id' => $transaction->vendor_id,
                                    'check_id' => null,
                                    'paid_by' => null,
                                    'belongs_to_vendor_id' => $match->belongs_to_vendor_id,
                                    'created_by_user_id' => 0,
                                ]);

                                //splits
                                $this->transaction_vendor_bulk_match_splits($match, $expense, $transaction->amount);
                            }

                            $transaction->expense_id = $expense->id;
                            $transaction->save();
                        }
                    }
                }
            }
        }
    }
}
