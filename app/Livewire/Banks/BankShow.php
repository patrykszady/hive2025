<?php

namespace App\Livewire\Banks;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Banks\BankAccountsGrouper;
use App\Services\Banks\LatestStatementDownloader;
use App\Services\PlaidService;
use Flux;

use Carbon\Carbon;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Livewire\Attributes\Title;
use Livewire\Component;

class BankShow extends Component
{
    use AuthorizesRequests;

    public Bank $bank;
    public $accounts = [];

    /**
     * Days of history a reconnected Item asks Plaid for. Plaid only honors
     * this when the Item is created, and it has to reach back past the day
     * the old connection last synced (PSFCU: 06/23, reconnected 09/29).
     */
    public const RELINK_DAYS_REQUESTED = 180;

    /**
     * Columns of the bank card's table: one row per account, then its
     * uncleared checks beneath it.
     *
     * @return list<array{label: string, width: string, align?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Account', 'width' => 'w-[37%] min-w-0'],
            ['label' => 'Details', 'width' => 'w-[40%] min-w-0'],
            ['label' => 'Amount', 'width' => 'w-[23%]', 'align' => 'end'],
        ];
    }

    protected $listeners = [
        'plaidLinkItemUpdate' => 'plaid_link_item_update',
        'plaidLinkItemRelink' => 'plaid_link_item_relink',
        'plaidError' => 'handlePlaidError',
        'refreshComponent' => '$refresh',
    ];

    /**
     * @param  \Illuminate\Support\Collection|null  $accounts  Pre-computed by
     *     BankIndex::accountsByBank() when this component is nested on the
     *     /banks list — batched across every bank instead of this component
     *     running its own accounts query plus one checks query per
     *     account-type group. Null when reached standalone (banks.show
     *     route), where it's built the same way, just for this one bank.
     */
    public function mount(Bank $bank, $accounts = null)
    {
        $this->bank = $bank;

        if ($accounts !== null) {
            $this->accounts = $accounts;

            return;
        }

        // Same grouping BankIndex batches across every bank — see
        // BankAccountsGrouper for the account_number -> type -> {account,
        // checks} shape.
        $this->accounts = app(BankAccountsGrouper::class)->forBank($this->bank->id);
    }

    /**
     * Whether this bank's institution supports Plaid statements — hides the
     * "Latest Statement" button for institutions that don't (e.g. Capital
     * One), instead of surfacing a guaranteed PRODUCTS_NOT_SUPPORTED error.
     */
    public function getSupportsStatementsProperty(): bool
    {
        if (blank($this->bank->plaid_access_token)) {
            return false;
        }

        return app(PlaidService::class)->institutionSupportsStatements(
            $this->bank->plaid_ins_id,
            ['bank_id' => $this->bank->id, 'source' => 'BankShow::supportsStatements'],
        );
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
            'products' => ['transactions', 'statements'],
            'statements' => [
                // Cover the trailing 6 months so newly-consented items ingest
                // enough history to resolve masked/aged transactions.
                'start_date' => Carbon::today()->subMonths(6)->startOfMonth()->format('Y-m-d'),
                'end_date' => Carbon::today()->format('Y-m-d'),
            ],
        ];

        $result = $plaidService->createLinkToken($data);

