<?php

namespace App\Jobs;

use App\Mail\InitialEstimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendInitialEstimateEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $estimate;

    protected $sections;

    protected $type;

    /**
     * Create a new job instance.
     */
    public function __construct($estimate, $sections, $type)
    {
        $this->estimate = $estimate;
        $this->sections = $sections;
        $this->type = $type;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to('patryk.szady@live.com')
            ->cc(['patryk.szady@live.com'])
            ->send(new InitialEstimate($this->estimate, $this->sections, $this->type));
    }
}
