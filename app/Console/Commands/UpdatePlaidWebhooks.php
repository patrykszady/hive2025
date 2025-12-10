<?php

namespace App\Console\Commands;

use App\Models\Bank;
use App\Services\PlaidService;
use Illuminate\Console\Command;

class UpdatePlaidWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:update-webhooks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update webhook URLs for all connected Plaid bank accounts';

    /**
     * Execute the console command.
     */
    public function handle(PlaidService $plaidService)
    {
        $webhookUrl = config('plaid.webhook') ?? env('PLAID_WEBHOOK');
        
        if (!$webhookUrl) {
            $this->error('PLAID_WEBHOOK environment variable is not set.');
            return Command::FAILURE;
        }

        $this->info("Updating Plaid webhooks to: {$webhookUrl}");
        
        $banks = Bank::withoutGlobalScopes()
            ->whereNotNull('plaid_access_token')
            ->get();

        if ($banks->isEmpty()) {
            $this->info('No banks with Plaid access tokens found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$banks->count()} bank(s) to update.");
        
        $successCount = 0;
        $failureCount = 0;

        foreach ($banks as $bank) {
            try {
                $result = $plaidService->updateWebhook($bank->plaid_access_token, $webhookUrl);
                
                $this->info("✓ Updated webhook for Bank ID {$bank->id} ({$bank->name})");
                $successCount++;
            } catch (\Exception $e) {
                $this->error("✗ Failed to update Bank ID {$bank->id} ({$bank->name}): {$e->getMessage()}");
                $failureCount++;
            }
        }

        $this->newLine();
        $this->info("Update complete: {$successCount} successful, {$failureCount} failed");

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
