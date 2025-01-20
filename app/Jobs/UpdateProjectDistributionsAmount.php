<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateProjectDistributionsAmount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $project;

    protected $distribution_ids;

    /**
     * Create a new job instance.
     */
    public function __construct($project, $distribution_ids)
    {
        $this->project = $project;
        $this->distribution_ids = $distribution_ids;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $profit = $this->project->finances['profit'];

        //where in $distribution_ids. $distribution_ids = requesting Vendor all distribution ids
        foreach ($this->project->distributions()->whereIn('distributions.id', $this->distribution_ids)->get() as $distribution) {
            $percent = '.'.$distribution->pivot->percent;
            $amount = round($profit * $percent, 2);

            $this->project->distributions()->updateExistingPivot($distribution, ['amount' => $amount], true);
        }
    }
}
