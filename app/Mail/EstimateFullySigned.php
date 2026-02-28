<?php

namespace App\Mail;

use App\Models\Estimate;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class EstimateFullySigned extends Mailable
{
    use Queueable, SerializesModels;

    public string $contractorName;

    public string $recipientName;

    public string $estimateNumber;

    public string $projectLabel;

    public string $clientLastNames;

    public bool $isClient;

    public string $loginUrl;

    /**
     * @param  Estimate  $estimate  The fully-signed estimate
     * @param  string  $recipientName  First name of the recipient
     * @param  bool  $isClient  Whether the recipient is a client (vs vendor)
     */
    public function __construct(
        public Estimate $estimate,
        string $recipientName = '',
        bool $isClient = false,
    ) {
        $this->theme = 'transparent';

        $vendor = Vendor::withoutGlobalScopes()->find($estimate->belongs_to_vendor_id);
        $this->contractorName = $vendor?->short_name ?? $vendor?->business_name ?? 'Contractor';
        $this->recipientName = $recipientName;
        $this->isClient = $isClient;
        $this->estimateNumber = $estimate->number;

        // Build project label: "Address | Project Name"
        $project = $estimate->project;
        $projectParts = array_filter([
            $project?->address,
            $project?->project_name,
        ]);
        $this->projectLabel = implode(' | ', $projectParts);

        // Client last names for subject line
        $client = $project?->client;
        $this->clientLastNames = $client?->last_names ?? '';

        // Login URL for client CTA
        $this->loginUrl = rtrim((string) config('app.url'), '/') . '/login';

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('estimate_fully_signed'));
        });
    }

    public function envelope(): Envelope
    {
        $subjectParts = array_filter([
            $this->contractorName,
            $this->clientLastNames,
            'Contract Fully Signed',
        ]);

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: implode(' | ', $subjectParts),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.estimate_fully_signed',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $path = $this->estimate->signed_contract_path;

        if ($path && Storage::disk('local')->exists($path)) {
            return [
                Attachment::fromStorageDisk('local', $path)
                    ->as('Signed Contract - Estimate ' . $this->estimateNumber . '.pdf')
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
