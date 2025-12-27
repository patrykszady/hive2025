<?php

namespace App\Jobs;

use App\Mail\VendorPaymentMade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
        $to = $this->to_email;
        $cc = array_values(array_filter([
            $this->auth_user->vendor->business_email,
        ]));

        $trackingProvider = (string) config('email_tracking.provider', 'mailtrap');
        $trackingId = (string) Str::uuid();

        $mailable = new VendorPaymentMade(
            vendor: $this->vendor,
            paying_vendor: $this->auth_user->vendor,
            check: $this->check,
            trackingId: $trackingId,
            senderEmail: $this->auth_user->email,
            belongsToVendorId: (int) $this->auth_user->vendor->id,
        );

        $mailer = $trackingProvider === 'mailtrap'
            ? (string) config('email_tracking.mailtrap_mailer', 'mailtrap-sdk')
            : 'mailtrap-sdk';

        Mail::mailer($mailer)
            ->to($to)
            ->cc($cc)
            ->send($mailable);
    }
}
