<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RequestInsurance extends Mailable
{
    use Queueable, SerializesModels;

    public $requesting_vendor;

    public $vendor;

    public $agent_expired_docs;

    /** 'coi' | 'license' | 'documents' — localized via insurance_request.label_* */
    public string $requestType;

    public string $trackingId = '';

    public function __construct($agent_expired_docs, Vendor $vendor, Vendor $requesting_vendor)
    {
        $this->theme = 'transparent';
        $this->trackingId = (string) \Illuminate\Support\Str::uuid();

        $this->agent_expired_docs = $agent_expired_docs;
        $this->vendor = $vendor;
        $this->requesting_vendor = $requesting_vendor;
        $this->requestType = $this->resolveRequestType($agent_expired_docs);

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            try {
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('insurance_request'));
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('tracking_id', $this->trackingId));
            } catch (Throwable) {
                // Mailtrap header classes may not be available in test/CI envs.
            }

            // Hive mark for the shared CTA card (<x-mail.cta />).
            $markPath = public_path('favicon.png');
            if (is_file($markPath)) {
                $message->embedFromPath($markPath, 'hive-mark', 'image/png');
            }

            $message->getHeaders()->addTextHeader('X-Email-Metadata', json_encode([
                'email_template_name' => 'Insurance Request',
                'tracking_id' => $this->trackingId,
                'vendor_id' => $this->vendor->id,
                'belongs_to_vendor_id' => $this->requesting_vendor->id,
            ]));
        });
    }

    public function envelope()
    {
        return new Envelope(
            // From certificates@ so a plain reply (with the new COI attached)
            // lands straight in the ingest mailbox.
            from: new Address(config('nylas.certificates_email'), 'Hive Contractors'),
            subject: __('insurance_request.label_' . $this->requestType) . ' | ' . $this->vendor->name,
        );
    }

    public function content()
    {
        return new Content(
            markdown: 'emails.insurance_request'
        );
    }

    public function attachments()
    {
        return [];
    }

    private function resolveRequestType($docs): string
    {
        $insuranceTypes = ['general', 'professional', 'workers'];

        $hasInsurance = false;
        $hasLicense = false;

        foreach ($docs as $doc) {
            $type = (string) ($doc->attributes['type'] ?? $doc->type ?? '');
            if (in_array($type, $insuranceTypes, true)) {
                $hasInsurance = true;
            } else {
                $hasLicense = true;
            }
        }

        return match (true) {
            $hasInsurance && $hasLicense => 'documents',
            $hasLicense => 'license',
            default => 'coi',
        };
    }
}
