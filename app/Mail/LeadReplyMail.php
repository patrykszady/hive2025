<?php

namespace App\Mail;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class LeadReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public User $user,
        public string $fromEmail,
        public ?string $replyToEmail,
        public string $emailSubject,
        public string $emailBody,
        public ?string $emailTemplateName = null,
        public ?string $senderIp = null,
        public ?string $trackingId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
    ) {
    }

    public function envelope(): Envelope
    {
        $fromName = $this->user->vendor?->name
            ?? trim($this->user->first_name . ' ' . $this->user->last_name)
            ?: config('app.name');

        $replyToEmail = (string) ($this->replyToEmail ?? '');

        $metadata = array_filter([
            'lead_id' => (string) $this->lead->id,
            'email_type' => 'lead_reply',
            'email_template_name' => $this->emailTemplateName,
        ], fn ($value) => $value !== null);

        $bcc = app()->environment('local', 'development', 'testing')
            ? []
            : [new Address($this->fromEmail, $fromName)];

        return new Envelope(
            from: new Address($this->fromEmail, $fromName),
            replyTo: $replyToEmail !== '' ? [new Address($replyToEmail, $fromName)] : [],
            bcc: $bcc,
            subject: $this->emailSubject,
            metadata: $metadata,
        );
    }

    public function content(): Content
    {
        $html = trim($this->emailBody);

        if ($html === '') {
            $html = '';
        } elseif (preg_match('/<\s*html\b/i', $html) !== 1) {
            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;">'
                . $html
                . '</body></html>';
        }

        return new Content(
            htmlString: $html,
            text: 'mail.lead-reply-plain',
            with: [
                'textBody' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $this->emailBody))),
            ],
        );
    }

    public function headers(): Headers
    {
        $text = [];

        if ($this->inReplyTo) {
            $text['In-Reply-To'] = $this->inReplyTo;
        }

        if (! empty($this->references)) {
            $text['References'] = implode(' ', $this->references);
        }

        $metadata = [
            'lead_id' => $this->lead->id,
            'email_type' => 'lead_reply',
            'sender_email' => $this->user->email,
        ];

        if ($this->emailTemplateName) {
            $metadata['email_template_name'] = $this->emailTemplateName;
        }

        if ($this->senderIp) {
            $metadata['sender_ip'] = $this->senderIp;
        }

        if ($this->trackingId) {
            $metadata['tracking_id'] = $this->trackingId;
        }

        $text['X-Email-Metadata'] = json_encode($metadata);

        return new Headers(text: $text);
    }
}
