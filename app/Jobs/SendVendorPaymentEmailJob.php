<?php

namespace App\Jobs;

use App\Mail\VendorPaymentMade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendVendorPaymentEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    protected $auth_user;

    protected $vendor;

    protected $check;

    public $to_email;

    public function __construct($auth_user, $vendor, $check)
    {
        $this->auth_user = $auth_user;
        $this->vendor = $vendor;
        $this->check = $check;

        if ($this->vendor->business_email) {
            $to_email = $this->vendor->business_email;
        } else {
            //1099 or DBA ... Sub shoud have email required?
            $to_email = $this->vendor->users()->where('is_employed', 1)->first()->email;
        }

        $this->to_email = $to_email;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (env('APP_ENV') === 'production') {
            Mail::to($this->to_email)
                ->cc([$this->auth_user->vendor->business_email])
                ->send(new VendorPaymentMade($this->vendor, $this->auth_user->vendor, $this->check));
        }
    }
}
