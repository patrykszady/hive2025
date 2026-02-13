<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteToHive extends Mailable
{
    use Queueable, SerializesModels;

    public string $inviterName;

    public string $recipientName;

    public bool $isClient;

    public string $registerUrl;

    /**
     * @param  int     $vendorId       Vendor ID of the company sending the invite
     * @param  string  $recipientName  First name of the person receiving the invite
     * @param  bool    $isClient       true = client invite, false = vendor/colleague invite
     */
    public function __construct(
        public int $vendorId,
        string $recipientName = '',
        bool $isClient = false,
    ) {
        $this->theme = 'transparent';

        $vendor = Vendor::find($vendorId);
        $this->inviterName = $vendor?->short_name ?? 'Your contractor';
        $this->recipientName = $recipientName;
        $this->isClient = $isClient;
        $this->registerUrl = rtrim((string) config('app.url'), '/') . '/invite/' . base64_encode(json_encode([
            'invite' => $isClient ? 'client' : 'vendor',
            'vid' => $vendorId,
        ]));

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('invite_to_hive'));
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('invite_type', $this->isClient ? 'client' : 'vendor'));
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('vendor_id', (string) $this->vendorId));
        });
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->inviterName . ' has invited you to Hive Contractors',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invite_to_hive',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
