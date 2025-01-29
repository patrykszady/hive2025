<?php

namespace App\Jobs;

use App\Mail\RequestInsurance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendVendorDocRequestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $agent_expired_docs;

    protected $vendor;

    protected $requesting_vendor;

    protected $agent_email;

    /**
     * Create a new job instance.
     */
    public function __construct($agent_expired_docs, $vendor, $requesting_vendor, $agent_email)
    {
        $this->agent_expired_docs = $agent_expired_docs;
        $this->requesting_vendor = $requesting_vendor;
        $this->vendor = $vendor;
        $this->agent_email = $agent_email;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (env('APP_ENV') === 'production') {
            Mail::to($this->agent_email)
                ->cc([$this->vendor->business_email, $this->requesting_vendor->business_email])
                ->send(new RequestInsurance($this->agent_expired_docs, $this->vendor, $this->requesting_vendor));
        }
    }
}
