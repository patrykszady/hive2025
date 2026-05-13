<?php

namespace App\Mail;

use App\Models\LienWaiver;
use App\Models\Vendor;
use App\Support\LienWaiverDocumentGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

class LienWaiverSigningRequest extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientName;

    public string $contractorName;

    public string $signingUrl;

    public string $waiverTypeLabel;

    public string $projectLabel;

    public string $amountFormatted;

    public string $throughDate;

    public function __construct(
        public LienWaiver $waiver,
        string $recipientName = '',
    ) {
        $this->theme = 'transparent';

        $payer = Vendor::withoutGlobalScopes()->find($waiver->belongs_to_vendor_id);
        $this->contractorName = $payer?->short_name ?? $payer?->business_name ?? 'Your contractor';
        $this->recipientName = $recipientName;
        $this->waiverTypeLabel = $waiver->typeLabel();
        $this->amountFormatted = '$' . number_format((float) $waiver->amount, 2);
        $this->throughDate = optional($waiver->through_date)->format('F j, Y') ?? '';
        $this->signingUrl = route('lien-waivers.public-sign', ['token' => $waiver->access_token]);

        $project = $waiver->project;
        $address = $project?->address ?? '';
        $name = $project?->project_name ?? '';
        $this->projectLabel = trim(implode(' | ', array_filter([$address, $name])));

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) use ($waiver): void {
            try {
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('lien_waiver_signing_request'));
            } catch (Throwable) {
                // Mailtrap header class may not be available in test/CI envs.
            }

            $metadata = [
                'email_template_name' => 'Lien Waiver Signing Request',
                'lien_waiver_id' => $waiver->id,
                'project_id' => $waiver->project_id,
                'check_id' => $waiver->check_id,
                'payment_id' => $waiver->payment_id,
                'belongs_to_vendor_id' => $waiver->belongs_to_vendor_id,
                'vendor_id' => $waiver->vendor_id,
            ];
            $message->getHeaders()->addTextHeader('X-Email-Metadata', json_encode($metadata));
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: trim(sprintf(
                '%s | Lien Waiver for Signature (%s)',
                $this->contractorName,
                $this->amountFormatted,
            )),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.lien_waiver_signing_request',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        try {
            $doc = LienWaiverDocumentGenerator::generate($this->waiver);

            return [
                Attachment::fromData(fn () => $doc['binary'], $doc['filename'])
                    ->withMime('application/pdf'),
            ];
        } catch (Throwable) {
            return [];
        }
    }
}
