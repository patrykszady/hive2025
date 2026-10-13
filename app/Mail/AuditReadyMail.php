<?php

namespace App\Mail;

use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The audit package, sent when EWCCV has answered for every sub: the merged
 * certificates PDF and the Excel, with the coverage tally in the body.
 */
class AuditReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{covered: int, not_found: int, pending: int, unchecked: int, total: int}  $counts
     * @param  list<string>  $missingFiles
     */
    public function __construct(
        public AuditRequest $audit,
        public string $companyName,
        public array $counts,
        public ?string $pdf,
        public string $xlsx,
        public string $filenameBase,
        public array $missingFiles = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: sprintf('Audit package ready: %s, %s', $this->companyName, $this->audit->label()),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.audit_ready');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn () => $this->xlsx, $this->filenameBase.'.xlsx')
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
        if ($this->pdf !== null) {
            array_unshift($attachments, Attachment::fromData(fn () => $this->pdf, $this->filenameBase.'.pdf')->withMime('application/pdf'));
        }

        return $attachments;
    }
}
