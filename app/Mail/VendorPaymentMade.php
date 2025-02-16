<?php

namespace App\Mail;

use App\Models\Check;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorPaymentMade extends Mailable
{
    use Queueable, SerializesModels;

    public $check;

    public $check_number;

    public $vendor;

    public $paying_vendor;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Vendor $vendor, Vendor $paying_vendor, Check $check)
    {
        $this->check = $check;
        if (isset($this->check->check_number)) {
            $this->check_number = $this->check->check_number;
        } else {
            $this->check_number = $this->check->check_type;
        }

        $this->vendor = $vendor;
        $this->paying_vendor = $paying_vendor;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope()
    {
        return new Envelope(
            // from: new Address('support@hive.contractors', 'Hive Contractors'),
            subject: $this->paying_vendor->name.' Payment',
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content()
    {
        return new Content(
            markdown: 'emails.vendor_payment_made',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array
     */
    public function attachments()
    {
        return [];
    }
}
