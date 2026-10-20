<?php

namespace App\Mail;

use App\Models\ServiceCallEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The automatic "let's schedule your service call" reply, threaded under the
 * client's own email. Same shape as LeadReplyMail: an HTML body written in
 * code, a plain-text twin, and threading headers.
 */
class ServiceCallScheduleMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceCallEmail $serviceCall,
        public User $actor,
        public string $fromEmail,
        public string $fromName,
        public ?string $replyToEmail,
        public string $emailSubject,
        public string $emailBody,
        public ?string $inReplyTo = null,
        public ?string $trackingId = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $replyTo = trim((string) $this->replyToEmail);

        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            replyTo: $replyTo !== '' ? [new Address($replyTo, $this->fromName)] : [],
            subject: $this->emailSubject,
            metadata: [
                'project_id' => (string) $this->serviceCall->project_id,
                'email_type' => 'service_call_schedule',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;">'.$this->emailBody.'</body></html>',
            text: 'mail.lead-reply-plain',
            with: [
                'textBody' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], "\n", $this->emailBody)))),
            ],
        );
    }

    public function headers(): Headers
    {
        $text = [];

        if ($this->inReplyTo) {
            $text['In-Reply-To'] = $this->inReplyTo;
            $text['References'] = $this->inReplyTo;
        }

        $text['X-Email-Metadata'] = json_encode(array_filter([
            'project_id' => $this->serviceCall->project_id,
            'email_type' => 'service_call_schedule',
            'sender_email' => $this->actor->email,
            'tracking_id' => $this->trackingId,
        ]));

        return new Headers(text: $text);
    }
}
