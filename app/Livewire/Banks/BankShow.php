<?php

namespace App\Livewire\Banks;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Services\PlaidService;

use Carbon\Carbon;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Attributes\Title;
use Livewire\Component;

class BankShow extends Component
{
    use AuthorizesRequests;

    public Bank $bank;
    public $accounts = [];

    protected $listeners = [
        'plaidLinkItemUpdate' => 'plaid_link_item_update',
        'plaidError' => 'handlePlaidError',
    ];

    public function mount(Bank $bank)
    {
        // $this->bank = $bank->load('accounts');

        // Group accounts by account_number and type, and include checks in the result
        $this->accounts = $this->bank->accounts
            ->groupBy('account_number')
            ->map(function ($accountsByNumber) {
                return $accountsByNumber->groupBy('type')->map(function ($accountsByType) {
                    return $accountsByType->flatMap(function ($account) {
                        return $account->checks()
                            ->whereIn('check_type', ['Transfer', 'Check'])
                            ->whereYear('date', '>=', 2024)
                            ->whereDoesntHave('transactions')
                            ->get();
                    });
                });
            });
    }

    public function plaid_link_token_update(PlaidService $plaidService)
    {
        $data = [
            'client_id' => env('PLAID_CLIENT_ID'),
            'secret' => env('PLAID_SECRET'),
            'client_name' => env('APP_NAME'),
            'user' => ['client_user_id' => (string) auth()->user()->id],
            'country_codes' => ['US'],
            'language' => 'en',
            'webhook' => env('PLAID_WEBHOOK'),
            'access_token' => $this->bank->plaid_access_token,
            'products' => ['transactions'],
            'required_if_supported_products' => ['statements'],
            'statements' => [
                'start_date' => Carbon::today()->subMonth()->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::today()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
        ];

        $result = $plaidService->createLinkToken($data);

        // Ensure the link_token is dispatched correctly
        if (isset($result['link_token'])) {
            $this->dispatch('linkTokenUpdate', [
                'exchangeToken' => $result['link_token'],
                'bankId' => $this->bank->id,
            ]);
        } else {
            // Handle the case where the link_token is missing
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Failed to generate Plaid link token.',
            ]);
        }
    }

    //plaidLinkItemUpdate
    //sibling: as plaid_link_item on BankIndex
    public function plaid_link_item_update($itemData, PlaidService $plaidService)
    {
        $result = $plaidService->processPlaidItem($itemData);

        if (isset($result['error']) && $result['error'] === true) {
            // Update the Bank model with the error details
            $this->bank->plaid_options = json_encode([
                'error' => true,
                'error_code' => $result['error_code'],
                'error_message' => $result['error_message'],
                'error_body' => $result['error_body'] ?? null,
            ]);
            $this->bank->save();

            // Display an error message using flux.ui toast
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'An error occurred while updating the bank link: ' . $result['error_message'],
            ]);

            return;
        }

        // Process the bank item if no error occurred
        app(\App\Http\Controllers\TransactionController::class)->plaid_item_status();
        sleep(5);
        $this->render();

        // Dispatch a success toast
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Bank link updated successfully!',
        ]);

        $this->dispatch('confirmProcessStep', 'banks_registered')->to('entry.vendor-registration');
    }

    //plaidError
    public function handlePlaidError($errorData)
    {
        // Find the bank being updated using the bank_id
        $bank = Bank::find($errorData['bank_id']);

        if ($bank) {
            // Update the Bank model with the error details
            $bank->plaid_options = json_encode([
                'error' => true,
                'error_type' => $errorData['error_type'],
                'error_code' => $errorData['error_code'],
                'error_message' => $errorData['error_message'],
                'display_message' => $errorData['display_message'],
                'request_id' => $errorData['request_id'],
            ]);
            $bank->save();
        }

        // Display an error message using flux.ui toast
        $this->dispatch('toast', [
            'type' => 'error',
            'message' => 'An error occurred while updating the bank: ' . $errorData['error_message'],
        ]);
    }

    #[Title('Bank')]
    public function render()
    {
        $this->authorize('create', Bank::class);
        return view('livewire.banks.show');
    }
}
