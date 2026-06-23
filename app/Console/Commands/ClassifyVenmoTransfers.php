<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class ClassifyVenmoTransfers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:classify-venmo-transfers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Classify all Venmo transactions as transfers (check_number=1010101) except for excluded transaction IDs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $excludedIds = [6554, 6604];
        
        $updated = Transaction::query()
            ->where('plaid_merchant_description', 'LIKE', '%VENMO%')
            ->whereNotIn('id', $excludedIds)
            ->update([
                'check_number' => '1010101',
                'vendor_id' => null,
            ]);
        
        $this->info("✓ Classified {$updated} Venmo transactions as transfers (check_number=1010101)");
        $this->info("  Excluded transaction IDs: " . implode(', ', $excludedIds));
        
        return self::SUCCESS;
    }
}
