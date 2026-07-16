<?php

namespace App\Console\Commands;

use App\Models\Bank;
use App\Services\PlaidService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pull bank statement PDFs from Plaid (statements product) to the files disk.
 *
 * Used to resolve transactions whose Plaid descriptors arrive masked by the
 * bank (e.g. Capital One's "*********#**********") — the statement PDF shows
 * the full merchant descriptor.
 *
 *   php artisan app:pull-bank-statements 23 --list
 *   php artisan app:pull-bank-statements 23 --month=2026-04 --month=2026-05
 *
 * Idempotent: existing files are skipped. Statements save to
 * statements/{bank-slug}-{account}-{YYYY-MM}.pdf on the files disk.
 */
class PullBankStatements extends Command
{
    protected $signature = 'app:pull-bank-statements
                            {bank : Bank ID}
                            {--month=* : Statement month(s) as YYYY-MM; omit with --list to just enumerate}
                            {--account= : Limit to one account_number (e.g. 4060)}
                            {--list : List available statements without downloading}';

    protected $description = 'List/download bank statement PDFs via Plaid statements to the files disk.';

    public function handle(PlaidService $plaidService): int
    {
        $bank = Bank::withoutGlobalScopes()->find((int) $this->argument('bank'));
        if (! $bank) {
            $this->error('Bank not found.');

            return self::FAILURE;
        }

        if (blank($bank->plaid_access_token)) {
            $this->error("Bank {$bank->id} ({$bank->name}) has no Plaid access token.");

            return self::FAILURE;
        }

        $bankAccounts = $bank->accounts()->withTrashed()
            ->whereNotNull('plaid_account_id')
            ->get(['id', 'account_number', 'plaid_account_id']);

        $response = $plaidService->getStatements(
            $bank->plaid_access_token,
            null,
            ['bank_id' => $bank->id, 'source' => 'app:pull-bank-statements'],
        );

        if (($response['error'] ?? false) === true) {
            $this->error('Plaid statements list failed: '.json_encode($response['error_body'] ?? $response['error_message'] ?? 'unknown'));

            return self::FAILURE;
        }

        $statements = collect($response['accounts'] ?? [])
            ->flatMap(function (array $account) use ($bankAccounts) {
                $bankAccount = $bankAccounts->firstWhere('plaid_account_id', $account['account_id'] ?? null);

                return collect($account['statements'] ?? [])->map(fn (array $s) => [
                    'statement_id' => $s['statement_id'] ?? null,
                    'account_number' => $bankAccount?->account_number ?? ($account['account_id'] ?? '?'),
                    'year' => $s['year'] ?? null,
                    'month' => $s['month'] ?? null,
                    'date_posted' => $s['date_posted'] ?? null,
                    'start_date' => $s['start_date'] ?? null,
                    'end_date' => $s['end_date'] ?? null,
                ]);
            })
            ->filter(fn (array $s) => filled($s['statement_id']))
            ->when($this->option('account'), fn ($c, $acct) => $c->filter(
                fn (array $s) => (string) $s['account_number'] === (string) $acct
            ))
            ->sortBy([['account_number', 'asc'], ['year', 'asc'], ['month', 'asc']])
            ->values();

        if ($statements->isEmpty()) {
            $this->warn('Plaid returned no statements for this bank.');

            return self::SUCCESS;
        }

        $this->table(
            ['Account', 'Year', 'Month', 'Posted', 'Period'],
            $statements->map(fn ($s) => [
                $s['account_number'],
                $s['year'] ?? '—',
                $s['month'] ?? '—',
                $s['date_posted'] ?? '—',
                trim(($s['start_date'] ?? '').' → '.($s['end_date'] ?? ''), ' →'),
            ])->all()
        );

        if ($this->option('list')) {
            return self::SUCCESS;
        }

        $months = collect($this->option('month'))
            ->map(fn ($m) => Carbon::createFromFormat('Y-m', $m))
            ->map(fn (Carbon $m) => ['year' => (int) $m->format('Y'), 'month' => (int) $m->format('n')]);

        if ($months->isEmpty()) {
            $this->warn('No --month given (and not --list) — nothing to download.');

            return self::SUCCESS;
        }

        $wanted = $statements->filter(function (array $s) use ($months) {
            // Prefer year/month fields; fall back to the period end date's month
            $year = (int) ($s['year'] ?? 0);
            $month = (int) ($s['month'] ?? 0);
            if (! $year && filled($s['end_date'])) {
                $end = Carbon::parse($s['end_date']);
                $year = (int) $end->format('Y');
                $month = (int) $end->format('n');
            }

            return $months->contains(fn ($m) => $m['year'] === $year && $m['month'] === $month);
        });

        if ($wanted->isEmpty()) {
            $this->warn('No statements match the requested month(s).');

            return self::SUCCESS;
        }

        foreach ($wanted as $s) {
            $period = $s['year'] && $s['month']
                ? sprintf('%04d-%02d', $s['year'], $s['month'])
                : Carbon::parse($s['end_date'])->format('Y-m');
            $filename = sprintf(
                'statements/%s-%s-%s.pdf',
                Str::slug($bank->name ?: 'bank'),
                preg_replace('/[^0-9A-Za-z]/', '', (string) $s['account_number']),
                $period,
            );

            if (Storage::disk('files')->exists($filename)) {
                $this->line("Exists, skipping: {$filename}");

                continue;
            }

            $binary = $plaidService->downloadStatement(
                $bank->plaid_access_token,
                $s['statement_id'],
                ['bank_id' => $bank->id, 'source' => 'app:pull-bank-statements'],
            );

            if (is_array($binary) && ($binary['error'] ?? false) === true) {
                $this->error("Download failed for {$filename}: ".json_encode($binary['error_body'] ?? $binary['error_message'] ?? 'unknown'));

                continue;
            }

            Storage::disk('files')->put($filename, $binary);
            $this->info("Saved: {$filename} (".number_format(strlen($binary) / 1024, 1)." KB)");
        }

        return self::SUCCESS;
    }
}
