<?php

namespace App\Console\Commands;

use App\Services\EstimateAI\RuleProposer;
use Illuminate\Console\Command;

/**
 * Nightly: read the corrections estimators made to AI drafts and propose
 * rules for the patterns that keep recurring. A person approves them in the
 * generator's "How we estimate" view.
 */
class ProposeEstimateAiRules extends Command
{
    protected $signature = 'estimates:ai-propose-rules {--vendor= : One company id; every company with drafts otherwise}';

    protected $description = 'Propose estimating rules from the corrections made to AI estimate drafts';

    public function handle(RuleProposer $proposer): int
    {
        $vendorIds = $this->option('vendor') ? collect([(int) $this->option('vendor')]) : RuleProposer::vendorsWithDrafts();
        $total = 0;

        foreach ($vendorIds as $vendorId) {
            $created = $proposer->propose($vendorId);
            $total += count($created);

            foreach ($created as $rule) {
                $this->line("vendor {$vendorId}: proposed — {$rule->text} ({$rule->evidence['note']})");
            }
        }

        $this->info("{$total} rule".($total === 1 ? '' : 's').' proposed.');

        return self::SUCCESS;
    }
}
