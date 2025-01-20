<?php

namespace App\Livewire\Banks;

use App\Models\Bank;
use App\Models\BankAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class BankShow extends Component
{
    use AuthorizesRequests;

    public Bank $bank;

    public $error = null;

    protected $listeners = [
        'plaidLinkItemUpdate' => 'plaid_link_item_update',
    ];

    public function mount()
    {
        // $this->bank = Bank::findOrFail($this->bank);
        if ($this->bank->plaid_options->error != false) {
            $this->error = $this->bank->plaid_options->error->error_code;
        } else {
            $this->error = false;
        }
    }

    //plaidLinkItemUpdate
    //SAME as plaid_link_item on BankIndex
    public function plaid_link_item_update($item_data)
    {
        //php proccess the $data /aka: add bank and bank_accounts to user
        // Log::channel('plaid')->info(request()->all());
        $data = [
            'client_id' => env('PLAID_CLIENT_ID'),
            'secret' => env('PLAID_SECRET'),
            'public_token' => $item_data['public_token'],
        ];

        //convert array into JSON
        $data = json_encode($data);
        //initialize session
        $ch = curl_init('https://'.env('PLAID_ENV').'.plaid.com/item/public_token/exchange');
        //set options
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute session
        $exchangeToken = curl_exec($ch);

        //close session
        curl_close($ch);
        $result = json_decode($exchangeToken, true);
        // Log::channel('plaid')->info($result);

        //if plaid_access_token exists on Bank table ...
        $bank = Bank::where('plaid_access_token', $result['access_token'])->first();

        if (! $bank) {
            $bank = new Bank;
            $bank->name = $item_data['institution']['name'];
            $bank->plaid_access_token = $result['access_token'];
            $bank->plaid_item_id = $result['item_id'];
            $bank->vendor_id = auth()->user()->vendor->id;
            $bank->plaid_ins_id = $item_data['institution']['institution_id'];
            //Need to set balances & error to FALSE because of banks.index and/or banks.show/edit view requirements
            $bank->plaid_options = '{"error": false, "balances": false}';
            $bank->save();
        }

        foreach ($item_data['accounts'] as $account) {
            $bank_account = BankAccount::where('plaid_account_id', $account['id'])->first();

            if (! $bank_account) {
                $bank_account = new BankAccount;
                $bank_account->bank_id = $bank->id;
                //06/27/2021 if 0 or less than 4 ... add 0 in front until it reaches 4 digits on the BankAccount Model.
                $bank_account->account_number = $account['mask'];
                $bank_account->vendor_id = $bank->vendor_id;
                $bank_account->type = ucwords($account['subtype']);
                //06/25/2021 There's way more subtypes...account for all
                //09/03/2021 add type to database  see https://plaid.com/docs/api/accounts/
                // checking
                // savings
                // credit
                // cd
                // money market
                // 401k
                // student
                // auto
                // consumer
                $bank_account->plaid_account_id = $account['id'];
                $bank_account->save();
            } else {
                // dd($account, $bank_account);
            }
        }

        //run / execute plaid_item_status
        app(\App\Http\Controllers\TransactionController::class)->plaid_item_status();
        sleep(5);
        $this->render();
        $this->dispatch('confirmProcessStep', 'banks_registered')->to('entry.vendor-registration');
    }

    public function plaid_link_token_update()
    {
        $data = [
            'client_id' => env('PLAID_CLIENT_ID'),
            'secret' => env('PLAID_SECRET'),
            'client_name' => env('APP_NAME'),
            //variable of user json cleaned below (single quotes inside single quotes)
            'user' => ['client_user_id' => (string) auth()->user()->id], //, 'client_vendor_id' => (string)auth()->user()->getVendor()->id
            'country_codes' => ['US'],
            'language' => 'en',
            // "redirect_uri" => OAuth redirect URI must be configured in the developer dashboard. See https://plaid.com/docs/#oauth-redirect-uris
            'webhook' => env('PLAID_WEBHOOK'),
            'access_token' => $this->bank->plaid_access_token,
        ];

        $data['products'] = ['transactions'];
        $data['required_if_supported_products'] = ['statements'];
        $data['statements'] = ['start_date' => Carbon::today()->subMonth()->startOfMonth()->format('Y-m-d'), 'end_date' => Carbon::today()->subMonth()->endOfMonth()->format('Y-m-d')];
        //convert array into JSON
        $data = json_encode($data);

        //initialize session
        $ch = curl_init('https://'.env('PLAID_ENV').'.plaid.com/link/token/create');
        //set options
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //execute session
        $exchangeToken = curl_exec($ch);
        //close session
        curl_close($ch);

        $result = json_decode($exchangeToken, true);

        //open Plaid Link Modal.
        //script file in banks.show.blade file.
        $this->dispatch('linkTokenUpdate', $result['link_token']);
        //after dispatch run TransactionController@plaid_item_status
    }

    #[Title('Bank')]
    public function render()
    {
        $this->authorize('create', Bank::class);

        return view('livewire.banks.show');
    }
}
