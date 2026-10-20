<?php

namespace App\Services\Banks;

use App\Models\Bank;
use App\Services\PlaidService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Downloading a bank's latest Plaid statement — extracted from
 * App\Livewire\Banks\BankShow::downloadLatestStatement() so the Hive
 * Contractors app's BankController renders the exact same PDF. Every
 * failure mode returns a human message instead of the binary (the web
 * turns the same cases into a Flux toast via formatStatementErrorMessage()).
 */
class LatestStatementDownloader
{
    /**
     * @return array{binary: string, filename: string}|array{error: string}
     */
    public function download(Bank $bank, PlaidService $plaidService): array
    {
        if (blank($bank->plaid_access_token)) {
            return ['error' => 'Reconnect this bank before downloading statements.'];
        }

        $bankAccounts = $bank->accounts()
            ->withTrashed()
            ->whereNotNull('plaid_account_id')
            ->get(['id', 'account_number', 'plaid_account_id']);

        if ($bankAccounts->isEmpty()) {
            return ['error' => 'This bank has no linked Plaid accounts to download statements from.'];
        }

        $statementsResponse = $plaidService->getStatements(
            $bank->plaid_access_token,
            null,
            ['bank_id' => $bank->id],
        );

        if (($statementsResponse['error'] ?? false) === true) {
            return ['error' => $this->formatStatementErrorMessage($statementsResponse)];
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
            return ['error' => 'Plaid did not return any statements for this bank yet.'];
        }

        $statementBinary = $plaidService->downloadStatement(
            $bank->plaid_access_token,
            $latestStatement['statement_id'],
            ['bank_id' => $bank->id],
        );

        if (is_array($statementBinary) && ($statementBinary['error'] ?? false) === true) {
            return ['error' => $this->formatStatementErrorMessage($statementBinary)];
        }

        return ['binary' => (string) $statementBinary, 'filename' => $this->statementFilename($bank, $latestStatement)];
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

    protected function statementFilename(Bank $bank, array $statement): string
    {
        $postedDate = $statement['date_posted']
            ?? $statement['end_date']
            ?? sprintf('%04d-%02d-01', (int) ($statement['year'] ?? now()->year), (int) ($statement['month'] ?? now()->month));

        return sprintf(
            '%s-%s-%s.pdf',
            Str::slug($bank->name ?: 'bank-statement'),
            preg_replace('/[^0-9A-Za-z]/', '', (string) ($statement['account_number'] ?? 'account')),
            Carbon::parse($postedDate)->format('Y-m-d'),
        );
    }
}
