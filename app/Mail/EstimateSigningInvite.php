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

    public string $projectLabel;

    public string $projectAddress;

    public string $projectName;

    public string $clientLastNames;

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

        // Build project label: "Address | Project Name"
        $project = $estimate->project;
        $this->projectAddress = $project?->address ?? '';
        $this->projectName = $project?->project_name ?? '';
        $projectParts = array_filter([$this->projectAddress, $this->projectName]);
        $this->projectLabel = implode(' | ', $projectParts);

        // Client last names for subject line
        $client = $project?->client;
        $this->clientLastNames = $client?->last_names ?? '';

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('estimate_signing_invite'));
        });
    }

    public function envelope(): Envelope
    {
        $subjectParts = array_filter([
            $this->contractorName,
            $this->clientLastNames,
            'Contract Ready for Signature',
        ]);

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: implode(' | ', $subjectParts),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.estimate_signing_invite',
        );
    }
}
