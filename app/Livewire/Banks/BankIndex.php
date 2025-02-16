<?php

namespace App\Livewire\Banks;

use App\Models\Bank;
use App\Models\BankAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class BankIndex extends Component
{
    use AuthorizesRequests;

    protected $listeners = [
        'linkToken',
        'plaidLinkItem' => 'plaid_link_item',
        'refreshComponent' => '$refresh',
    ];

    public $view = null;

    #[Computed]
    public function banks()
    {
        return Bank::whereNotNull('plaid_access_token')
            // ->with(['accounts', 'accounts.checks' => function($query) {
            //     $query->whereIn('check_type', ['Transfer', 'Check'])->whereYear('date', '>=', 2024)->whereDoesntHave('transactions');
            // }])
            ->get();
        // ->each(function ($item, $key) {
        //     if($item->plaid_options->error != FALSE){
        //         $item->error = $item->plaid_options->error->error_code;
        //     }else{
        //         $item->error = FALSE;
        //     }

        //     // $balances = collect($this->plaid_options->accounts)->where('account_id', $account->plaid_account_id)->first();
        //     // if()
        // });

    }

    public function plaid_link_token()
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

        //open Plaid Link Modal
        //script file in banks.index.blade file.
        $this->dispatch('linkToken', $result['link_token']);
    }

    //workflow is VERY similar between NEW bank and UPDATING existing back.. see old GS/TransactionController.plaid_CREATE_item

    //plaidLinkItem
    public function plaid_link_item($item_data)
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

        $bank = new Bank;
        $bank->name = $item_data['institution']['name'];
        $bank->plaid_access_token = $result['access_token'];
        $bank->plaid_item_id = $result['item_id'];
        $bank->vendor_id = auth()->user()->vendor->id;
        $bank->plaid_ins_id = $item_data['institution']['institution_id'];
        //6/27/2021 need to set balances & error to FALSE because of banks.index and/or banks.show/edit view requirements
        $bank->plaid_options = '{"error": false, "balances": false}';
        $bank->save();

        foreach ($item_data['accounts'] as $account) {
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
        }

        //12/30/2022 if successful send to bank.show route, otherwise send back with error (plaid link or laravel php?)
        $this->mount();
        $this->render();
        $this->dispatch('confirmProcessStep', 'banks_registered')->to('entry.vendor-registration');
    }

    #[Title('Banks')]
    public function render()
    {
        $this->authorize('viewAny', Bank::class);

        return view('livewire.banks.index');
    }
}
