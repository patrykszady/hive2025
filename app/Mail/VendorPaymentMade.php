<?php

namespace App\Mail;

use App\Models\Check;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class VendorPaymentMade extends Mailable
{
    use Queueable, SerializesModels;

    public $check;

    public $check_number;

    public $vendor;

    public $paying_vendor;

    public ?string $trackingId = null;

    public ?string $senderEmail = null;

    public ?int $belongsToVendorId = null;

    public ?string $emailTemplateName = null;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        Vendor $vendor,
        Vendor $paying_vendor,
        Check $check,
        ?string $trackingId = null,
        ?string $senderEmail = null,
        ?int $belongsToVendorId = null,
        ?string $emailTemplateName = null,
    )
    {
        $this->theme = 'transparent';

        $this->check = $check;
        if (isset($this->check->check_number)) {
            $this->check_number = $this->check->check_number;
        } else {
            $this->check_number = $this->check->check_type;
        }

        $this->vendor = $vendor;
        $this->paying_vendor = $paying_vendor;

        $this->trackingId = $trackingId;
        $this->senderEmail = $senderEmail;
        $this->belongsToVendorId = $belongsToVendorId;
        $this->emailTemplateName = $emailTemplateName ?: 'Vendor Payment';

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('vendor_payment'));

            if (is_string($this->trackingId) && $this->trackingId !== '') {
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('tracking_id', $this->trackingId));
            }

            if (is_int($this->belongsToVendorId) && $this->belongsToVendorId > 0) {
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('belongs_to_vendor_id', (string) $this->belongsToVendorId));
            }

            if (is_string($this->emailTemplateName) && $this->emailTemplateName !== '') {
                $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('email_template_name', $this->emailTemplateName));
            }
        });
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope()
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
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
            with: [
                'hideFooter' => true,
                'showHeaderBrand' => true,
            ],
        );
    }

    public function headers(): Headers
    {
        $metadata = [
            'email_type' => 'vendor_payment',
            'email_template_name' => $this->emailTemplateName ?: 'Vendor Payment',
        ];

        if (is_string($this->senderEmail) && $this->senderEmail !== '') {
            $metadata['sender_email'] = $this->senderEmail;
        }

        if (is_string($this->trackingId) && $this->trackingId !== '') {
            $metadata['tracking_id'] = $this->trackingId;
        }

        if (is_int($this->belongsToVendorId) && $this->belongsToVendorId > 0) {
            $metadata['belongs_to_vendor_id'] = $this->belongsToVendorId;
        }

        return new Headers(text: [
            'X-Email-Metadata' => json_encode($metadata),
        ]);
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
