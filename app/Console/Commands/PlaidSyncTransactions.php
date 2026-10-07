<?php

namespace App\Console\Commands;

use App\Http\Controllers\PlaidTransactionSyncController;
use App\Models\Bank;
use App\Services\PlaidService;
use Illuminate\Console\Command;

/**
 * The daily Plaid sync (04:00 Chicago) and the by-hand one. Webhooks are
 * the only other trigger, and they skip a bank in error, so every healthy
 * bank is synced in case a webhook was missed, and every bank in error is
 * re-checked with Plaid — the moment Plaid reports it healthy its error is
 * cleared and a catch-up sync starts (PlaidService::refreshItemStatus). A
 * bank reconnected on the banks page does not wait for this: that page
 * syncs it right away. Written 2025-12, scheduled 2026-10-06 after a
 * reconnected Citibank sat in error for a week.
 */
class PlaidSyncTransactions extends Command
{
    protected $signature = 'plaid:sync-transactions 
                            {--bank= : Sync a specific bank by ID}
                            {--all : Sync all banks}';

    protected $description = 'Sync Plaid transactions for every healthy bank, or one bank; re-check banks in error and sync those Plaid reports repaired';

    public function handle(PlaidTransactionSyncController $controller, PlaidService $plaid): int
    {
        $bankId = $this->option('bank');
        $syncAll = $this->option('all');

        if (!$bankId && !$syncAll) {
            $this->error('You must specify either --bank=<id> or --all');
            return self::FAILURE;
        }

        if ($syncAll) {
            $this->info('Syncing all banks...');
            
            // The company without its global scope: a scheduled run has no one
            // signed in, and the scope then hides every company — which read as
            // "missing registration_date" and skipped every bank.
            $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')
                ->with(['vendor' => fn ($query) => $query->withoutGlobalScopes()])
                ->get();
            $this->info("Found {$banks->count()} banks with Plaid access tokens.");

            $bar = $this->output->createProgressBar($banks->count());
            $bar->start();

            $synced = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($banks as $bank) {
                if (!$bank->vendor?->registration_date) {
                    $this->newLine();
                    $this->warn("  Skipped bank {$bank->id} ({$bank->name}): missing vendor registration_date");
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // In error: ask Plaid how the Item is now. Repaired (a reconnect we
                // never heard about, a fix at the bank): the error goes and a
                // catch-up sync is queued. Still in error: a person has to reconnect.
                if ($code = $bank->plaid_options['error']['error_code'] ?? false) {
                    $status = $plaid->refreshItemStatus($bank);
                    $this->newLine();

                    if ($status['repaired']) {
                        $this->info("  Bank {$bank->id} ({$bank->name}): {$code} cleared by Plaid — catch-up sync queued");
                        $synced++;
                    } elseif (! $status['checked']) {
                        $this->warn("  Skipped bank {$bank->id} ({$bank->name}): could not reach Plaid — still {$code}");
                        $skipped++;
                    } else {
                        $this->warn("  Skipped bank {$bank->id} ({$bank->name}): still ".($status['error']['error_code'] ?? $code).' — reconnect it on the banks page');
                        $skipped++;
                    }

                    $bar->advance();
                    continue;
                }

                try {
                    $controller->syncBank($bank);
                    $synced++;
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("  Error syncing bank {$bank->id} ({$bank->name}): {$e->getMessage()}");
                    $errors++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info("Sync complete: {$synced} synced, {$skipped} skipped, {$errors} errors");

            return self::SUCCESS;
        }

        // Sync specific bank
        $bank = Bank::withoutGlobalScopes()->find($bankId);

        if (!$bank) {
            $this->error("Bank with ID {$bankId} not found.");
            return self::FAILURE;
        }

        if (!$bank->plaid_access_token) {
            $this->error("Bank {$bank->name} has no Plaid access token.");
            return self::FAILURE;
        }

        $this->info("Syncing bank: {$bank->name} (ID: {$bank->id})");

        try {
            $controller->syncBank($bank);
            $this->info('Sync complete!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
