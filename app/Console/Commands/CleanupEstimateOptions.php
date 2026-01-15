<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupEstimateOptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-estimate-options';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove deprecated include_reimbursement key from estimates.options JSON column';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Cleaning up include_reimbursement from estimates.options...');

        $updated = 0;

        DB::table('estimates')
            ->whereNotNull('options')
            ->orderBy('id')
            ->chunk(100, function ($estimates) use (&$updated) {
                foreach ($estimates as $estimate) {
                    $options = json_decode($estimate->options, true);

                    if (is_array($options) && array_key_exists('include_reimbursement', $options)) {
                        unset($options['include_reimbursement']);

                        DB::table('estimates')
                            ->where('id', $estimate->id)
                            ->update(['options' => json_encode($options)]);

                        $updated++;
                    }
                }
            });

        $this->info("Done. Updated {$updated} estimate(s).");

        return Command::SUCCESS;
    }
}
