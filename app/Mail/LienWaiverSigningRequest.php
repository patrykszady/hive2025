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

    public string $waiverTypeLabel;

    public string $projectLabel;

    public string $amountFormatted;

    public string $throughDate;

    public ?string $replyToEmail = null;

    public string $addressLabel = '';

    public ?string $projectUrl = null;

    public string $contractorPhone = '';

    public string $trackingId = '';

    public function __construct(
        public LienWaiver $waiver,
        string $recipientName = '',
        // Set when the vendor has no email and the mail goes to the GC user
        // who created the draw instead — renders a "please forward" banner.
        public ?string $forwardVendorName = null,
        // True when the amount changed after this waiver was already emailed:
        // the vendor is holding a copy that must not be signed, so both the
        // subject and the body lead with that.
        public bool $superseded = false,
    ) {
        $this->theme = 'transparent';
        $this->trackingId = (string) \Illuminate\Support\Str::uuid();

        $payer = Vendor::withoutGlobalScopes()->find($waiver->belongs_to_vendor_id);
        $this->contractorName = $payer?->short_name ?? $payer?->business_name ?? config('app.name');
        // The email asks the vendor to send the notarized scan back — a plain
        // "Reply" must land in the waivers@ ingest mailbox where the barcode
        // matcher picks it up (GC business email as fallback).
        $this->replyToEmail = trim((string) config('nylas.waivers_email'))
            ?: (trim((string) ($payer?->business_email ?? '')) ?: null);
        $this->recipientName = $recipientName;
        $this->waiverTypeLabel = $waiver->typeLabel();
        $this->amountFormatted = '$' . number_format((float) $waiver->amount, 2);
        $this->throughDate = optional($waiver->through_date)->format('F j, Y') ?? '';

        $project = $waiver->project;
        $address = $project?->address ?? '';
        $name = $project?->project_name ?? '';
        $this->projectLabel = trim(implode(' | ', array_filter([$address, $name])));
        $this->addressLabel = trim($address);

        // The address links to the project on hive.contractors.
        $this->projectUrl = $project ? route('projects.show', $project->id) : null;

        // "Call us ASAP" — the GC's phone, when on file.
        $phoneDigits = preg_replace('/\D+/', '', (string) ($payer?->business_phone ?? ''));
        $this->contractorPhone = strlen($phoneDigits) === 10
            ? preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $phoneDigits)
            : trim((string) ($payer?->business_phone ?? ''));

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) use ($waiver): void {
            // High priority: the draw is blocked until this comes back signed.
            $message->priority(\Symfony\Component\Mime\Email::PRIORITY_HIGHEST);
            $message->getHeaders()->addTextHeader('Importance', 'High');
            $message->getHeaders()->addTextHeader('X-MSMail-Priority', 'High');

            // Step icons + brand mark ride inside the email as CID inline
            // attachments — mail clients can't be trusted to fetch hosted
            // images (and localhost URLs never resolve in dev).
            foreach (['print', 'sign', 'notarize', 'return'] as $step) {
                $iconPath = public_path("images/email/waiver-step-{$step}.png");
                if (is_file($iconPath)) {
                    $message->embedFromPath($iconPath, "waiver-step-{$step}", 'image/png');
                }
            }

            $markPath = public_path('favicon.png');
            if (is_file($markPath)) {
                $message->embedFromPath($markPath, 'hive-mark', 'image/png');
            }

            try {
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('lien_waiver_signing_request'));
                // Correlates the sent record with later Mailtrap open/click
                // webhooks — same tracking scheme as the other mailables.
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('tracking_id', $this->trackingId));
            } catch (Throwable) {
                // Mailtrap header classes may not be available in test/CI envs.
            }

            $metadata = [
                'email_template_name' => 'Lien Waiver Signing Request',
                'tracking_id' => $this->trackingId,
                'lien_waiver_id' => $waiver->id,
                'project_id' => $waiver->project_id,
                'check_id' => $waiver->check_id,
                'payment_id' => $waiver->payment_id,
                'belongs_to_vendor_id' => $waiver->belongs_to_vendor_id,
                'vendor_id' => $waiver->vendor_id,
                'forwarded_to_creator' => $this->forwardVendorName !== null,
            ];
            $message->getHeaders()->addTextHeader('X-Email-Metadata', json_encode($metadata));
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: $this->replyToEmail
                ? [new Address($this->replyToEmail, $this->contractorName)]
                : [],
            subject: trim(($this->superseded ? __('lien_waiver.subject_superseded_prefix') . ' ' : '') . ($this->forwardVendorName
                ? __('lien_waiver.subject_forward', [
                    'vendor' => $this->forwardVendorName,
                    'address' => $this->addressLabel ?: $this->projectLabel,
                ])
                : __('lien_waiver.subject', [
                    'contractor' => $this->contractorName,
                    'address' => $this->addressLabel ?: $this->projectLabel,
                ])), " |\t"),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.lien_waiver_signing_request',
        );
    }

    /**
     * The email's whole point is "print the attached PDF" — a failed render
     * must fail the send (the queued job retries) rather than going out with
     * no attachment or with HTML bytes named .pdf.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $doc = LienWaiverDocumentGenerator::generate($this->waiver);

        if (! str_starts_with($doc['binary'], '%PDF')) {
            throw new \RuntimeException('Lien waiver PDF could not be rendered for email attachment (waiver ' . $this->waiver->id . ').');
        }

        return [
            Attachment::fromData(fn () => $doc['binary'], $doc['filename'])
                ->withMime('application/pdf'),
        ];
    }
}
