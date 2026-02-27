<?php

namespace App\Mail;

use App\Models\Estimate;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EstimateSigningInvite extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientName;

    public string $contractorName;

    public string $signingUrl;

    public string $estimateNumber;

    /**
     * @param  Estimate  $estimate  The estimate to sign
     * @param  string  $recipientName  First name of the client user
     */
    public function __construct(
        public Estimate $estimate,
        string $recipientName = '',
    ) {
        $this->theme = 'transparent';

        $vendor = Vendor::withoutGlobalScopes()->find($estimate->belongs_to_vendor_id);
        $this->contractorName = $vendor?->short_name ?? 'Your contractor';
        $this->recipientName = $recipientName;
        $this->estimateNumber = $estimate->number;
        $this->signingUrl = route('estimate.sign', $estimate);

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('estimate_signing_invite'));
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->contractorName . ' — Contract Ready for Signature',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.estimate_signing_invite',
        );
    }
}
