<?php

namespace App\Jobs;

use App\Http\Controllers\PlaidTransactionSyncController;
use App\Models\Bank;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPlaidTransactionSync implements ShouldQueue, ShouldBeUnique
{
    use Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;
    
    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120];
    
    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bank $bank,
        public string $webhookCode = 'MANUAL_SYNC',
    ) {}

    /**
     * Get the unique ID for the job.
     * This prevents duplicate sync jobs for the same bank.
     */
    public function uniqueId(): string
    {
        return 'plaid_sync_bank_' . $this->bank->id;
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 300; // 5 minutes

    /**
     * Execute the job.
     */
    public function handle(PlaidTransactionSyncController $controller): void
    {
        Log::channel('plaid_adds')->info('ProcessPlaidTransactionSync job starting', [
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'webhook_code' => $this->webhookCode,
            'attempt' => $this->attempts(),
        ]);
        
        try {
            // Refresh the bank model to get latest state
            $this->bank->refresh();
            
            // Check if bank is in error state
            if ($this->bank->plaid_options['error']['error_code'] ?? false) {
                Log::channel('plaid_skips')->info('Skipping sync - bank in error state', [
                    'bank_id' => $this->bank->id,
                    'error_code' => $this->bank->plaid_options['error']['error_code'],
                ]);
                return;
            }
            
            // Perform the sync
            $controller->syncBank($this->bank);
            
            Log::channel('plaid_adds')->info('ProcessPlaidTransactionSync job completed', [
                'bank_id' => $this->bank->id,
                'bank_name' => $this->bank->name,
                'webhook_code' => $this->webhookCode,
            ]);
            
        } catch (Throwable $e) {
            Log::channel('plaid_adds')->error('ProcessPlaidTransactionSync job failed', [
                'bank_id' => $this->bank->id,
                'bank_name' => $this->bank->name,
                'webhook_code' => $this->webhookCode,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('plaid_adds')->error('ProcessPlaidTransactionSync job permanently failed', [
            'bank_id' => $this->bank->id,
            'bank_name' => $this->bank->name,
            'webhook_code' => $this->webhookCode,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'plaid',
            'sync',
            'bank:' . $this->bank->id,
            'vendor:' . $this->bank->vendor_id,
        ];
    }
}
