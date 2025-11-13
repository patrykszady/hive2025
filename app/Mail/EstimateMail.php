<?php

namespace App\Mail;

use App\Models\Estimate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EstimateMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Estimate $estimate,
        public User $user,
        public string $fromEmail,
        public string $emailSubject,
        public string $emailBody,
        public array $attachmentPaths = []
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $fromName = $this->user->vendor?->name
            ?? trim($this->user->first_name.' '.$this->user->last_name)
            ?: config('app.name');

        return new Envelope(
            from: new Address(config('mail.from.address'), $fromName),
            replyTo: [new Address($this->fromEmail, $fromName)],
            subject: $this->emailSubject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: $this->emailBody,
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return collect($this->attachmentPaths)
            ->map(fn ($path) => Attachment::fromPath($path))
            ->all();
    }
}
