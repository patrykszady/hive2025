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
use Illuminate\Support\Facades\Cache;
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

            if (($result['error'] ?? false) === true) {
                $error = ['error' => $result];
            } elseif (!empty($result['item']['error'])) {
                $error = ['error' => $result['item']['error']];
            } else {
                $transactionsStatus = $result['status']['transactions'] ?? [];
                $lastFailedUpdate = $transactionsStatus['last_failed_update'] ?? null;
                $lastSuccessfulUpdate = $transactionsStatus['last_successful_update'] ?? null;

                if ($lastFailedUpdate && $lastSuccessfulUpdate) {
                    $lastFailed = Carbon::parse($lastFailedUpdate);
                    $lastSuccessful = Carbon::parse($lastSuccessfulUpdate);

                    $difference = $lastFailed->diff($lastSuccessful);
                    $difference = ['before' => $difference->invert, 'diff_in_days' => $difference->days];

                    if ($difference['before'] === 1 && $difference['diff_in_days'] > 3) {
                        $error = ['error' => ['error_type' => 'ITEM_ERROR', 'error_code' => 'NO_TRANSACTIONS', 'error_message' => 'No New Transactions in over 3 days. Please UPDATE BANK.']];
                    } else {
                        $error = ['error' => false];
                    }
                } else {
                    $error = ['error' => false];
                }
            }

            //if error is false, check for errors on the bank transactions
            // if (!$error['error']) {
            //     $result_bank_transactions = $this->syncBankTransactions($bank);

            //     if (isset($result_bank_transactions['error_code'])) {
            //         $error = ['error' => $result_bank_transactions];
            //         $result = [];
            //     } else {
            //         $error = ['error' => false];
            //     }
            // }

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
        // This method has been replaced by the Plaid statements functionality in AuditShow Livewire component
        // Using PlaidService->getStatements() and PlaidService->downloadStatement()
        
        return redirect()->route('audit.show')->with('message', 'Please use the bank statements download feature in the audit page.');
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
        
        // Auto-link to a Check by check_number + bank_account + amount.
        // Plaid sometimes also stamps a merchant_name on a check transaction (e.g. low-confidence
        // category matches), so we run this independently of vendor logic. The check wins.
        if (empty($transaction->check_id) && ! empty($transaction->check_number) && ! empty($transaction->bank_account_id)) {
            $matchedCheck = \App\Models\Check::withoutGlobalScopes()
                ->where('bank_account_id', $transaction->bank_account_id)
                ->where('check_number', $transaction->check_number)
                ->where('amount', $transaction->amount)
                ->whereNull('deleted_at')
                ->get();

            if ($matchedCheck->count() === 1) {
                $check = $matchedCheck->first();
                $transaction->check_id = $check->id;
                $transaction->vendor_id = $check->vendor_id;
            }
        }

        // Auto-assign vendor based on Plaid merchant data if available and not already set
        // Only do this for new transactions or when updating without a vendor
        // Skip checks - they should not be auto-matched to vendors
        if (empty($transaction->vendor_id) && !empty($transaction->plaid_merchant_name) && empty($transaction->check_number)) {
            $vendors = Vendor::withoutGlobalScopes()->where('business_type', 'Retail')->get();
            $vendor_match = app(\App\Http\Controllers\CompanyEmailController::class)->fuzzyMatchVendor($transaction->plaid_merchant_name, $vendors);
            
            if ($vendor_match) {
                $transaction->vendor_id = $vendor_match->id;
            }
        }
        
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
        $cutoff = Carbon::now()->subMonth();

        $hive_vendors = Vendor::hiveVendors()->get();
        $categories = Category::all();
        foreach ($hive_vendors as $hive_vendor) {
            $hive_vendor_bank_account_ids = $hive_vendor->bank_accounts->pluck('id');
            $vendors_with_category = Vendor::withoutGlobalScopes()->whereHas('category')->get();

            $transactions =
                Transaction::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                    ->whereHas('expense', function ($query) use ($cutoff) {
                        return $query->whereDoesntHave('category')
                            ->where('created_at', '>=', $cutoff);
                    })
                    ->with(['expense.vendor.category'])
                    ->get();

            foreach ($transactions as $transaction) {
                if (!$transaction->expense) { continue; }

                // Prefer the vendor's category if present; otherwise leave for next pass
                $vendorCategory = optional(optional($transaction->expense)->vendor)->category;
                if ($vendorCategory) {
                    if ((int) $transaction->expense->category_id !== (int) $vendorCategory->id) {
                        $transaction->expense->category()->associate($vendorCategory);
                        $transaction->expense->save();
                    }
                }
            }

            $transactions =
                Transaction::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                    ->whereNotNull('details')
                    ->whereHas('expense', function ($query) use ($cutoff) {
                        return $query->whereDoesntHave('category')
                            ->where('created_at', '>=', $cutoff);
                    })
                    ->with(['expense.vendor.category'])
                    ->get();

            foreach ($transactions as $transaction) {
                if ($transaction->expense && ! $transaction->expense->category) {
                    // 1) Use vendor's category if available
                    $vendorCategory = optional(optional($transaction->expense)->vendor)->category;
                    if ($vendorCategory) {
                        if ((int) $transaction->expense->category_id !== (int) $vendorCategory->id) {
                            $transaction->expense->category()->associate($vendorCategory);
                            $transaction->expense->save();
                        }
                        continue;
                    }

                    // 2) Override: map transaction name to category (exact or prefix match)
                    $transactionName = $transaction->details['name'] ?? null;
                    if ($transactionName) {
                        $nameOverride = $this->resolveNameOverride($transactionName, $categories);
                        if ($nameOverride) {
                            if ((int) $transaction->expense->category_id !== (int) $nameOverride->id) {
                                $transaction->expense->category()->associate($nameOverride);
                                $transaction->expense->save();
                            }
                            continue;
                        }
                    }

                    // 3) Otherwise, map from Plaid detailed category
                    $transaction_category = $transaction->details['personal_finance_category']['detailed'] ?? null;
                    if ($transaction_category) {
                        $category = $categories->where('detailed', $transaction_category)->first();
                        if ($category) {
                            if ((int) $transaction->expense->category_id !== (int) $category->id) {
                                $transaction->expense->category()->associate($category);
                                $transaction->expense->save();
                            }
                        }
                    }
                }

                if ($transaction->check) {
                    foreach ($transaction->check->expenses as $expense) {
                        if ($expense->category) { continue; }

                        if ($expense->created_at?->lt($cutoff)) {
                            continue;
                        }

                        // Prefer the expense vendor category; else fallback to the transaction's expense category; else Plaid mapping
                        $expenseVendorCategory = optional($expense->vendor)->category;
                        if ($expenseVendorCategory) {
                            if ((int) $expense->category_id !== (int) $expenseVendorCategory->id) {
                                $expense->category()->associate($expenseVendorCategory);
                                $expense->save();
                            }
                            continue;
                        }

                        if ($transaction->expense && $transaction->expense->category) {
                            if ((int) $expense->category_id !== (int) $transaction->expense->category_id) {
                                $expense->category()->associate($transaction->expense->category);
                                $expense->save();
                            }
                            continue;
                        }

                        $transaction_category = $transaction->details['personal_finance_category']['detailed'] ?? null;
                        if ($transaction_category) {
                            $category = $categories->where('detailed', $transaction_category)->first();
                            if ($category) {
                                if ((int) $expense->category_id !== (int) $category->id) {
                                    $expense->category()->associate($category);
                                    $expense->save();
                                }
                            }
                        }
                    }
                }
            }

            // Check-only transactions (expense_id=null but check_id set): categorize the check's expenses
            $checkOnlyTransactions =
                Transaction::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                    ->whereNotNull('check_id')
                    ->whereNull('expense_id')
                    ->whereNotNull('details')
                    ->where('created_at', '>=', $cutoff)
                    ->with(['check.expenses.vendor.category'])
                    ->get();

            foreach ($checkOnlyTransactions as $transaction) {
                if (!$transaction->check) { continue; }

                foreach ($transaction->check->expenses as $expense) {
                    if ($expense->category) { continue; }
                    if ($expense->created_at?->lt($cutoff)) { continue; }

                    // 1) Vendor category
                    $expenseVendorCategory = optional($expense->vendor)->category;
                    if ($expenseVendorCategory) {
                        if ((int) $expense->category_id !== (int) $expenseVendorCategory->id) {
                            $expense->category()->associate($expenseVendorCategory);
                            $expense->save();
                        }
                        continue;
                    }

                    // 2) Plaid category from the check's transaction
                    $transaction_category = $transaction->details['personal_finance_category']['detailed'] ?? null;
                    if ($transaction_category) {
                        $category = $categories->where('detailed', $transaction_category)->first();
                        if ($category) {
                            if ((int) $expense->category_id !== (int) $category->id) {
                                $expense->category()->associate($category);
                                $expense->save();
                            }
                        }
                    }
                }
            }

            // Pass 4a: vendor already has a default category_id — apply directly, no date restriction needed
            $vendorDirectExpenses =
                Expense::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where('belongs_to_vendor_id', $hive_vendor->id)
                    ->where('created_at', '>=', $cutoff)
                    ->whereDoesntHave('category')
                    ->whereHas('vendor', function ($q) {
                        $q->whereNotNull('category_id');
                    })
                    ->with('vendor')
                    ->get();

            foreach ($vendorDirectExpenses as $directExpense) {
                $directVendorCategoryId = $directExpense->vendor->category_id ?? null;
                if ($directVendorCategoryId) {
                    $directExpense->category_id = $directVendorCategoryId;
                    $directExpense->save();
                }
            }

            $vendors_expenses =
                Expense::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where('belongs_to_vendor_id', $hive_vendor->id)
                    ->where('created_at', '>=', $cutoff)
                    ->whereBetween('date', ['2021-01-01', Carbon::now()->subDays(6)->format('Y-m-d')])
                    ->whereDoesntHave('category')
                    ->get()
                    ->groupBy('vendor_id');

            foreach ($vendors_expenses as $vendor_id => $vendor_expenses) {
                $expenses =
                    Expense::withoutGlobalScopes()
                        ->where('belongs_to_vendor_id', $hive_vendor->id)
                        ->where('created_at', '>=', $cutoff)
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

                if (empty($category)) {
                    continue;
                }

                $expenses
                    ->where(function ($query) use ($category) {
                        $query->whereNull('category_id')
                            ->orWhere('category_id', '!=', $category);
                    })
                    ->update(['category_id' => $category]);
            }
        }
    }

    /**
     * Transaction name-to-category overrides.
     * Exact matches are checked first, then prefix (startsWith) matches.
     *
     * @return array<string, string> name pattern => category detailed code
     */
    private function transactionNameOverrides(): array
    {
        return [
            // Exact matches
            'PAST DUE FEE' => 'BANK_FEES_LATE_FEES',
            'INTEREST CHARGE:PURCHASES' => 'BANK_FEES_INTEREST_CHARGE',
            'INTEREST CHARGE ADJUSTMENT' => 'INCOME_INTEREST_EARNED',

            // Prefix matches (checked via str_starts_with)
            'CAPITAL ONE MOBILE PYMT' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT',
            'CAPITAL ONE AUTOPAY PYMT' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT',
            'CAPITAL ONE ONLINE PYMT' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT',
            'CAPITAL ONE CRCARDPMT' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT',
            'CAPITAL ONE MEMBER FEE' => 'BANK_FEES_OTHER_BANK_FEES',
            'OTHER DECREASE CAPITAL ONE' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT',
        ];
    }

    /**
     * Resolve a category override from transaction name.
     */
    private function resolveNameOverride(string $name, $categories): ?Category
    {
        foreach ($this->transactionNameOverrides() as $pattern => $detailed) {
            if ($name === $pattern || str_starts_with($name, $pattern)) {
                return $categories->where('detailed', $detailed)->first();
            }
        }

        return null;
    }

    /**
     * Soft-delete $0.00 posted Plaid transactions that carry no value.
     * Mirrors the check in Transaction::booted() saved event, but sweeps
     * any records that slipped through (e.g. synced before the event existed).
     */
    public function cleanup_zero_transactions(): int
    {
        return Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('posted_date')
            ->where('amount', '0.00')
            ->whereNotNull('plaid_transaction_id')
            ->delete();
    }

    /**
     * Build a map of distinctive vendor-name tokens to the vendor ids that use them.
     * Generic corporate words and short tokens are excluded so only meaningful,
     * identifying tokens (e.g. "northbrook", "menards") are considered.
     *
     * @param  iterable<\App\Models\Vendor>  $vendors
     * @return array<string, array<int, int>>
     */
    protected function buildVendorTokenOwners(iterable $vendors): array
    {
        $generic = [
            'village', 'company', 'limited', 'holding', 'holdings', 'services', 'service',
            'medical', 'dental', 'capital', 'financial', 'property', 'properties', 'management',
            'solutions', 'systems', 'national', 'international', 'american', 'general', 'center',
            'centre', 'supply', 'rental', 'rentals', 'market', 'markets', 'store', 'stores',
        ];

        $owners = [];
        foreach ($vendors as $vendor) {
            $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower((string) $vendor->business_name, 'UTF-8')) ?? '';
            foreach (array_filter(preg_split('/\s+/', trim($name)) ?: []) as $token) {
                if (mb_strlen($token) < 6 || in_array($token, $generic, true)) {
                    continue;
                }
                $owners[$token][(int) $vendor->id] = (int) $vendor->id;
            }
        }

        return $owners;
    }

    /**
     * Determine whether a transaction description distinctly names a vendor OTHER than the
     * one matched from plaid_merchant_name. Returns true only when the description shares a
     * distinctive token with a different vendor and shares none with the matched vendor.
     *
     * @param  array<string, array<int, int>>  $vendorTokenOwners
     */
    protected function descriptionNamesDifferentVendor(string $description, int $matchedVendorId, array $vendorTokenOwners): bool
    {
        if ($description === '' || $vendorTokenOwners === []) {
            return false;
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($description, 'UTF-8')) ?? '';
        $hasDifferentVendor = false;

        foreach (array_filter(preg_split('/\s+/', trim($normalized)) ?: []) as $token) {
            if (mb_strlen($token) < 6 || !isset($vendorTokenOwners[$token])) {
                continue;
            }
            if (isset($vendorTokenOwners[$token][$matchedVendorId])) {
                // Description shares a distinctive token with the matched vendor -> consistent.
                return false;
            }
            $hasDifferentVendor = true;
        }

        return $hasDifferentVendor;
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

        $vendors = Vendor::withoutGlobalScopes()->where('business_type', 'Retail')->get();

        // Map of distinctive vendor-name tokens to the vendor ids that use them. Used to
        // detect when a transaction's description clearly names a DIFFERENT vendor than the
        // one fuzzy-matched from plaid_merchant_name (Plaid occasionally reports a clean
        // merchant_name that contradicts the raw description).
        $vendorTokenOwners = $this->buildVendorTokenOwners($vendors);

        // PART 1: Process transactions WITHOUT vendors
        // First try matching on plaid_merchant_description (more specific), then fall back to plaid_merchant_name
        $transactionsWithoutVendor = Transaction::TransactionsSinVendor()->whereIn('bank_account_id', $bankAccountIds)->get();

        // Transaction types that should NOT be matched to retail vendors
        // These are typically bank transfers, not purchases
        $skipPatterns = [
            '/\bZELLE\b/i',
            '/\bVENMO\b/i',
            '/\bTRANSFER\b/i',
            '/\bWIRE\b/i',
            '/\bACH\b/i',
            '/\bDIRECT DEPOSIT\b/i',
            '/\bPAYROLL\b/i',
            '/\bOTHER DECREASE\b/i',
            '/\bOTHER INCREASE\b/i',
        ];

        foreach ($transactionsWithoutVendor as $transaction) {
            $vendor_match = null;
            
            // Skip if description indicates this is a transfer/non-purchase transaction
            $description = $transaction->plaid_merchant_description ?? '';
            $isTransfer = false;
            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $description)) {
                    $isTransfer = true;
                    break;
                }
            }
            
            // Don't assign retail vendors to transfer transactions
            if ($isTransfer) {
                continue;
            }

            // First try matching the more specific plaid_merchant_description
            $matchedViaName = false;
            if (!empty($transaction->plaid_merchant_description)) {
                $vendor_match = app(\App\Http\Controllers\CompanyEmailController::class)->fuzzyMatchVendor($transaction->plaid_merchant_description, $vendors);
            }

            // Fall back to plaid_merchant_name if no match found
            if (!$vendor_match && !empty($transaction->plaid_merchant_name)) {
                $vendor_match = app(\App\Http\Controllers\CompanyEmailController::class)->fuzzyMatchVendor($transaction->plaid_merchant_name, $vendors);
                $matchedViaName = (bool) $vendor_match;
            }

            if ($vendor_match) {
                // Sanity check: don't assign a vendor whose name doesn't appear in the
                // plaid merchant name/description. Without this, PART 2 below would just
                // clear it again on the next run, causing an infinite oscillation
                // (see "Cleared mismatched vendor" log spam).
                $vName = strtolower($vendor_match->business_name);
                $pName = strtolower($transaction->plaid_merchant_name ?? '');
                $pDesc = strtolower($transaction->plaid_merchant_description ?? '');
                $namesOverlap = ($vName !== '' && $pName !== '' && (stripos($pName, $vName) !== false || stripos($vName, $pName) !== false))
                    || ($vName !== '' && $pDesc !== '' && stripos($pDesc, $vName) !== false);

                // Conflict guard: when the vendor was matched ONLY via plaid_merchant_name,
                // refuse the assignment if the description distinctly names a different
                // vendor. Plaid sometimes reports a contradictory merchant_name (e.g.
                // name "GitHub" on a transaction whose description is "NORTHBROOK VLG MISC");
                // without this, the ANY-amount bulk match would later sweep the transaction
                // into the wrong vendor's expense. Biases toward leaving it for manual review
                // rather than producing a cross-vendor match.
                $descNamesDifferentVendor = $matchedViaName
                    && $this->descriptionNamesDifferentVendor($pDesc, (int) $vendor_match->id, $vendorTokenOwners);

                // Location gate: a vendor with a pinned location (street + zip)
                // is one specific physical place — don't fuzzy-match it to a
                // charge whose Plaid location says it happened somewhere else.
                $locationConflicts = $vendor_match->hasPinnedLocation()
                    && $transaction->locationAgreementWithVendor($vendor_match) === false;

                if ($namesOverlap && !$descNamesDifferentVendor && !$locationConflicts) {
                    $transaction->vendor_id = $vendor_match->id;
                    $transaction->save();
                }
            }
        }

        // PART 2: Re-validate transactions WITH vendors where plaid_merchant_name doesn't match assigned vendor
        // Get transactions with vendors assigned, from recent period
        $transactionsWithVendor = Transaction::withoutGlobalScopes()
            ->whereNotNull('vendor_id')
            ->whereNotNull('plaid_merchant_name')
            ->whereNull('deleted_at')
            ->whereNull('check_number')
            ->whereNull('deposit')
            ->whereDate('transaction_date', '>=', now()->subMonths(6))
            ->whereIn('bank_account_id', $bankAccountIds)
            ->with('vendor')
            ->get();

        // Load explicit VendorTransaction alias rules so PART 2 can recognise
        // intentional mappings (e.g. "ParkChicago" → vendor "chicago parking").
        // Without this, PART 2 clears such a vendor because the names don't
        // overlap, and PART 3 re-applies it on the next run — an infinite
        // oscillation that spams "Cleared mismatched vendor" every 10 minutes.
        $vendorTransactionAliases = VendorTransaction::whereNull('deposit_check')->get();
        $isAssignedByAliasRule = function (Transaction $transaction) use ($vendorTransactionAliases): bool {
            $desc = $transaction->plaid_merchant_description ?? '';
            if ($desc === '') {
                return false;
            }
            // A pinned-location vendor's alias doesn't protect the assignment
            // when the charge's Plaid location contradicts the vendor's address
            // — let PART 2 clear it so it resurfaces on Match Vendor.
            if ($transaction->vendor && $transaction->vendor->hasPinnedLocation()
                && $transaction->locationAgreementWithVendor($transaction->vendor) === false) {
                return false;
            }
            foreach ($vendorTransactionAliases as $alias) {
                if ((int) $alias->vendor_id !== (int) $transaction->vendor_id) {
                    continue;
                }
                if ($alias->amount_sign === 1 && $transaction->amount <= 0) {
                    continue;
                }
                if ($alias->amount_sign === 2 && $transaction->amount >= 0) {
                    continue;
                }
                $flags = json_decode($alias->options);
                $matched = @preg_match('/'.$alias->desc.$flags, $desc, $aliasMatches, PREG_UNMATCHED_AS_NULL);
                if ($matched === 1 && ! empty($aliasMatches)) {
                    return true;
                }
            }
            return false;
        };

        foreach ($transactionsWithVendor as $transaction) {
            // Skip if plaid_merchant_name is empty
            if (empty($transaction->plaid_merchant_name)) {
                continue;
            }
            
            // Skip transfer/non-purchase transactions - don't reassign their vendors
            $description = $transaction->plaid_merchant_description ?? '';
            $merchantName = $transaction->plaid_merchant_name ?? '';
            $isTransfer = false;
            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $description) || preg_match($pattern, $merchantName)) {
                    $isTransfer = true;
                    break;
                }
            }
            if ($isTransfer) {
                continue;
            }

            // Check if the assigned vendor's name matches the plaid merchant name
            $vendorName = strtolower($transaction->vendor->business_name);
            $plaidMerchantName = strtolower($transaction->plaid_merchant_name);
            $plaidMerchantDescription = strtolower($transaction->plaid_merchant_description ?? '');

            // If vendor name is NOT contained in plaid merchant name/description and vice versa, re-validate
            if (
                stripos($plaidMerchantName, $vendorName) === false &&
                stripos($vendorName, $plaidMerchantName) === false &&
                stripos($plaidMerchantDescription, $vendorName) === false
            ) {
                // Respect explicit VendorTransaction alias rules: if the current
                // vendor was assigned by a matching alias (e.g. "ParkChicago" →
                // "chicago parking"), the assignment is intentional even though
                // the names don't overlap. Leave it alone to break the PART 2 ↔
                // PART 3 oscillation that spammed "Cleared mismatched vendor".
                if ($isAssignedByAliasRule($transaction)) {
                    continue;
                }

                // Try to find correct vendor using fuzzy match
                $correctVendor = app(\App\Http\Controllers\CompanyEmailController::class)->fuzzyMatchVendor($transaction->plaid_merchant_name, $vendors);

                // Location gate: never "correct" onto a pinned-location vendor
                // whose address contradicts where the charge happened — fall
                // through to the clear branch so it resurfaces on Match Vendor.
                if ($correctVendor && $correctVendor->hasPinnedLocation()
                    && $transaction->locationAgreementWithVendor($correctVendor) === false) {
                    $correctVendor = null;
                }

                if ($correctVendor && $correctVendor->id !== $transaction->vendor_id) {
                    // Found a better vendor match - update
                    Log::channel('plaid_adds')->info('Corrected vendor mismatch', [
                        'transaction_id' => $transaction->id,
                        'old_vendor_id' => $transaction->vendor_id,
                        'old_vendor_name' => $vendorName,
                        'new_vendor_id' => $correctVendor->id,
                        'new_vendor_name' => $correctVendor->business_name,
                        'plaid_merchant_name' => $transaction->plaid_merchant_name,
                    ]);
                    $transaction->vendor_id = $correctVendor->id;
                    $transaction->save();
                } elseif (!$correctVendor) {
                    // No vendor matches at all - clear the wrong assignment so it shows on Match Vendor
                    Log::channel('plaid_adds')->debug('Cleared mismatched vendor (no better match found)', [
                        'transaction_id' => $transaction->id,
                        'old_vendor_id' => $transaction->vendor_id,
                        'old_vendor_name' => $vendorName,
                        'plaid_merchant_name' => $transaction->plaid_merchant_name,
                    ]);
                    $transaction->vendor_id = null;
                    $transaction->save();
                }
            }
        }

        $transactions = Transaction::TransactionsSinVendor()->whereIn('bank_account_id', $bankAccountIds)->get()->groupBy('plaid_merchant_description');
        $vendor_transactions = VendorTransaction::whereNull('deposit_check')->orderByRaw('LENGTH(`desc`) ASC')->get();
        $aliasVendors = Vendor::withoutGlobalScopes()
            ->whereIn('id', $vendor_transactions->pluck('vendor_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        foreach ($transactions as $merchant_desc => $plaid_name_transactions) {
            // Aliases whose regex matches this descriptor, kept shortest-desc
            // first: the last (longest) candidate wins, matching the old
            // behavior where later, longer aliases overwrote earlier ones.
            $matchingAliases = $vendor_transactions->filter(function ($vendor_transaction) use ($merchant_desc) {
                //decode json on VendorTrasaction Model
                $preg = json_decode($vendor_transaction->options);
                $matched = @preg_match('/'.$vendor_transaction->desc.$preg, $merchant_desc, $matches, PREG_UNMATCHED_AS_NULL);

                return $matched === 1 && ! empty($matches);
            });

            if ($matchingAliases->isEmpty()) {
                continue;
            }

            foreach ($plaid_name_transactions as $transaction) {
                $candidates = $matchingAliases->filter(function ($vendor_transaction) use ($transaction, $aliasVendors) {
                    // Check amount_sign filter
                    if ($vendor_transaction->amount_sign === 1 && $transaction->amount <= 0) {
                        return false;
                    }
                    if ($vendor_transaction->amount_sign === 2 && $transaction->amount >= 0) {
                        return false;
                    }

                    // Location gate: a vendor pinned to one physical place
                    // (street + zip) only claims charges whose Plaid location
                    // doesn't contradict it. Soft city/state never vetoes.
                    $vendor = $aliasVendors->get($vendor_transaction->vendor_id);

                    return ! ($vendor && $vendor->hasPinnedLocation()
                        && $transaction->locationAgreementWithVendor($vendor) === false);
                });

                if ($candidates->pluck('vendor_id')->unique()->count() > 1) {
                    // Generic descriptor ("SMOKE N VAPE") claimed by multiple
                    // vendors — only a positive location match may pick one;
                    // otherwise leave the transaction for Match Vendor.
                    $positive = $candidates->filter(function ($vendor_transaction) use ($transaction, $aliasVendors) {
                        $vendor = $aliasVendors->get($vendor_transaction->vendor_id);

                        return $vendor && $transaction->locationAgreementWithVendor($vendor) === true;
                    });

                    if ($positive->pluck('vendor_id')->unique()->count() !== 1) {
                        continue;
                    }

                    $candidates = $positive;
                }

                $chosen = $candidates->last();

                if (! $chosen) {
                    continue;
                }

                $transaction->vendor_id = $chosen->vendor_id;
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
                $vendor_transaction_rules = VendorTransaction::where('deposit_check', $deposit_check_type)->where('plaid_inst_id', $institution)->get();
                $transaction_check_desc = $vendor_transaction_rules->pluck('desc');

                $transactions = Transaction::where('expense_id', null)
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
                    // Check amount_sign filter from the matched rule
                    $matchedRule = $vendor_transaction_rules->first(function ($rule) use ($transaction) {
                        return stripos($transaction->plaid_merchant_description ?? '', $rule->desc) !== false;
                    });
                    if ($matchedRule && $matchedRule->amount_sign !== null) {
                        if ($matchedRule->amount_sign === 1 && $transaction->amount <= 0) {
                            continue;
                        }
                        if ($matchedRule->amount_sign === 2 && $transaction->amount >= 0) {
                            continue;
                        }
                    }
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
                        $transaction->vendor_id = null;

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

        $globalTransferRules = VendorTransaction::query()
            ->whereNull('plaid_inst_id')
            ->where('deposit_check', 3)
            ->orderByRaw('LENGTH(`desc`) ASC')
            ->get();

        if ($globalTransferRules->isNotEmpty()) {
            $allBankAccountIds = BankAccount::query()->pluck('id')->toArray();

            $transactions = Transaction::where('expense_id', null)
                ->where('check_number', null)
                ->where('deposit', null)
                ->whereNotNull('transaction_date')
                ->whereIn('bank_account_id', $allBankAccountIds)
                ->get();

            foreach ($globalTransferRules as $vendor_transaction) {
                foreach ($transactions as $transaction) {
                    $preg = json_decode($vendor_transaction->options);

                    if (! preg_match('/'.$vendor_transaction->desc.$preg, $transaction->plaid_merchant_description ?? '', $matches, PREG_UNMATCHED_AS_NULL)) {
                        continue;
                    }

                    if ($vendor_transaction->amount_sign !== null) {
                        if ($vendor_transaction->amount_sign === 1 && $transaction->amount <= 0) {
                            continue;
                        }
                        if ($vendor_transaction->amount_sign === 2 && $transaction->amount >= 0) {
                            continue;
                        }
                    }

                    $transaction->check_number = '1010101';
                    $transaction->vendor_id = null;
                    $transaction->save();
                }
            }
        }
    }

    public function add_expense_to_transactions()
    {
        $processedCount = 0;
        
        $hive_vendors = Vendor::hiveVendors()->get();

        foreach ($hive_vendors as $hive_vendor) {
            $hive_vendor_bank_account_ids = $hive_vendor->bank_accounts->pluck('id');

            // Use cursor() for memory-efficient iteration through all matching expenses
            // Filter at DB level: only get expenses where transaction sum < expense amount
            $expensesCursor = Expense::with('receipts')
                ->whereNull('deleted_at')
                ->where('belongs_to_vendor_id', $hive_vendor->id)
                ->whereNotNull('vendor_id')
                ->whereNull('paid_by') // Exclude employee reimbursements - they match to check, not bank transactions
                ->whereDate('date', '>=', Carbon::now()->subMonths(12))
                // Only fetch expenses that are not fully matched (transaction sum < expense amount)
                ->whereRaw("(
                    (expenses.amount >= 0 AND (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transactions.expense_id = expenses.id AND transactions.deleted_at IS NULL) < expenses.amount)
                    OR
                    (expenses.amount < 0 AND (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transactions.expense_id = expenses.id AND transactions.deleted_at IS NULL) > expenses.amount)
                )")
                ->orderBy('date', 'DESC')
                ->cursor();

            foreach ($expensesCursor as $expense) {
                $processedCount++;
                
                // Check memory usage periodically
                if ($processedCount % 50 === 0) {
                    $memoryMB = memory_get_usage(true) / 1024 / 1024;
                    $limitMB = (int) ini_get('memory_limit');
                    
                    if ($limitMB > 0 && $memoryMB > ($limitMB * 0.75)) {
                        Log::warning('Memory usage high - stopping expense processing', [
                            'memory_mb' => round($memoryMB, 2),
                            'limit_mb' => $limitMB,
                            'processed' => $processedCount,
                        ]);
                        break 2; // Break out of both loops
                    }
                }
                
                // Load transactions only when needed (not eager loaded to save memory)
                $expenseTransactions = Transaction::where('expense_id', $expense->id)
                    ->whereNull('deleted_at')
                    ->get();
                
                $start_date = $expense->date->copy()->subDays(7)->format('Y-m-d');
                $end_date = $expense->date->copy()->addDays(21)->format('Y-m-d');

                if (! $expenseTransactions->isEmpty()) {
                    $transaction_amount_outstanding = $expense->amount - $expenseTransactions->sum('amount');

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
                    // Only consider transactions that could plausibly match the outstanding amount.
                    // Keep the sign consistent (refunds/returns match to negative, purchases to positive).
                    ->when($transaction_amount_outstanding < 0, function ($query) use ($transaction_amount_outstanding) {
                        return $query->where('amount', '<', 0)
                            ->where('amount', '>=', $transaction_amount_outstanding);
                    }, function ($query) use ($transaction_amount_outstanding) {
                        return $query->where('amount', '>', 0)
                            ->where('amount', '<=', $transaction_amount_outstanding);
                    })
                    // Exclude bank transfers from expense matching - these are not retail purchases
                    ->where(function ($query) {
                        $query->whereNull('plaid_merchant_description')
                            ->orWhere(function ($subQuery) {
                                $subQuery->where('plaid_merchant_description', 'NOT LIKE', '%ZELLE%')
                                    ->where('plaid_merchant_description', 'NOT LIKE', '%WIRE%')
                                    ->where('plaid_merchant_description', 'NOT LIKE', '%ACH%')
                                    ->where('plaid_merchant_description', 'NOT LIKE', '%TRANSFER%')
                                    ->where('plaid_merchant_description', 'NOT LIKE', '%PAYROLL%');
                            });
                    })
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
                    // Include transactions matching the vendor, service fee transactions,
                    // or ATM/cash deposit transactions (no vendor_id, deposit = 1)
                    $expenseVendorId = $expense->vendor_id;
                    $transactions = $transactions->where(function ($query) use ($expenseVendorId) {
                        $query->where('vendor_id', $expenseVendorId)
                            // Also include potential service fee transactions (any vendor, small amount)
                            ->orWhere(function ($subQuery) {
                                $subQuery->where('amount', '<=', 10.00) // Service fees are typically small
                                    ->where('plaid_merchant_description', 'LIKE', '%SERVICEFEE%');
                            })
                            // Also include ATM/cash deposit transactions (no vendor, marked as deposit)
                            ->orWhere(function ($subQuery) {
                                $subQuery->whereNull('vendor_id')
                                    ->where('deposit', 1);
                            });
                    });
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
                                        if (isset($receipt->receipt_items['items']) && !empty($receipt->receipt_items['items'])) {
                                            $items = $receipt->receipt_items['items'];
                                            $lastItem = end($items);
                                            $last_item_str = htmlspecialchars($lastItem['Description'] ?? '');
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
                                        if (isset($receipt->receipt_items['charges'])) {
                                            $matches = collect($receipt->receipt_items['charges'])->where('amount', $transaction->amount);
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

                            // Skip subset matching if too many items (prevent memory exhaustion)
                            if ($n > 14) {
                                Log::warning('Too many transactions to match - skipping subset sum matching', [
                                    'expense_id' => $expense->id,
                                    'transaction_count' => $n,
                                ]);
                                continue;
                            }

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
                                    // Only link if vendor matches (service fee transactions are excluded)
                                    if ($transaction->vendor_id !== $expense->vendor_id && !str_contains($transaction->plaid_merchant_description ?? '', 'SERVICEFEE')) {
                                        continue;
                                    }
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

        // Second pass: match refund/return transactions to their parent expense.
        // Negative transactions are excluded from the main pass's sign check,
        // so unmatched refunds need a dedicated lookup by vendor + date proximity.
        $this->matchRefundTransactions();
    }

    /**
     * Match unmatched negative (refund/return) transactions to expenses
     * whose receipt HTML contains the refund amount as a negative value.
     *
     * Also links related expenses via parent_expense_id when receipts
     * share the same DEPOSIT NO# reference.
     */
    private function matchRefundTransactions(): void
    {
        $hiveVendors = Vendor::hiveVendors()->get();

        foreach ($hiveVendors as $hiveVendor) {
            $bankAccountIds = $hiveVendor->bank_accounts->pluck('id');

            $refundTransactions = Transaction::whereIn('bank_account_id', $bankAccountIds)
                ->whereNull('expense_id')
                ->whereNull('deleted_at')
                ->whereNotNull('vendor_id')
                ->where('amount', '<', 0)
                ->whereDate('transaction_date', '>=', Carbon::now()->subMonths(12))
                ->doesntHave('payments')
                ->whereNull('check_number')
                ->where(function ($query) {
                    $query->whereNull('plaid_merchant_description')
                        ->orWhere(function ($sub) {
                            $sub->where('plaid_merchant_description', 'NOT LIKE', '%ZELLE%')
                                ->where('plaid_merchant_description', 'NOT LIKE', '%WIRE%')
                                ->where('plaid_merchant_description', 'NOT LIKE', '%ACH%')
                                ->where('plaid_merchant_description', 'NOT LIKE', '%TRANSFER%')
                                ->where('plaid_merchant_description', 'NOT LIKE', '%PAYROLL%');
                        });
                })
                ->get();

            foreach ($refundTransactions as $refund) {
                $absAmount = number_format(abs($refund->amount), 2, '.', '');

                // Find an expense whose receipt HTML contains this negative amount
                $matchingExpense = Expense::withoutGlobalScopes()
                    ->where('belongs_to_vendor_id', $hiveVendor->id)
                    ->where('vendor_id', $refund->vendor_id)
                    ->where('amount', '>', 0)
                    ->whereNull('deleted_at')
                    ->whereBetween('date', [
                        $refund->transaction_date->copy()->subDays(14)->format('Y-m-d'),
                        $refund->transaction_date->copy()->addDays(7)->format('Y-m-d'),
                    ])
                    ->whereHas('receipts', function ($q) use ($absAmount) {
                        $q->where('receipt_html', 'LIKE', '%-' . $absAmount . '%');
                    })
                    ->orderByRaw('ABS(DATEDIFF(date, ?))', [$refund->transaction_date->format('Y-m-d')])
                    ->first();

                if (! $matchingExpense) {
                    continue;
                }

                $refund->expense()->associate($matchingExpense);
                $refund->save();

                Log::info('Refund transaction matched to expense via receipt HTML', [
                    'transaction_id' => $refund->id,
                    'expense_id' => $matchingExpense->id,
                    'transaction_amount' => $refund->amount,
                    'expense_amount' => $matchingExpense->amount,
                ]);

                // Link related expenses via shared DEPOSIT NO# in receipt HTML
                $this->linkExpensesByDepositNumber($matchingExpense, $hiveVendor);
            }
        }
    }

    /**
     * If this expense's receipt contains a DEPOSIT NO#, find the other expense
     * with the same deposit number and set parent_expense_id to link them.
     */
    private function linkExpensesByDepositNumber(Expense $expense, Vendor $hiveVendor): void
    {
        if ($expense->parent_expense_id) {
            return;
        }

        $receipt = $expense->receipts()->whereNotNull('receipt_html')->first();

        if (! $receipt || ! preg_match('/DEPOSIT NO#\s*(\S+)/', $receipt->receipt_html, $matches)) {
            return;
        }

        $depositNumber = $matches[1];

        // Find another expense from the same vendor with the same DEPOSIT NO# in its receipt
        $relatedExpense = Expense::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $hiveVendor->id)
            ->where('vendor_id', $expense->vendor_id)
            ->where('id', '!=', $expense->id)
            ->whereNull('deleted_at')
            ->whereHas('receipts', function ($q) use ($depositNumber) {
                $q->where('receipt_html', 'LIKE', '%' . $depositNumber . '%');
            })
            ->orderBy('date')
            ->first();

        if (! $relatedExpense) {
            return;
        }

        // The earlier expense (deposit) is the parent
        if ($relatedExpense->date <= $expense->date) {
            $expense->parent_expense_id = $relatedExpense->id;
            $expense->save();
        } else {
            $relatedExpense->parent_expense_id = $expense->id;
            $relatedExpense->save();
        }

        Log::info('Linked expenses via shared DEPOSIT NO#', [
            'deposit_number' => $depositNumber,
            'parent_expense_id' => $relatedExpense->date <= $expense->date ? $relatedExpense->id : $expense->id,
            'child_expense_id' => $relatedExpense->date <= $expense->date ? $expense->id : $relatedExpense->id,
        ]);
    }

    public function add_transaction_to_multi_expenses()
    {
        $checkpointCacheKey = 'transactions:add-transaction-to-multi-expenses:last-checked-transaction-id';
        $baseCutoffDate = Carbon::create(2017, 1, 1);
        $lastCheckedTransactionId = Cache::get($checkpointCacheKey);
        $cutoffDate = $baseCutoffDate;

        if ($lastCheckedTransactionId) {
            $lastCheckedTransaction = Transaction::withoutGlobalScopes()->find($lastCheckedTransactionId);

            if ($lastCheckedTransaction?->transaction_date) {
                $cutoffDate = $lastCheckedTransaction->transaction_date->copy()->subMonths(3);
                if ($cutoffDate->lt($baseCutoffDate)) {
                    $cutoffDate = $baseCutoffDate;
                }
            }
        }

        $matchedCount = 0;
        $hive_vendors = Vendor::hiveVendors()->get();
        $latestCheckedTransactionDate = null;
        $latestCheckedTransactionId = null;

        foreach ($hive_vendors as $hive_vendor) {
            $hive_vendor_bank_account_ids = $hive_vendor->bank_accounts->pluck('id');

            // Find unmatched transactions (no expense_id and no expenses via pivot table)
            $transactions = Transaction::whereIn('bank_account_id', $hive_vendor_bank_account_ids)
                ->whereNull('expense_id')
                ->whereDoesntHave('expenses') // No expenses linked via pivot table
                ->doesntHave('payments')
                ->whereNull('check_number')
                ->whereNull('deposit')
                ->whereNotNull('vendor_id')
                ->where('amount', '>', 0) // Only positive amounts for now
                ->whereDate('transaction_date', '>=', $cutoffDate)
                ->orderBy('transaction_date', 'desc')
                ->limit(500)
                ->get();

            foreach ($transactions as $transaction) {
                if (!$latestCheckedTransactionDate || $transaction->transaction_date->gt($latestCheckedTransactionDate)) {
                    $latestCheckedTransactionDate = $transaction->transaction_date->copy();
                    $latestCheckedTransactionId = $transaction->id;
                }

                $start_date = $transaction->transaction_date->copy()->subDays(7)->format('Y-m-d');
                $end_date = $transaction->transaction_date->copy()->addDays(14)->format('Y-m-d');

                // Find unmatched expenses for the same vendor within date range
                $expenses = Expense::whereNull('deleted_at')
                    ->where('belongs_to_vendor_id', $hive_vendor->id)
                    ->where('vendor_id', $transaction->vendor_id)
                    ->whereNull('paid_by')
                    ->whereDoesntHave('transactions') // Check legacy expense_id links
                    ->whereDoesntHave('sharedTransactions') // Also check pivot table links
                    ->whereBetween('date', [$start_date, $end_date])
                    ->where('amount', '>', 0)
                    ->get();

                // Need at least 2 expenses to match
                if ($expenses->count() < 2) {
                    continue;
                }

                $expenses_ids = $expenses->pluck('id')->toArray();
                $expenses_amounts = $expenses->pluck('amount', 'id')->toArray();

                $arr = array_values(array_map('floatval', $expenses_amounts));
                $n = count($arr);
                $ids = array_keys($expenses_amounts);

                // Skip subset matching if too many items (prevent memory exhaustion)
                if ($n > 14) {
                    Log::warning('Too many expenses to match - skipping subset sum matching', [
                        'transaction_id' => $transaction->id,
                        'expense_count' => $n,
                    ]);
                    continue;
                }

                // Find all subsets that sum to the transaction amount
                $results = collect($this->subsetSums($arr, $n, $ids, 'expense'))->sortBy('sum');

                $matchingSubsets = [];
                foreach ($results as $result) {
                    $sum = number_format($result['sum'], 2, '.', '');
                    if ($sum == number_format($transaction->amount, 2, '.', '') && count($result['expenses']) > 1) {
                        $matchingSubsets[] = $result;
                    }
                }

                if (empty($matchingSubsets)) {
                    continue;
                }

                // Use the first matching subset (smallest number of expenses that sum to transaction)
                $bestMatch = collect($matchingSubsets)->sortBy(fn($r) => count($r['expenses']))->first();
                $expenseIds = collect($bestMatch['expenses'])->pluck('expense_id')->toArray();

                // Link all matching expenses to this transaction via pivot table
                $transaction->expenses()->attach($expenseIds);
                $transaction->searchable();
                $matchedCount++;

                // Re-index linked expenses in Meilisearch (status changes to "Complete")
                Expense::withoutGlobalScopes()
                    ->whereIn('id', $expenseIds)
                    ->get()
                    ->each(fn($expense) => $expense->searchable());

                Log::info('Multi-expense match found', [
                    'transaction_id' => $transaction->id,
                    'transaction_amount' => $transaction->amount,
                    'expense_ids' => $expenseIds,
                    'expenses_sum' => $bestMatch['sum'],
                ]);
            }
        }

        if ($latestCheckedTransactionId) {
            Cache::forever($checkpointCacheKey, $latestCheckedTransactionId);
        }

        return response()->json([
            'matched' => $matchedCount,
            'message' => "Matched {$matchedCount} transactions to multiple expenses",
        ]);
    }

    public function add_check_id_to_transactions()
    {
        // Match checks to transactions automatically
        // Priority order:
        // 1. Single exact amount match (with check_number)
        // 2. Multiple exact matches - pick closest by date
        // 3. Multiple transactions that sum to check amount (subset sum matching)
        //    - Requires all transactions within 3 days of each other
        // 4. Fallback to check-type specific matching (Transfer with name, Check by number)
        
        $checks =
            Check::withoutGlobalScopes()
                ->whereDoesntHave('transactions')
                ->whereNull('deleted_at')
                ->where('date', '>', '2021-01-01')
                ->orderBy('date', 'DESC')
                ->limit(500) // Process most recent 500 checks to prevent memory issues
                ->get();

        foreach ($checks as $check) {
            if ($check->check_type == 'Transfer') {
                $check_number = '1010101';
                $add_days = 14;
                $sub_days = 14;
            } elseif ($check->check_type == 'Check') {
                $check_number = $check->check_number;
                $add_days = 180;
                $sub_days = 7;
            } elseif ($check->check_type == 'Cash') {
                $check_number = '2020202';
                $add_days = 14;
                $sub_days = 14;
            } else {
                Log::channel('add_check_id_to_transactions')->info($check);
                continue;
            }

            $bank_account_ids = $check->bank_account_id ? $check->bank_account->bank->accounts->pluck('id') : NULL;

            // For Transfer checks, prefer grouped subset-sum matches first (e.g. 2x$100)
            // so date-close grouped transfers win over a far-away single exact amount.
            if ($check->check_type === 'Transfer') {
                $this->matchTransactionsToCheckBySubsetSum($check, $check_number, $bank_account_ids, $add_days, $sub_days);

                if ($check->fresh()->transactions()->exists()) {
                    continue;
                }
            }

            //$transactions match the check amount.
            $transactions = Transaction::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereNull('check_id')
                ->whereNull('expense_id')
                ->where('check_number', $check_number)
                // Exclude returned checks - they are reversals, not the original check
                ->where(function ($query) {
                    $query->whereNull('plaid_merchant_description')
                        ->orWhere('plaid_merchant_description', 'NOT LIKE', '%RETURNED%');
                })
                ->when($bank_account_ids, function ($query, $bank_account_ids) {
                    return $query->whereIn('bank_account_id', $bank_account_ids);
                })
                ->whereBetween('transaction_date', [
                    $check->date->subDays($sub_days)->format('Y-m-d'),
                    $check->date->addDays($add_days)->format('Y-m-d'),
                ])
                ->where('amount', $check->amount)
                ->orderBy('id', 'DESC')
                ->get();

            //if amount matches and is only one, that's the one
            if ($transactions->count() === 1) {
                $matchedTransaction = $transactions->first();
                $matchedTransaction->check()->associate($check)->save();
            } elseif ($transactions->count() > 1) {
                // Pick the closest-by-days without mutating attributes
                // NOTE: Carbon 3 returns SIGNED values from diffInDays($other); pass true for absolute,
                // otherwise transactions dated AFTER the check get the most-negative value and would be
                // incorrectly selected as the "closest" by an ascending sort.
                $closest = $transactions
                    ->sortBy(fn ($t) => abs($t->transaction_date->diffInDays($check->date, true)))
                    ->first();

                if ($closest) {
                    $closest->check()->associate($check)->save();
                }
                continue; // done with this check
            } else {
                // No exact match found - try subset sum matching
                // Find multiple transactions that sum to the check amount
                $this->matchTransactionsToCheckBySubsetSum($check, $check_number, $bank_account_ids, $add_days, $sub_days);
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
            ->limit(500) // Process most recent 500 checks to prevent memory issues
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
                // Same rail as the check: sentinel number for Transfer/Cash,
                // the real number for Check — without this, any same-amount
                // card purchase could be absorbed as a check "completion".
                ->where('check_number', $check_number)
                // Exclude returned checks - they are reversals, not the original check
                ->where(function ($query) {
                    $query->whereNull('plaid_merchant_description')
                        ->orWhere('plaid_merchant_description', 'NOT LIKE', '%RETURNED%');
                })
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

            // Transfer checks: apply the same payee-identity gate as subset-sum
            // matching, so a completion can't grab a same-amount transfer to a
            // different person (or a Venmo business payment).
            if ($check->check_type === 'Transfer' && $transactions->isNotEmpty()) {
                $payeeIdentity = $check->vendor?->business_name;
                $allowUnresolvedPayee = false;

                if (! $payeeIdentity && $check->user_id) {
                    $userName = trim(($check->user?->first_name ?? '').' '.($check->user?->last_name ?? ''));
                    $payeeIdentity = $userName !== '' ? $userName : null;
                    $allowUnresolvedPayee = true;
                }

                if ($payeeIdentity) {
                    $transactions = $transactions->filter(function ($transaction) use ($payeeIdentity, $allowUnresolvedPayee) {
                        $payeeName = $this->extractPayeeNameFromTransaction($transaction);

                        return ($allowUnresolvedPayee && ! $this->payeeNameIsResolvable($payeeName))
                            || $this->payeeNameMatchesVendor($payeeName, $payeeIdentity);
                    });
                }
            }

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
        
        // Clear memory before expensive matching operations
        unset($checks, $transactions, $bank_account_ids);
        gc_collect_cycles();
        
        // Match returned check transactions to their original checks
        $this->matchReturnedChecksToOriginalChecks();
        
        // Now match checks to expenses using many-to-many relationship
        // Find expenses that don't have checks (via pivot) and match checks that sum to expense amount
        $this->matchChecksToExpenses();
    }
    
    /**
     * Create separate check and expense records for "RETURNED CHECK" transactions.
     * Links returned expense to original expense via parent_expense_id.
     */
    protected function matchReturnedChecksToOriginalChecks(): void
    {
        // Find unmatched returned check transactions
        $returnedTransactions = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('check_id')
            ->whereNotNull('check_number')
            ->where('plaid_merchant_description', 'LIKE', '%RETURNED%CHECK%')
            ->where('amount', '<', 0) // Returned checks are negative (money coming back)
            ->get();
        
        foreach ($returnedTransactions as $returnedTransaction) {
            // Find the original check transaction with matching check_number and bank
            $originalTransaction = Transaction::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereNotNull('check_id')
                ->where('check_number', $returnedTransaction->check_number)
                ->where('bank_account_id', $returnedTransaction->bank_account_id)
                ->where('amount', '>', 0) // Original is positive (money going out)
                ->first();
            
            if (!$originalTransaction || !$originalTransaction->check_id) {
                continue;
            }
            
            $originalCheck = Check::withoutGlobalScopes()->find($originalTransaction->check_id);
            if (!$originalCheck) {
                continue;
            }
            
            // Find the original expense linked to the original check
            $originalExpense = Expense::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('check_id', $originalCheck->id)
                ->first();
            
            // Create a new "Returned Check" record
            $returnedCheck = Check::create([
                'check_type' => 'Returned Check',
                'check_number' => $originalCheck->check_number,
                'date' => $returnedTransaction->transaction_date,
                'amount' => $returnedTransaction->amount, // Negative amount
                'bank_account_id' => $originalCheck->bank_account_id,
                'vendor_id' => $originalCheck->vendor_id,
                'belongs_to_vendor_id' => $originalCheck->belongs_to_vendor_id,
                'created_by_user_id' => 0,
            ]);
            
            // Link returned transaction to the new returned check
            $returnedTransaction->check_id = $returnedCheck->id;
            $returnedTransaction->vendor_id = $originalCheck->vendor_id;
            $returnedTransaction->save();
            
            // Find or create a returned expense linked to original expense
            if ($originalExpense) {
                // Check if a returned expense already exists (from prior runs or manual creation)
                $returnedExpense = Expense::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where('amount', $returnedTransaction->amount)
                    ->where('vendor_id', $originalExpense->vendor_id)
                    ->whereBetween('date', [
                        $returnedTransaction->transaction_date->copy()->subDays(3)->format('Y-m-d'),
                        $returnedTransaction->transaction_date->copy()->addDays(3)->format('Y-m-d'),
                    ])
                    ->first();
                
                if ($returnedExpense) {
                    // Update existing expense to link to returned check and original expense
                    $returnedExpense->check_id = $returnedCheck->id;
                    $returnedExpense->parent_expense_id = $originalExpense->id;
                    $returnedExpense->save();
                } else {
                    // Create new returned expense
                    $returnedExpense = Expense::create([
                        'date' => $returnedTransaction->transaction_date,
                        'amount' => $returnedTransaction->amount, // Negative amount
                        'distribution_id' => $originalExpense->distribution_id,
                        'vendor_id' => $originalExpense->vendor_id,
                        'check_id' => $returnedCheck->id,
                        'category_id' => $originalExpense->category_id,
                        'parent_expense_id' => $originalExpense->id,
                        'belongs_to_vendor_id' => $originalExpense->belongs_to_vendor_id,
                        'created_by_user_id' => 0,
                    ]);
                }
                
                // Link transaction to the new expense
                $returnedTransaction->expense_id = $returnedExpense->id;
                $returnedTransaction->save();
                
                Log::channel('add_check_id_to_transactions')->info('Created returned check and expense', [
                    'returned_transaction_id' => $returnedTransaction->id,
                    'returned_check_id' => $returnedCheck->id,
                    'returned_expense_id' => $returnedExpense->id,
                    'original_check_id' => $originalCheck->id,
                    'original_expense_id' => $originalExpense->id,
                    'check_number' => $returnedTransaction->check_number,
                ]);
            } else {
                Log::channel('add_check_id_to_transactions')->info('Created returned check (no original expense found)', [
                    'returned_transaction_id' => $returnedTransaction->id,
                    'returned_check_id' => $returnedCheck->id,
                    'original_check_id' => $originalCheck->id,
                    'check_number' => $returnedTransaction->check_number,
                ]);
            }
        }
    }
    
    /**
     * Match checks to expenses via many-to-many relationship.
     * Finds groups of checks (without expense links) that sum to an expense amount.
     */
    protected function matchChecksToExpenses(): void
    {
        // Get expenses without any checks linked (via pivot table OR direct check_id)
        $expenses = Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereDoesntHave('checks') // No checks via many-to-many
            ->whereNull('check_id') // No direct check_id set (legacy relationship)
            ->whereNotNull('vendor_id')
            ->whereNull('paid_by') // Exclude employee reimbursements
            ->where('date', '>', '2021-01-01')
            ->whereDate('date', '>=', Carbon::now()->subMonths(6)) // Reduced from 12 to 6 months
            ->orderBy('date', 'DESC')
            ->limit(200) // Process most recent 200 expenses to prevent memory issues
            ->get();
        
        foreach ($expenses as $expense) {
            $start_date = $expense->date->subDays(7)->format('Y-m-d');
            $end_date = $expense->date->addDays(21)->format('Y-m-d');
            
            // Find checks without expense links that could match
            // Exclude checks that have expenses via many-to-many OR via legacy check_id
            $checks = Check::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereDoesntHave('expensesMany') // No expenses via many-to-many
                ->whereDoesntHave('expenses') // No expenses via legacy check_id
                ->whereBetween('date', [$start_date, $end_date])
                ->where(function($query) use ($expense) {
                    // Either: check vendor matches expense vendor
                    // Or: check has no vendor BUT it's a paper check with a check number (not a Transfer)
                    // This prevents Transfers with no vendor from auto-matching to random expenses
                    $query->where('vendor_id', $expense->vendor_id)
                          ->orWhere(function($q) {
                              $q->whereNull('vendor_id')
                                ->where('check_type', '!=', 'Transfer');
                          });
                })
                ->orderBy('date', 'ASC')
                ->limit(20) // Limit to 20 checks max to prevent excessive subset combinations
                ->get();
            
            if ($checks->isEmpty()) {
                continue;
            }
            
            // Try single exact match first
            $exactMatch = $checks->where('amount', $expense->amount)->first();
            if ($exactMatch) {
                $expense->checks()->attach($exactMatch->id);
                $expense->searchable();
                continue;
            }
            
            // Try subset sum matching for multiple checks
            $check_ids = $checks->pluck('id')->toArray();
            $check_amounts = $checks->pluck('amount')->toArray();
            
            $arr = array_values(array_filter($check_amounts));
            $n = count($arr);
            
            if ($n > 14 || $n < 2) {
                continue; // Skip if too many items or only one check
            }
            
            $results = collect($this->subsetSums($arr, $n, $check_ids, 'check'))->sortBy('sum');
            
            foreach ($results as $result) {
                $sum = number_format($result['sum'], 2, '.', '');
                
                if ($sum == $expense->amount && isset($result['checks'])) {
                    $matchingCheckIds = collect($result['checks'])->pluck('check_id')->toArray();
                    
                    // Verify checks haven't been linked to other expenses since we queried
                    $stillAvailable = Check::withoutGlobalScopes()
                        ->whereIn('id', $matchingCheckIds)
                        ->whereDoesntHave('expensesMany')
                        ->pluck('id')
                        ->toArray();
                    
                    if (count($stillAvailable) === count($matchingCheckIds)) {
                        $expense->checks()->attach($matchingCheckIds);
                        $expense->searchable();
                        Log::channel('add_check_id_to_transactions')->info('Matched multiple checks to expense via subset sum', [
                            'expense_id' => $expense->id,
                            'check_ids' => $matchingCheckIds,
                            'check_count' => count($matchingCheckIds),
                            'amount' => $expense->amount,
                        ]);
                        break; // Found a match, move to next expense
                    }
                }
            }
            
            // Free memory after processing each expense
            unset($checks, $results, $arr, $check_ids, $check_amounts);
        }
        
        // Final memory cleanup
        gc_collect_cycles();
    }

    /**
     * Match multiple transactions to a single check using subset sum matching.
     * Finds groups of transactions that sum to the check amount.
     */
    protected function matchTransactionsToCheckBySubsetSum(Check $check, string $check_number, $bank_account_ids, int $add_days, int $sub_days = 7): void
    {
        // Get all unmatched transactions within the date range that could potentially match
        $transactions = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('check_id')
            ->whereNull('expense_id')
            ->where('check_number', $check_number)
            // Exclude returned checks - they are reversals, not the original check
            ->where(function ($query) {
                $query->whereNull('plaid_merchant_description')
                    ->orWhere('plaid_merchant_description', 'NOT LIKE', '%RETURNED%');
            })
            ->when($bank_account_ids, function ($query, $bank_account_ids) {
                return $query->whereIn('bank_account_id', $bank_account_ids);
            })
            ->whereBetween('transaction_date', [
                $check->date->copy()->subDays($sub_days)->format('Y-m-d'),
                $check->date->copy()->addDays($add_days)->format('Y-m-d'),
            ])
            ->where('amount', '<=', $check->amount) // Only transactions <= check amount
            ->orderBy('transaction_date', 'ASC')
            ->limit(50) // Get more initially, we'll group by payee name
            ->get();

        if ($transactions->count() < 2) {
            return; // Need at least 2 transactions for subset matching
        }

        // For Zelle/Transfer transactions, group by payee name extracted from description
        // This ensures we only match transactions to the same person
        if ($check->check_type === 'Transfer') {
            $transactionsByPayee = $transactions->groupBy(function ($t) {
                return $this->extractPayeeNameFromTransaction($t);
            });

            // Only consider payee groups whose extracted name matches the party the check
            // was paid to. This prevents subset-sum from greedily grabbing same-amount
            // transactions sent to a *different* person before the real match lands (e.g.,
            // a $1,250 check to "Morenos Drywall" matched to $150+$1,100 of Zelle transfers
            // to "Grzegorz", or a $450 Venmo check to "Patryk Szady" matched to three
            // Zelle transfers to "Grzegorz" that happen to sum to $450). If no payee group
            // matches, we leave the check unmatched so a later pass can match it correctly.
            //
            // The paid-to identity is the check's vendor business name, or — for personal
            // transfer checks with no vendor — the linked user's name. When gating by user
            // we still allow payee groups whose name could not be resolved (e.g., generic
            // "Venmo" memos with no recipient in the bank description), since those are
            // ambiguous rather than provably wrong.
            $payeeIdentity = $check->vendor?->business_name;
            $allowUnresolvedPayee = false;

            if (! $payeeIdentity && $check->user_id) {
                $checkUser = $check->user;
                $userName = trim(($checkUser?->first_name ?? '').' '.($checkUser?->last_name ?? ''));
                $payeeIdentity = $userName !== '' ? $userName : null;
                $allowUnresolvedPayee = true;
            }

            if ($payeeIdentity) {
                $transactionsByPayee = $transactionsByPayee->filter(
                    fn ($_payeeTransactions, $payeeName) => (
                        $allowUnresolvedPayee && ! $this->payeeNameIsResolvable((string) $payeeName)
                    ) || $this->payeeNameMatchesVendor((string) $payeeName, $payeeIdentity)
                );
            }

            // Try each payee group separately
            foreach ($transactionsByPayee as $payeeName => $payeeTransactions) {
                if ($payeeTransactions->count() < 2) {
                    continue; // Need at least 2 transactions for subset matching
                }

                // Limit to 14 for subset sum algorithm
                $payeeTransactions = $payeeTransactions->take(14);

                $matched = $this->trySubsetSumMatch($check, $payeeTransactions);
                if ($matched) {
                    return; // Found a match, done
                }
            }
        } else {
            // For non-Transfer types (Check, Cash), just ensure all transactions have the same check_number
            // which is already filtered in the query above
            $transactions = $transactions->take(14);
            $this->trySubsetSumMatch($check, $transactions);
        }
    }

    /**
     * Extract the payee name for a transaction, preferring the raw bank memo for
     * Venmo transfers. Venmo transactions carry a generic "Venmo" merchant
     * description; the actual recipient only appears in the original bank
     * description (details.original_description), e.g.
     * "DEBIT PURCHASE Jun 02 4849 VENMO *PATRYK SZADY 8558124430 NY". Resolving
     * the person from there keeps different people's Venmo transfers in separate
     * payee groups so subset-sum can't mix them.
     */
    protected function extractPayeeNameFromTransaction(Transaction $transaction): string
    {
        // Plaid's merchant enrichment beats the bank memo. A Venmo BUSINESS
        // payment (e.g. "Ssemblyai" paid via Venmo) still shows the account
        // holder's own name in the bank description ("VENMO *PATRYK SZADY"),
        // so the memo would wrongly group it with person-to-person transfers.
        $merchant = trim((string) ($transaction->plaid_merchant_name ?? ''));
        if ($merchant !== '' && ! in_array(strtoupper($merchant), ['VENMO', 'ZELLE'], true)) {
            return strtoupper($merchant);
        }

        $description = (string) $transaction->plaid_merchant_description;
        $originalDescription = (string) ($transaction->details['original_description'] ?? '');

        if (stripos($description, 'venmo') !== false || stripos($originalDescription, 'venmo') !== false) {
            if (preg_match('/VENMO\s*\*?\s*([A-Z][A-Za-z\'\-]+(?:\s+[A-Z][A-Za-z\'\-]+)*)/i', $originalDescription, $matches)) {
                return strtoupper(trim($matches[1]));
            }
        }

        return $this->extractPayeeNameFromDescription($transaction->plaid_merchant_description);
    }

    /**
     * Extract payee name from Plaid transaction description.
     * Handles formats like "ZELLE DEBIT PAY ID xxx ORG ID CTI NAME JOHN DOE"
     */
    protected function extractPayeeNameFromDescription(?string $description): string
    {
        if (empty($description)) {
            return 'unknown';
        }

        // Try to extract name after "NAME " pattern (common in Zelle transactions)
        if (preg_match('/\bNAME\s+([A-Z][A-Z\s]+?)(?:\s*$|\s+(?:ID|ORG|REF|CONF))/i', $description, $matches)) {
            return strtoupper(trim($matches[1]));
        }

        // Try to extract name from Venmo format
        if (preg_match('/VENMO\s+(?:CASHOUT|PAYMENT)?\s*([A-Z][A-Z\s]+?)(?:\s*$|\s+ID)/i', $description, $matches)) {
            return strtoupper(trim($matches[1]));
        }

        // For other transfers, use the full description as identifier
        // This ensures different transactions don't get mixed
        return strtoupper(trim($description));
    }

    /**
     * Tokenize a payee/vendor name into comparable tokens: uppercase, strip
     * non-letters, drop short tokens and common business/transfer stopwords.
     *
     * @return array<int, string>
     */
    protected function tokenizePayeeName(string $value): array
    {
        $value = strtoupper($value);
        // Replace any non-letter character with a space so "Morenos Drywall, Inc"
        // and "MORENOS-DRYWALL" tokenize the same way.
        $value = preg_replace('/[^A-Z]+/', ' ', $value);
        $tokens = preg_split('/\s+/', trim($value)) ?: [];

        $stopwords = [
            'INC', 'LLC', 'LTD', 'CORP', 'CORPORATION', 'CO', 'COMPANY',
            'THE', 'AND', 'OF', 'A', 'AN',
            'NAME', 'ID', 'ORG', 'REF', 'CONF', 'PAY', 'PAYMENT',
            'ZELLE', 'VENMO', 'CASHOUT', 'DEBIT', 'CREDIT', 'TRANSFER',
            'CTI', 'JPM', 'BOA', 'CHASE', 'UNKNOWN',
        ];

        return array_values(array_filter(
            $tokens,
            fn ($t) => strlen($t) >= 4 && !in_array($t, $stopwords, true)
        ));
    }

    /**
     * Whether a payee-group key contains an actual, comparable name (rather than a
     * generic marker like "VENMO" or "unknown"). Used to decide whether a group
     * can be gated against the check's paid-to identity.
     */
    protected function payeeNameIsResolvable(string $payeeName): bool
    {
        return ! empty($this->tokenizePayeeName($payeeName));
    }

    /**
     * Decide whether a payee name extracted from a transaction description plausibly
     * refers to the same party as a check's vendor business name. Used to gate
     * Transfer-check subset-sum matching so we don't link a check for vendor X to
     * transactions actually sent to vendor Y.
     *
     * Strategy: tokenize both sides, drop short tokens and common business suffixes,
     * and require at least one shared token of length >= 4 (case-insensitive).
     */
    protected function payeeNameMatchesVendor(string $payeeName, string $vendorBusinessName): bool
    {
        $payeeTokens = $this->tokenizePayeeName($payeeName);
        $vendorTokens = $this->tokenizePayeeName($vendorBusinessName);

        if (empty($payeeTokens) || empty($vendorTokens)) {
            return false;
        }

        return !empty(array_intersect($payeeTokens, $vendorTokens));
    }

    /**
     * Attempt to match transactions to a check using subset sum algorithm.
     * Returns true if a match was found and linked.
     */
    protected function trySubsetSumMatch(Check $check, $transactions): bool
    {
        // Verify transactions are within reasonable date range of each other (7 days max spread)
        $minDate = $transactions->min('transaction_date');
        $maxDate = $transactions->max('transaction_date');
        if ($minDate->diffInDays($maxDate) > 7) {
            // Transactions are too spread out - filter to only those within 7 days of check date
            $transactions = $transactions->filter(function ($t) use ($check) {
                return abs($t->transaction_date->diffInDays($check->date)) <= 7;
            });
            
            if ($transactions->count() < 2) {
                return false;
            }
        }

        $transaction_ids = $transactions->pluck('id')->toArray();
        $transaction_amounts = $transactions->pluck('amount')->map(fn ($a) => (float) $a)->toArray();

        $arr = array_values(array_filter($transaction_amounts));
        $n = count($arr);

        if ($n > 14 || $n < 2) {
            return false; // Skip if too many items or only one transaction
        }

        // Build id -> transaction_date lookup for date-proximity scoring
        $transactionDatesById = $transactions->pluck('transaction_date', 'id');

        // Filter combos to only those that match the check amount, then sort by total
        // absolute date distance from the check date so the *date-tightest* combo wins.
        // Without this, the algorithm would greedily pick the first combo it finds
        // (e.g., for a check dated 3/21 with five $100 candidates spread across 3/16-3/25,
        // it might pick 3/16+3/20 instead of the same-date 3/21+3/21 pair).
        $results = collect($this->subsetSums($arr, $n, $transaction_ids, 'transaction'))
            ->filter(function ($result) use ($check) {
                if (!isset($result['transactions'])) {
                    return false;
                }
                // Subset-sum is for combining MULTIPLE transactions. A single transaction
                // equal to the check amount is handled by the dedicated single-exact-match
                // path; allowing it here lets a lone transaction win over the correct group
                // purely because its summed date-distance is smaller (fewer terms).
                if (count($result['transactions']) < 2) {
                    return false;
                }
                return number_format($result['sum'], 2, '.', '') == $check->amount;
            })
            ->sortBy(function ($result) use ($check, $transactionDatesById) {
                $totalDistance = 0;
                $hasAfterCheckDate = false;
                foreach ($result['transactions'] as $t) {
                    $txDate = $transactionDatesById[$t['transaction_id']] ?? null;
                    if ($txDate) {
                        $totalDistance += abs($txDate->diffInDays($check->date, true));
                        if ($txDate->gt($check->date)) {
                            $hasAfterCheckDate = true;
                        }
                    }
                }

                // A Transfer check is recorded AFTER the money was actually sent, so the
                // real match is the group of transactions dated on/before the check date
                // (the transfers that happened that week). Rank any combo containing a
                // post-check-date transaction below every all-before combo, then break ties
                // by total date proximity. Paper checks clear after being written, so this
                // preference is intentionally limited to Transfer checks.
                if ($check->check_type === 'Transfer' && $hasAfterCheckDate) {
                    $totalDistance += 1000000;
                }

                return $totalDistance;
            })
            ->values();

        foreach ($results as $result) {
            $matchingTransactionIds = collect($result['transactions'])->pluck('transaction_id')->toArray();

            // Verify transactions haven't been linked to other checks since we queried
            $stillAvailable = Transaction::withoutGlobalScopes()
                ->whereIn('id', $matchingTransactionIds)
                ->whereNull('check_id')
                ->pluck('id')
                ->toArray();

            if (count($stillAvailable) === count($matchingTransactionIds)) {
                // Link all matching transactions to this check
                Transaction::withoutGlobalScopes()
                    ->whereIn('id', $matchingTransactionIds)
                    ->update(['check_id' => $check->id]);

                // Re-index transactions in Meilisearch after bulk update (bulk updates bypass Scout)
                Transaction::withoutGlobalScopes()
                    ->whereIn('id', $matchingTransactionIds)
                    ->searchable();

                Log::channel('add_check_id_to_transactions')->info('Matched multiple transactions to check via subset sum', [
                    'check_id' => $check->id,
                    'check_amount' => $check->amount,
                    'transaction_ids' => $matchingTransactionIds,
                    'transaction_count' => count($matchingTransactionIds),
                ]);
                return true; // Found a match
            }
        }

        return false;
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
            ->whereDoesntHave('payments')
            ->whereNull('expense_id')
            ->where(function ($q) {
                // Negative (debit) transactions OR positive deposits (returned checks)
                $q->where('amount', 'LIKE', '-%')
                  ->orWhere('deposit', 1);
            })
            ->orderBy('transaction_date', 'DESC')
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
            $single_payments = $payments->where('amount', is_numeric(substr($transaction->amount, 0, 1)) ? '-'.$transaction->amount : substr($transaction->amount, 1))->get();

            if ($single_payments->isNotEmpty()) {
                // Choose the payment whose date is CLOSEST (absolute diff) to the transaction date.
                // Tie-breakers:
                //  1) Prefer a payment on or before the transaction date over one after (if same diff)
                //  2) Then earlier calendar date (stable ordering)
                $txDate = $transaction->transaction_date->copy();
                $save_payment = $single_payments
                    ->sortBy(function ($p) use ($txDate) {
                        $diff = abs($p->date->diffInDays($txDate));
                        $afterFlag = $p->date->greaterThan($txDate) ? 1 : 0; // prefer <= tx date
                        return sprintf('%05d-%d-%s', $diff, $afterFlag, $p->date->toDateString());
                    })
                    ->first();

                // Associate and save
                $transaction->payments()->save($save_payment);

                // Trigger Searchable / indexing side-effects
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

                    // Skip subset matching if too many items (prevent memory exhaustion)
                    if ($n > 14) {
                        Log::warning('Too many client payments to match - skipping subset sum matching', [
                            'transaction_id' => $transaction->id,
                            'client_payment_count' => $n,
                        ]);
                        continue;
                    }

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
            if (is_array($receipt->receipt_items) && isset($receipt->receipt_items['merchant_name'])) {
                $merchant_name = $receipt->receipt_items['merchant_name'];
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
        
        // Reduced safety limit to prevent memory exhaustion
        // With proper memory management:
        // 2^12 = 4k combinations (very safe, processes instantly)
        // 2^14 = 16k combinations (safe, processes quickly)
        // 2^16 = 65k combinations (acceptable with memory cleanup)
        // Beyond 2^16 risks memory exhaustion even with cleanup
        if ($n > 14) {
            Log::warning('subsetSums called with too many elements - skipping to prevent memory exhaustion', [
                'n' => $n,
                'model' => $model,
                'max_combinations' => 1 << $n,
                'memory_limit_mb' => ini_get('memory_limit'),
                'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);
            return [];
        }
        
        ini_set('max_execution_time', 600000);
        
        // Initialize results array
        $summys = [];
        
        // There are totoal 2^n subsets
        $total = 1 << $n;
        // $sums = array();

        // Consider all numbers
        // from 0 to 2^n - 1
        for ($i = 0; $i < $total; $i++) {
            // Check memory every 1000 iterations to prevent exhaustion
            if ($i % 1000 === 0) {
                $memoryUsageMB = memory_get_usage(true) / 1024 / 1024;
                $memoryLimitMB = ini_get('memory_limit');
                if ($memoryLimitMB !== '-1') {
                    $memoryLimitMB = (int) $memoryLimitMB;
                    // If we've used more than 80% of available memory, stop
                    if ($memoryUsageMB > ($memoryLimitMB * 0.8)) {
                        Log::warning('subsetSums approaching memory limit - stopping early', [
                            'n' => $n,
                            'model' => $model,
                            'iteration' => $i,
                            'total_iterations' => $total,
                            'memory_used_mb' => round($memoryUsageMB, 2),
                            'memory_limit_mb' => $memoryLimitMB,
                        ]);
                        break;
                    }
                }
            }
            
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
    
    /**
     * Match existing expenses that are associated (credit/debit pairs).
     * Finds negative expenses (refunds/credits) and links them to matching positive expenses (payments).
     */
    public function match_associated_expenses()
    {
        $hive_vendors = Vendor::hiveVendors()->get();
        
        foreach ($hive_vendors as $hive_vendor) {
            // Find negative expenses (credits/refunds) with transactions
            $credit_expenses = Expense::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereNull('parent_expense_id')
                ->where('belongs_to_vendor_id', $hive_vendor->id)
                ->where('amount', '<', 0)
                ->whereHas('transactions')
                ->whereDate('date', '>=', now()->subMonths(6))
                ->get();
            
            foreach ($credit_expenses as $credit_expense) {
                $positive_amount = abs($credit_expense->amount);
                $start_date = $credit_expense->date->subDays(2)->format('Y-m-d');
                $end_date = $credit_expense->date->addDays(10)->format('Y-m-d');
                
                // Find matching positive expense (debit/payment)
                $debit_expenses = Expense::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->whereNull('parent_expense_id')
                    ->where('belongs_to_vendor_id', $hive_vendor->id)
                    ->where('vendor_id', $credit_expense->vendor_id)
                    ->where('amount', $positive_amount)
                    ->whereBetween('date', [$start_date, $end_date])
                    ->get();
                
                if ($debit_expenses->isEmpty()) {
                    continue;
                }
                
                // Calculate date difference for each potential match
                foreach ($debit_expenses as $debit_expense) {
                    $debit_expense->date_diff = $credit_expense->date->floatDiffInDays($debit_expense->date);
                }
                
                // Get closest match by date
                $debit_expense = $debit_expenses->sortBy('date_diff')->first();
                
                // Store date_diff for logging before removing it
                $dateDiff = $debit_expense->date_diff;
                
                // Remove temporary date_diff property before saving
                unset($debit_expense->date_diff);
                
                // Link them as associated expenses
                $debit_expense->parent_expense_id = $credit_expense->id;
                $debit_expense->save();
                
                Log::info('Matched associated expenses', [
                    'credit_expense_id' => $credit_expense->id,
                    'credit_amount' => $credit_expense->amount,
                    'debit_expense_id' => $debit_expense->id,
                    'debit_amount' => $debit_expense->amount,
                    'vendor_id' => $credit_expense->vendor_id,
                    'date_diff_days' => $dateDiff,
                ]);
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

    /**
     * Update existing unmatched expenses (project_id=0, distribution_id=null) 
     * based on bulk match rules.
     */
    protected function updateExistingExpensesWithBulkMatches(): void
    {
        $bulkMatches = TransactionBulkMatch::with('vendor')->get();

        foreach ($bulkMatches as $match) {
            $query = Expense::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('belongs_to_vendor_id', $match->belongs_to_vendor_id)
                ->where('vendor_id', $match->vendor_id)
                ->where(function ($q) {
                    $q->where('project_id', 0)
                      ->orWhereNull('project_id');
                })
                ->whereNull('distribution_id');

            // Apply amount matching based on amount_type
            if ($match->amount !== null) {
                $amountType = $match->options['amount_type'] ?? '=';
                if ($amountType !== 'ANY') {
                    $query->where('amount', $amountType, $match->amount);
                }
            }

            // Apply description matching if set
            if (isset($match->options['desc']) && $match->options['desc']) {
                $query->where('note', 'LIKE', '%' . $match->options['desc'] . '%');
            }

            $expenses = $query->get();

            foreach ($expenses as $expense) {
                // Check if this expense has splits - if so, skip (already processed)
                if ($expense->splits()->exists()) {
                    continue;
                }

                // If match has splits, create them
                if (!empty($match->options['splits'])) {
                    $this->transaction_vendor_bulk_match_splits($match, $expense, $expense->amount);
                } else {
                    // Otherwise just set the distribution
                    $expense->distribution_id = $match->distribution_id;
                    $expense->save();
                }
            }
        }
    }

    public function transaction_vendor_bulk_match()
    {
        // First, update existing unmatched expenses based on bulk match rules
        $this->updateExistingExpensesWithBulkMatches();

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
                    //
                }else{
                    foreach($receipt_account->vendor->transactions_bulk_match as $match){
                        $transactions =
                            Transaction::withoutGlobalScopes()
                                ->whereNull('deleted_at')
                                ->whereIn('bank_account_id', $bank_account_ids)
                                ->where('vendor_id', $match->vendor_id)
                                ->whereDoesntHave('expense')
                                ->whereNull('check_number')
                                ->when($match->amount != null && ($match->options['amount_type'] ?? 'ANY') !== 'ANY', function ($query) use ($match) {
                                    return $query->where('amount', $match->options['amount_type'] ?? '=', $match->amount);
                                })
                                ->when(!empty($match->options['desc']), function ($query) use ($match) {
                                    return $query->where('plaid_merchant_description', 'LIKE', '%' . $match->options['desc'] . '%');
                                })
                                ->get();

                        //create new expense foreach transaction
                        foreach($transactions as $transaction){
                            //Find Duplicates $expense = $duplicate
                            //date diff — use copy() to avoid mutating the cached Carbon instance on the model.
                            //Window is intentionally wider on the lookback side because manually-entered
                            //expenses (invoice received in hand) usually predate the bank posting by several days.
                            $duplicate_start_date = $transaction->transaction_date->copy()->subDays(7)->format('Y-m-d');
                            $duplicate_end_date = $transaction->transaction_date->copy()->addDays(4)->format('Y-m-d');

                            //find duplicate expenses
                            //An expense that already has a transaction linked is NOT a duplicate —
                            //two same-amount charges in the window (e.g. two identical Groot invoices)
                            //must each get their own expense instead of piling onto one.
                            $duplicates =
                                Expense::where('belongs_to_vendor_id', $transaction->bank_account->bank->vendor_id)->
                                    where('vendor_id', $transaction->vendor_id)->
                                    whereNull('deleted_at')->
                                    where('amount', $transaction->amount)->
                                    whereBetween('date', [$duplicate_start_date, $duplicate_end_date])->
                                    whereDoesntHave('transactions', fn ($q) => $q->withoutGlobalScope(\App\Scopes\TransactionScope::class))->
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
