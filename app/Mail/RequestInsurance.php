<?php

namespace App\Mail;

use App\Models\Agent;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequestInsurance extends Mailable
{
    use Queueable, SerializesModels;

    public $requesting_vendor;

    public $vendor;

    // public $agent;
    public $agent_expired_docs;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    //Agent $agent,
    public function __construct($agent_expired_docs, Vendor $vendor, Vendor $requesting_vendor)
    {
        $this->agent_expired_docs = $agent_expired_docs;
        // $this->agent = $agent;
        $this->vendor = $vendor;
        $this->requesting_vendor = $requesting_vendor;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope()
    {
        return new Envelope(
            from: new Address('insurance@hive.contractors', 'Hive Contractors'),
            subject: $this->vendor->name.' Insurance Certificate',
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
            markdown: 'emails.insurance_request'
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
