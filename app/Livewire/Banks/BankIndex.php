<?php

namespace App\Livewire\Banks;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Services\PlaidService;

use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
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

    /**
     * Accounts (grouped by account_number then type, latest-per-type, with
     * their checks) for every bank on this page, batched into 2 queries
     * total instead of BankShow::mount() running its own accounts query plus
     * one checks query per account-type group for EACH bank (38 queries for
     * a handful of banks). Keyed by bank_id, same shape BankShow builds for
     * itself when rendered standalone — see BankShow::mount().
     *
     * @return Collection<int, Collection>
     */
    #[Computed]
    public function accountsByBank(): Collection
    {
        $bankIds = $this->banks->pluck('id');

        if ($bankIds->isEmpty()) {
            return collect();
        }

        // Grouped, NOT reduced to one representative account yet — a type
        // group can hold several historical (incl. soft-deleted) accounts,
        // and BankShow's own logic pulls checks from every one of them, not
        // just the latest.
        $groupedByBank = BankAccount::withTrashed()
            ->whereIn('bank_id', $bankIds)
            ->get()
            ->groupBy('bank_id')
            ->map(fn (Collection $byBank) => $byBank
                ->groupBy('account_number')
                ->map(fn (Collection $byNumber) => $byNumber->groupBy('type')));

        $allAccountIds = $groupedByBank
            ->flatMap(fn (Collection $byNumber) => $byNumber->flatMap(
                fn (Collection $byType) => $byType->flatMap(fn (Collection $accounts) => $accounts)
            ))
            ->pluck('id');

        $checksByAccountId = $allAccountIds->isEmpty()
            ? collect()
            : Check::with(['user.vendors', 'vendor'])
                ->whereIn('bank_account_id', $allAccountIds)
                ->whereIn('check_type', ['Transfer', 'Check'])
                ->whereYear('date', '>=', 2024)
                ->whereDoesntHave('transactions')
                ->get()
                ->groupBy('bank_account_id');

        return $groupedByBank->map(fn (Collection $byNumber) => $byNumber->map(
            fn (Collection $byType) => $byType->map(function (Collection $accountsByType) use ($checksByAccountId) {
                return [
                    'account' => $accountsByType->sortByDesc('updated_at')->first(),
                    'checks' => $accountsByType->flatMap(
                        fn ($account) => $checksByAccountId->get($account->id, collect())
                    ),
                ];
            })
        ));
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
            'products' => ['transactions', 'statements'],
            'statements' => [
                'start_date' => Carbon::today()->subMonth()->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::today()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
        ];

        $result = $plaidService->createLinkToken($data);

        $this->dispatch('linkToken', $result['link_token']);
    }

    public function plaid_link_item($public_token = null, $institution = null, $accounts = null, $bank_id = null)
    {
        Log::info('plaid_link_item method triggered.', [
            'public_token' => $public_token,
            'institution' => $institution,
            'accounts' => $accounts,
            'bank_id' => $bank_id,
        ]);

        if (empty($public_token)) {
            Log::error('Missing public_token in Plaid payload.');
            return;
        }

        $normalizedPayload = [
            'public_token' => $public_token,
            'institution' => $institution ?? [],
            'accounts' => $accounts ?? [],
        ];

        $plaidService = app(PlaidService::class);
        $result = $plaidService->processPlaidItem($normalizedPayload);

        if (isset($result['error']) && $result['error'] === true) {
            Log::error('PlaidService error processing item', $result);
            return;
        }

        Log::info('Bank created/updated successfully', [
            'bank_id' => $result->id ?? null,
            'bank_name' => $result->name ?? null,
        ]);

        $this->dispatch('refreshComponent');
    }

    #[Title('Banks')]
    public function render()
    {
        $this->authorize('viewAny', Bank::class);

        return view('livewire.banks.index');
    }
}
