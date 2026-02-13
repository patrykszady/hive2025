<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeClient extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientName;

    public string $contractorName;

    public string $registerUrl;

    /**
     * @param  int|string  $contractor  Vendor ID (preferred) or contractor name
     * @param  string  $recipientName   First name of the client
     */
    public function __construct(
        int|string $contractor,
        string $recipientName = '',
    ) {
        $this->theme = 'transparent';

        if (is_int($contractor) || ctype_digit((string) $contractor)) {
            $this->contractorName = Vendor::find((int) $contractor)?->short_name ?? 'Your contractor';
        } else {
            $this->contractorName = trim((string) $contractor) ?: 'Your contractor';
        }

        $this->recipientName = $recipientName;
        $this->registerUrl = rtrim((string) config('app.url'), '/') . '/registration';

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('welcome_client'));
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->contractorName . ' has invited you to Hive Contractors',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.welcome_client',
        );
    }

    /** @return array<int, \Illuminate\Mail\Mailables\Attachment> */
    public function attachments(): array
    {
        return [];
    }
}
