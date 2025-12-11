<?php

namespace App\Console\Commands;

use App\Http\Controllers\PlaidTransactionSyncController;
use App\Models\Bank;
use Illuminate\Console\Command;

class PlaidSyncTransactions extends Command
{
    protected $signature = 'plaid:sync-transactions 
                            {--bank= : Sync a specific bank by ID}
                            {--all : Sync all banks}';

    protected $description = 'Manually sync Plaid transactions for all banks or a specific bank';

    public function handle(PlaidTransactionSyncController $controller): int
    {
        $bankId = $this->option('bank');
        $syncAll = $this->option('all');

        if (!$bankId && !$syncAll) {
            $this->error('You must specify either --bank=<id> or --all');
            return self::FAILURE;
        }

        if ($syncAll) {
            $this->info('Syncing all banks...');
            
            $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get();
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

                if ($bank->plaid_options['error']['error_code'] ?? false) {
                    $this->newLine();
                    $this->warn("  Skipped bank {$bank->id} ({$bank->name}): has Plaid error");
                    $skipped++;
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
