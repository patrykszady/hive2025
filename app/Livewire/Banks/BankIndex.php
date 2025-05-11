<?php

namespace App\Livewire\Banks;

use App\Models\Bank;
use App\Services\PlaidService;

use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;

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
        return Bank::whereNotNull('plaid_access_token')->get();
    }

    public function plaid_link_token(PlaidService $plaidService)
    {
        $data = [
            'client_id' => env('PLAID_CLIENT_ID'),
            'secret' => env('PLAID_SECRET'),
            'client_name' => env('APP_NAME'),
            'user' => ['client_user_id' => (string) auth()->user()->id],
            'country_codes' => ['US'],
            'language' => 'en',
            'webhook' => env('PLAID_WEBHOOK'),
            'access_token' => $this->bank->plaid_access_token ?? null,
            'products' => ['transactions'],
            'statements' => [
                'start_date' => Carbon::today()->subMonth()->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::today()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
        ];

        $result = $plaidService->createLinkToken($data);

        $this->dispatch('linkToken', $result['link_token']);
    }

    public function plaid_link_item($itemData)
    {
        Log::info('plaid_link_item method triggered.');
        Log::info('Received itemData:', $itemData);

        if (empty($itemData)) {
            Log::error('itemData is empty or null.');
            return;
        }

        Log::info('Processing itemData:', $itemData);
    }

    #[Title('Banks')]
    public function render()
    {
        $this->authorize('viewAny', Bank::class);

        return view('livewire.banks.index');
    }
}
