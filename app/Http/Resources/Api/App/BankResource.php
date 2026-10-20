<?php

namespace App\Http\Resources\Api\App;

use App\Models\Bank;
use App\Models\Check;
use App\Services\PlaidService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A bank as the app's Banks tab shows it — same fields as
 * resources/views/livewire/banks/show.blade.php: the connection error (if
 * any), and every account grouped by number then type, with its balance
 * and uncleared checks. Reconnecting a bank (Plaid Link) stays web-only;
 * this resource only ever reads.
 *
 * @mixin \App\Models\Bank
 */
class BankResource extends JsonResource
{
    protected bool $detail = false;

    /**
     * @param  \Illuminate\Support\Collection<string, Collection>|null  $accounts  Pre-grouped by
     *     BankAccountsGrouper (account_number -> type -> {account, checks}),
     *     batched by the controller for every bank on the index — one call
     *     instead of one per row.
     */
    public function __construct($resource, protected ?Collection $accounts = null)
    {
        parent::__construct($resource);
    }

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Bank $bank */
        $bank = $this->resource;
        $error = $bank->error;

        $row = [
            'id' => $bank->id,
            'name' => $bank->name,
            'error' => $error ? [
                'code' => $error['error_code'] ?? null,
                'message' => $error['display_message'] ?? $error['error_message'] ?? null,
            ] : null,
            'updated_at' => $bank->updated_at?->toIso8601String(),
            'accounts' => $this->accountsArray(),
            'web_path' => '/banks/'.$bank->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            // A Plaid call per bank — only worth making on the detail
            // screen, same as BankShow::getSupportsStatementsProperty().
            'supports_statements' => $this->supportsStatements($bank),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountsArray(): array
    {
        $accounts = $this->accounts ?? collect();
        $rows = [];

        foreach ($accounts as $accountNumber => $byType) {
            foreach ($byType as $type => $entry) {
                $account = $entry['account'];
                $balances = $account->options->balances ?? null;

                $rows[] = [
                    'account_number' => (string) $accountNumber,
                    'type' => (string) $type,
                    'trashed' => $account->trashed(),
                    'balance' => $balances ? (float) ($balances->available ?? $balances->current ?? 0) : null,
                    // Same manual parse as livewire.banks.show.blade.php —
                    // BankAccount's 'options->last_balance_update' => 'date:…'
                    // cast declaration doesn't actually apply through the
                    // sibling 'options' => 'object' cast, so this always
                    // arrives as a plain string.
                    'last_balance_update' => isset($account->options->last_balance_update)
                        ? \Carbon\Carbon::parse($account->options->last_balance_update)->toDateString()
                        : null,
                    'checks' => collect($entry['checks'])->map(fn (Check $check) => [
                        'id' => $check->id,
                        'owner' => $check->owner,
                        'check_type' => $check->check_type,
                        'check_number' => $check->check_number,
                        'date' => $check->date?->toDateString(),
                        'amount' => (float) $check->amount,
                        'web_path' => '/checks/'.$check->id,
                    ])->values()->all(),
                ];
            }
        }

        return $rows;
    }

    private function supportsStatements(Bank $bank): bool
    {
        if (blank($bank->plaid_access_token)) {
            return false;
        }

        return app(PlaidService::class)->institutionSupportsStatements(
            $bank->plaid_ins_id,
            ['bank_id' => $bank->id, 'source' => 'BankResource::supportsStatements'],
        );
    }
}