        if (isset($result['link_token'])) {
            $this->dispatch('linkTokenUpdate', [
                'exchangeToken' => $result['link_token'],
                'bankId' => $this->bank->id,
            ]);
        }
    }

    public function downloadLatestStatement(PlaidService $plaidService)
    {
        $this->authorize('create', Bank::class);

        $result = app(LatestStatementDownloader::class)->download($this->bank, $plaidService);

        if (isset($result['error'])) {
            Flux::toast(
                heading: 'Statement Download Failed',
                text: $result['error'],
                variant: 'danger',
            );

            return null;
        }

        return response()->streamDownload(function () use ($result) {
            echo $result['binary'];
        }, $result['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    //plaidLinkItemUpdate
    //sibling: as plaid_link_item on BankIndex
    public function plaid_link_item_update($public_token = null, $institution = null, $accounts = null, $bank_id = null)
    {
        if (empty($public_token)) {
            $this->handlePlaidError([
                'error' => true,
                'error_type' => 'invalid_request',
                'error_code' => 'missing_public_token',
                'error_message' => 'Missing Plaid public_token from Link payload.',
                'display_message' => 'We could not update the bank because Plaid did not return a token.',
                'request_id' => null,
            ], $bank_id);

            return;
        }

        $normalizedPayload = [
            'public_token' => $public_token,
            'institution' => $institution ?? [],
            'accounts' => $accounts ?? [],
        ];

        $plaidService = app(PlaidService::class);
        $plaidService = app(PlaidService::class);
        $result = $plaidService->processPlaidItem($normalizedPayload);

        if (isset($result['error']) && $result['error'] === true) {
            // Update the Bank model with the error details
            $this->handlePlaidError(
                $result['error'],
                $bank_id
            );

            return;
        }

        // Plaid sends no webhook for a repair made through our own update mode,
        // so ask it how the Item is now: a cleared ITEM_LOGIN_REQUIRED leaves
        // the card at once and a catch-up sync starts (PlaidService::refreshItemStatus).
        $bank = Bank::find($bank_id) ?? $this->bank;

        if ($bank) {
            $plaidService->refreshItemStatus($bank);
            $this->bank = $bank->fresh();
        }

        $this->render();

        $this->dispatch('confirmProcessStep', 'banks_registered')->to('entry.vendor-registration');
    }

    /**
     * Start Link for a brand-new Item at this bank, for when update mode
     * can't repair the old one. Transactions only: an institution that
     * doesn't offer statements (PSFCU) is hidden from Link when the token
     * asks for them.
     */
    public function plaid_link_token_relink(PlaidService $plaidService): void
    {
        $this->authorize('update', $this->bank);

        $result = $plaidService->createLinkToken([
            'client_id' => config('services.plaid.client_id'),
            'secret' => config('services.plaid.secret'),
            'client_name' => config('app.name'),
            'user' => ['client_user_id' => (string) auth()->id()],
            'country_codes' => ['US'],
            'language' => 'en',
            'webhook' => config('services.plaid.webhook'),
            'products' => ['transactions'],
            'transactions' => ['days_requested' => self::RELINK_DAYS_REQUESTED],
        ]);

        if (empty($result['link_token'])) {
            Flux::toast(
                text: $result['error_message'] ?? 'Plaid did not return a link token.',
                heading: 'Could not start the reconnect',
                variant: 'danger',
            );

            return;
        }

        $this->dispatch('linkTokenRelink', [
            'exchangeToken' => $result['link_token'],
            'bankId' => $this->bank->id,
        ]);
    }

    /**
     * Link finished for the new Item: move this bank and its accounts onto
     * it, so history, checks and expense links stay on the same records,
     * then remove the old Item at Plaid. The sync skips what the old Item
     * already imported (plaid_options.relinked_through).
     *
     * @param  array<string, mixed>|null  $institution
     * @param  array<int, array<string, mixed>>|null  $accounts
     */
    public function plaid_link_item_relink($public_token = null, $institution = null, $accounts = null, $bank_id = null): void
    {
        // Every BankShow on the page hears this event; only the one whose
        // bank started Link acts on it.
        if ((int) $bank_id !== $this->bank->id) {
            return;
        }

        $this->authorize('update', $this->bank);

        if (empty($public_token)) {
            Flux::toast(text: 'Plaid did not return a token.', heading: 'Reconnect failed', variant: 'danger');

            return;
        }

        $plaidService = app(PlaidService::class);
        $exchange = $plaidService->exchangePublicToken($public_token);

        if (empty($exchange['access_token'])) {
            Flux::toast(
                text: $exchange['error_message'] ?? 'Plaid did not return an access token.',
                heading: 'Reconnect failed',
                variant: 'danger',
            );

            return;
        }

        if (($institution['institution_id'] ?? null) !== $this->bank->plaid_ins_id) {
            // Don't leave the wrong bank's new Item connected (and billed).
            $plaidService->removeItem($exchange['access_token']);

            Flux::toast(
                text: 'That login was for '.($institution['name'] ?? 'a different bank').', not '.$this->bank->name.'. Nothing changed.',
                heading: 'Different bank',
                variant: 'danger',
            );

            return;
        }

        $oldAccessToken = $this->bank->plaid_access_token;
        $oldItemId = $this->bank->plaid_item_id;
        $relinkedThrough = $this->relinkedThroughDate();

        DB::transaction(function () use ($exchange, $accounts, $oldItemId, $relinkedThrough) {
            foreach ($accounts ?? [] as $account) {
                $this->attachRelinkedAccount($account);
            }

            $options = $this->bank->plaid_options ?? [];
            unset($options['next_cursor']);
            $options['error'] = false;
            $options['relinked_at'] = now()->toIso8601String();
            $options['relinked_through'] = $relinkedThrough;
            $options['previous_plaid_item_id'] = $oldItemId;

            $this->bank->forceFill([
                'plaid_access_token' => $exchange['access_token'],
                'plaid_item_id' => $exchange['item_id'] ?? null,
                'plaid_options' => $options,
            ])->save();
        });

        $removal = filled($oldAccessToken) ? $plaidService->removeItem($oldAccessToken) : null;

        Log::channel('plaid_adds')->info('Bank reconnected as a new Plaid Item', [
            'bank_id' => $this->bank->id,
            'previous_item_id' => $oldItemId,
            'item_id' => $exchange['item_id'] ?? null,
            'relinked_through' => $relinkedThrough,
            'accounts' => collect($accounts ?? [])->map(fn ($account) => ($account['mask'] ?? '').' '.($account['subtype'] ?? ''))->all(),
            'old_item_removed' => isset($removal['request_id']) && empty($removal['error']),
            'old_item_removal_error' => $removal['error_message'] ?? null,
        ]);

        Flux::toast(
            text: 'New transactions will sync shortly. Anything the old connection already imported is skipped.',
            heading: $this->bank->name.' reconnected',
            variant: 'success',
        );

        $this->redirect(route('banks.show', $this->bank), navigate: true);
    }

    /**
     * Point this bank's matching account (same last four and type) at the
     * new Item's account id, or add it when the login shows a new account.
     *
     * @param  array<string, mixed>  $account
     */
    protected function attachRelinkedAccount(array $account): void
    {
        $type = ucwords((string) ($account['subtype'] ?? ''));

        $bankAccount = $this->bank->accounts()->withTrashed()
            ->where('account_number', $account['mask'] ?? null)
            ->where('type', $type)
            ->orderByRaw('deleted_at IS NULL DESC')
            ->latest('updated_at')
            ->first();

        if (! $bankAccount) {
            $bankAccount = new BankAccount;
            $bankAccount->bank_id = $this->bank->id;
            $bankAccount->account_number = $account['mask'] ?? null;
            $bankAccount->vendor_id = $this->bank->vendor_id;
            $bankAccount->type = $type;
        }

        if ($bankAccount->trashed()) {
            $bankAccount->restore();
        }

        $bankAccount->plaid_account_id = $account['id'] ?? null;
        $bankAccount->save();
    }

    /**
     * The last day the old Item delivered transactions: Plaid's last
     * successful transactions update, else the newest imported transaction.
     */
    protected function relinkedThroughDate(): ?string
    {
        $lastSuccessfulUpdate = $this->bank->plaid_options['status']['transactions']['last_successful_update'] ?? null;

        if ($lastSuccessfulUpdate) {
            return Carbon::parse($lastSuccessfulUpdate)->toDateString();
        }

        $newest = Transaction::query()
            ->whereIn('bank_account_id', $this->bank->accounts()->withTrashed()->pluck('id'))
            ->max('transaction_date');

        return $newest ? Carbon::parse($newest)->toDateString() : null;
    }

    //plaidError
    public function handlePlaidError($errorData, $bankId)
    {
        // Find the bank being updated using the bank_id
        $bank = Bank::find($bankId);

        if ($bank) {
            // Retrieve the current plaid_options as an array
            $plaidOptions = $bank->plaid_options;

            // Ensure plaid_options is an array (in case it's null or not set)
            if (!is_array($plaidOptions)) {
                $plaidOptions = [];
            }

            // Update or create the 'error' key
            $plaidOptions['error'] = [
                'error' => true,
                'error_type' => $errorData['error_type'],
                'error_code' => $errorData['error_code'],
                'error_message' => $errorData['error_message'],
                'display_message' => $errorData['display_message'],
                'request_id' => $errorData['request_id'],
            ];

            // Assign the updated array back to the plaid_options attribute
            $bank->plaid_options = $plaidOptions;
            $bank->save();
        }

        $this->bank = $bank;
        // Display an error message using flux.ui toast
    }


    #[Title('Bank')]
    public function render()
    {
        $this->authorize('create', Bank::class);
        return view('livewire.banks.show');
    }
}
