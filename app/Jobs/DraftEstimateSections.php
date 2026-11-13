<?php

namespace App\Jobs;

use App\Models\EstimateAiRun;
use App\Services\EstimateAI\EstimateSectionsDrafter;
use App\Services\EstimateAI\PlanPictures;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Drafts a whole estimate with Claude, section by section, for one
 * EstimateAiRun (App\Livewire\Estimates\EstimateAIGenerator starts it and
 * then follows the run row). Queued because a whole estimate takes a minute
 * or more — longer than the 60 seconds production gives a web request — and
 * so the draft carries on if the estimator's window closes.
 */
class DraftEstimateSections implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        // Its own supervisor (config/horizon.php): the default one force-stops
        // a worker it scales down after 60 s, job timeout or not.
        $this->onQueue('estimate-ai');
    }

    public function handle(EstimateSectionsDrafter $drafter): void
    {
        $run = EstimateAiRun::find($this->runId);

        if ($run !== null) {
            $drafter->run($run);
        }
    }

    /** The worker died or timed out mid-draft: say so; what was drafted stays on the estimate. */
    public function failed(?Throwable $exception): void
    {
        EstimateAiRun::whereKey($this->runId)
            ->whereIn('status', [EstimateAiRun::QUEUED, EstimateAiRun::DRAFTING, EstimateAiRun::STOPPING])
            ->update([
                'status' => EstimateAiRun::FAILED,
                'error' => 'The draft stopped before it finished. Try again.',
                'finished_at' => now(),
            ]);

        // Plan pictures it never got to read are not left on the disk.
        app(PlanPictures::class)->forgetFor(EstimateAiRun::find($this->runId));
    }
}
