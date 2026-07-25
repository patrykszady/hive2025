<?php

namespace App\Mail;

use App\Models\LienWaiver;
use App\Models\SwornStatement;
use App\Models\Vendor;
use App\Support\LienWaiverDocumentGenerator;
use App\Support\PdfMerger;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The producer's copy of a freshly created draw: the GCSS sworn statement and
 * the GC's own lien waiver, merged into one PDF.
 */
class DrawPackageMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $contractorName = '';

    public string $projectLabel = '';

    public ?string $projectUrl = null;

    public int $drawNumber = 1;

    public string $trackingId = '';

    public function __construct(
        public int $swornStatementId,
        public string $recipientName = '',
    ) {
        $this->theme = 'transparent';
        $this->trackingId = (string) \Illuminate\Support\Str::uuid();

        $statement = SwornStatement::query()->find($swornStatementId);
        $contractor = $statement ? Vendor::withoutGlobalScopes()->find($statement->belongs_to_vendor_id) : null;
        $project = $statement ? \App\Models\Project::withoutGlobalScopes()->find($statement->project_id) : null;

        $this->contractorName = $contractor?->short_name ?? $contractor?->business_name ?? config('app.name');
        $this->drawNumber = $statement?->drawNumber() ?? 1;
        $this->projectLabel = trim(implode(' | ', array_filter([
            (string) ($project?->address ?? ''),
            (string) ($project?->project_name ?? ''),
        ])));
        $this->projectUrl = $project ? route('projects.show', $project->id) : null;
    }

    /**
     * The GC's own waiver riding in this package — it shares the email (and
     * therefore the tracking record) with the GCSS.
     */
    protected function gcWaiver(): ?LienWaiver
    {
        $statement = SwornStatement::withTrashed()->find($this->swornStatementId);

        if (! $statement) {
            return null;
        }

        return LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('vendor_id', $statement->belongs_to_vendor_id)
            ->whereNull('deleted_at')
            ->latest('id')
            ->first();
    }

    public function envelope(): Envelope
    {
        $statement = SwornStatement::query()->find($this->swornStatementId);
        $gcWaiverId = $this->gcWaiver()?->id;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            // Replying with the signed/notarized scans drops them straight
            // into the waivers@ ingest mailbox.
            replyTo: trim((string) config('nylas.waivers_email')) !== ''
                ? [new Address(config('nylas.waivers_email'), 'Hive Waivers')]
                : [],
            subject: trim(sprintf(
                '%s | Draw %d Package | %s',
                $this->contractorName,
                $this->drawNumber,
                strtok($this->projectLabel, '|') ?: $this->projectLabel,
            ), " |\t"),
            // Headers are wired here (not withSymfonyMessage in the
            // constructor) so the queued mailable stays serializable — this
            // closure only exists at send time in the worker.
            using: [function (\Symfony\Component\Mime\Email $message) use ($statement, $gcWaiverId): void {
                try {
                    $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('draw_package'));
                    $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('tracking_id', $this->trackingId));
                } catch (Throwable) {
                    // Mailtrap header classes may not be available in test/CI envs.
                }

                $message->getHeaders()->addTextHeader('X-Email-Metadata', json_encode([
                    'email_template_name' => 'Draw Package',
                    'tracking_id' => $this->trackingId,
                    'sworn_statement_id' => $this->swornStatementId,
                    // The GC's own waiver ships in this same email — one
                    // tracking record covers both documents.
                    'lien_waiver_id' => $gcWaiverId,
                    'project_id' => $statement?->project_id,
                    'belongs_to_vendor_id' => $statement?->belongs_to_vendor_id,
                ]));
            }],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.draw_package',
        );
    }

    /**
     * GCSS + GC waiver as one merged PDF; falls back to separate attachments
     * when Ghostscript isn't available. A draw with no renderable document at
     * all fails the send so the queued job retries.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $statement = SwornStatement::query()->find($this->swornStatementId);

        if (! $statement) {
            throw new \RuntimeException('Sworn statement ' . $this->swornStatementId . ' is gone — cannot build the draw package email.');
        }

        $statementBinary = null;
        if ($statement->path && Storage::disk('files')->exists($statement->path)) {
            $statementBinary = Storage::disk('files')->get($statement->path);
        }

        $gcWaiverBinary = null;
        $gcWaiver = $this->gcWaiver();

        if ($gcWaiver) {
            try {
                $doc = LienWaiverDocumentGenerator::generate($gcWaiver);
                $gcWaiverBinary = str_starts_with($doc['binary'], '%PDF') ? $doc['binary'] : null;
            } catch (Throwable) {
                $gcWaiverBinary = null;
            }
        }

        $packageName = self::packageFilenameFor($statement);
        $merged = PdfMerger::merge(array_filter([$statementBinary, $gcWaiverBinary]));

        if ($merged !== null) {
            return [
                Attachment::fromData(fn () => $merged, $packageName)->withMime('application/pdf'),
            ];
        }

        // No merge possible — attach whatever rendered, separately.
        $attachments = [];
        if (is_string($statementBinary) && str_starts_with($statementBinary, '%PDF')) {
            $attachments[] = Attachment::fromData(fn () => $statementBinary, $statement->filename ?: 'sworn-statement.pdf')
                ->withMime('application/pdf');
        }
        if ($gcWaiverBinary !== null && $gcWaiver) {
            $attachments[] = Attachment::fromData(fn () => $gcWaiverBinary, LienWaiverDocumentGenerator::filenameFor($gcWaiver))
                ->withMime('application/pdf');
        }

        if (empty($attachments)) {
            throw new \RuntimeException('No documents could be rendered for draw package email (statement ' . $statement->id . ').');
        }

        return $attachments;
    }

    /**
     * "gc-draw-package-" + the statement's own naming (vendor, estimate,
     * owner, address, draw, date).
     */
    public static function packageFilenameFor(SwornStatement $statement): string
    {
        $base = (string) ($statement->filename ?: 'sworn-statement.pdf');

        return str_starts_with($base, 'sworn-statement')
            ? 'gc-draw-package' . substr($base, strlen('sworn-statement'))
            : 'gc-draw-package-' . $base;
    }
}
