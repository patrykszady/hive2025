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
use Illuminate\Support\Facades\Storage;

/**
 * The audit package, sent when EWCCV has answered for every sub: download
 * links to the merged certificates PDF and the Excel kept on Hive, with the
 * files attached as well when they fit under the mail size limit, and the
 * coverage tally in the body.
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
        public bool $attach = true,
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
        return new Content(markdown: 'emails.audit_ready', with: [
            'pdfUrl' => $this->audit->pdf_path ? route('vendor_docs.audit.download', ['audit' => $this->audit->id, 'file' => 'pdf']) : null,
            'xlsxUrl' => $this->audit->xlsx_path ? route('vendor_docs.audit.download', ['audit' => $this->audit->id, 'file' => 'xlsx']) : null,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (! $this->attach) {
            return [];
        }
        $attachments = [];
        $disk = Storage::disk('local');
        if ($this->audit->pdf_path && $disk->exists($this->audit->pdf_path)) {
            $attachments[] = Attachment::fromStorageDisk('local', $this->audit->pdf_path)->as(basename($this->audit->pdf_path))->withMime('application/pdf');
        }
        if ($this->audit->xlsx_path && $disk->exists($this->audit->xlsx_path)) {
            $attachments[] = Attachment::fromStorageDisk('local', $this->audit->xlsx_path)->as(basename($this->audit->xlsx_path))->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        return $attachments;
    }
}
