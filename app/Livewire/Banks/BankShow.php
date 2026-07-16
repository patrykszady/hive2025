<?php

namespace App\Livewire\Banks;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Services\PlaidService;
use Flux;

use Carbon\Carbon;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;

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
        'refreshComponent' => '$refresh',
    ];

    public function mount(Bank $bank)
    {
        $this->bank = $bank;
        
        // Group accounts by account_number and type, and include account options and checks
        $this->accounts = $this->bank->accounts()->withTrashed()->get()
            ->groupBy('account_number')
            ->map(function ($accountsByNumber) {
                return $accountsByNumber->groupBy('type')->map(function ($accountsByType) {
                    // Find the latest account (most recently updated) to use for this account type
                    $latestAccount = $accountsByType->sortByDesc('updated_at')->first();
                    
                    // Create a result structure with a single representative account
                    $result = [
                        'account' => $latestAccount, // Use the latest account as THE account
                        'checks' => $accountsByType->flatMap(function ($account) {
                            return $account->checks()
                                ->whereIn('check_type', ['Transfer', 'Check'])
                                ->whereYear('date', '>=', 2024)
                                ->whereDoesntHave('transactions')
                                ->get();
                        })
                    ];
                    
                    return $result;
                });
            });
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

        if (blank($this->bank->plaid_access_token)) {
            Flux::toast(
                heading: 'No Plaid Connection',
                text: 'Reconnect this bank before downloading statements.',
                variant: 'danger',
            );

            return null;
        }

        $bankAccounts = $this->bank->accounts()
            ->withTrashed()
            ->whereNotNull('plaid_account_id')
            ->get(['id', 'account_number', 'plaid_account_id']);

        if ($bankAccounts->isEmpty()) {
            Flux::toast(
                heading: 'No Plaid Accounts',
                text: 'This bank has no linked Plaid accounts to download statements from.',
                variant: 'danger',
            );

            return null;
        }

        $statementsResponse = $plaidService->getStatements(
            $this->bank->plaid_access_token,
            null,
            ['bank_id' => $this->bank->id],
        );

        if (($statementsResponse['error'] ?? false) === true) {
            Flux::toast(
                heading: 'Statement Download Failed',
                text: $this->formatStatementErrorMessage($statementsResponse),
                variant: 'danger',
            );

            return null;
        }

        $latestStatement = collect($statementsResponse['accounts'] ?? [])
            ->filter(fn (array $account): bool => $bankAccounts->contains('plaid_account_id', $account['account_id'] ?? null))
            ->flatMap(function (array $account) use ($bankAccounts) {
                $bankAccount = $bankAccounts->firstWhere('plaid_account_id', $account['account_id'] ?? null);

                return collect($account['statements'] ?? [])->map(function (array $statement) use ($bankAccount) {
                    return [
                        'statement_id' => $statement['statement_id'] ?? null,
                        'date_posted' => $statement['date_posted'] ?? null,
                        'year' => $statement['year'] ?? null,
                        'month' => $statement['month'] ?? null,
                        'end_date' => $statement['end_date'] ?? null,
                        'account_number' => $bankAccount?->account_number,
                    ];
                });
            })
            ->filter(fn (array $statement): bool => filled($statement['statement_id']))
            ->sortByDesc(fn (array $statement): int => $this->statementSortValue($statement))
            ->first();

        if (! $latestStatement) {
            Flux::toast(
                heading: 'No Statements Found',
                text: 'Plaid did not return any statements for this bank yet.',
                variant: 'warning',
            );

            return null;
        }

        $statementBinary = $plaidService->downloadStatement(
            $this->bank->plaid_access_token,
            $latestStatement['statement_id'],
            ['bank_id' => $this->bank->id],
        );

        if (is_array($statementBinary) && ($statementBinary['error'] ?? false) === true) {
            Flux::toast(
                heading: 'Statement Download Failed',
                text: $this->formatStatementErrorMessage($statementBinary),
                variant: 'danger',
            );

            return null;
        }

        $filename = $this->statementFilename($latestStatement);

        return response()->streamDownload(function () use ($statementBinary) {
            echo $statementBinary;
        }, $filename, [
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

        // Process the bank item if no error occurred
        // Note: Item status updates are now handled by ITEM webhooks
        // app(\App\Controllers\TransactionController::class)->plaid_item_status();
        sleep(2);
        $this->render();

        $this->dispatch('confirmProcessStep', 'banks_registered')->to('entry.vendor-registration');
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

    protected function formatStatementErrorMessage(array $response): string
    {
        $errorBody = $response['error_body'] ?? null;

        if (is_array($errorBody) && isset($errorBody['error_code'])) {
            return match ($errorBody['error_code']) {
                'INVALID_ACCESS_TOKEN' => 'Bank connection expired. Reconnect this bank and try again.',
                'ITEM_NOT_SUPPORTED' => 'This bank does not support Plaid statements.',
                'PRODUCT_NOT_READY' => 'Statements are not ready yet. Try again later.',
                'PRODUCT_NOT_ENABLED' => 'Statements are not enabled for this bank yet. Reconnect the bank to add statement access.',
                default => $errorBody['error_message'] ?? ($response['error_message'] ?? 'Could not download the latest statement.'),
            };
        }

        $message = $response['error_message'] ?? 'Could not download the latest statement.';

        if (str_contains($message, "'statements' product is not enabled")) {
            return 'Statements are not enabled for this bank yet. Reconnect the bank to add statement access.';
        }

        return $message;
    }

    protected function statementSortValue(array $statement): int
    {
        if (filled($statement['date_posted'])) {
            return Carbon::parse($statement['date_posted'])->timestamp;
        }

        if (filled($statement['year']) && filled($statement['month'])) {
            return ((int) $statement['year'] * 100) + (int) $statement['month'];
        }

        if (filled($statement['end_date'])) {
            return Carbon::parse($statement['end_date'])->timestamp;
        }

        return 0;
    }

    protected function statementFilename(array $statement): string
    {
        $postedDate = $statement['date_posted']
            ?? $statement['end_date']
            ?? sprintf('%04d-%02d-01', (int) ($statement['year'] ?? now()->year), (int) ($statement['month'] ?? now()->month));

        return sprintf(
            '%s-%s-%s.pdf',
            Str::slug($this->bank->name ?: 'bank-statement'),
            preg_replace('/[^0-9A-Za-z]/', '', (string) ($statement['account_number'] ?? 'account')),
            Carbon::parse($postedDate)->format('Y-m-d'),
        );
    }

    #[Title('Bank')]
    public function render()
    {
        $this->authorize('create', Bank::class);
        return view('livewire.banks.show');
    }
}
