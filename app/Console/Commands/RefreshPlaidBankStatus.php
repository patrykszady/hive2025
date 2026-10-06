<?php

namespace App\Console\Commands;

use App\Models\Bank;
use App\Services\PlaidService;
use Illuminate\Console\Command;

/**
 * Asks Plaid, right now, how every bank shown in error is doing, and clears
 * the ones Plaid reports healthy (then syncs them to catch up). For a bank
 * reconnected before the banks page did this itself (2026-10-06: Citibank
 * reconnected at 18:30 UTC, Plaid healthy, the page still on
 * ITEM_LOGIN_REQUIRED and three sync webhooks skipped).
 */
class RefreshPlaidBankStatus extends Command
{
    protected $signature = 'banks:refresh-plaid-status
        {--bank=* : Only these bank IDs (default: every bank shown in error)}';

    protected $description = "Re-check banks in error with Plaid; clear the repaired ones and sync them";

    public function handle(PlaidService $plaid): int
    {
        $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get()
            ->filter(fn (Bank $bank) => ($ids = array_map('intval', (array) $this->option('bank'))) !== []
                ? in_array($bank->id, $ids, true)
                : (bool) ($bank->plaid_options['error']['error_code'] ?? false));

        if ($banks->isEmpty()) {
            $this->info('No bank is shown in error.');

            return self::SUCCESS;
        }

        foreach ($banks as $bank) {
            $before = $bank->plaid_options['error']['error_code'] ?? 'none';
            $result = $plaid->refreshItemStatus($bank);

            $this->line(sprintf('%s (#%d): %s', $bank->name, $bank->id, match (true) {
                ! $result['checked'] => "could not reach Plaid — left as {$before}",
                $result['repaired'] => "{$before} cleared, sync started",
                $result['error'] !== null => 'still in error: '.($result['error']['error_code'] ?? 'unknown'),
                default => 'healthy',
            }));
        }

        return self::SUCCESS;
    }
}
