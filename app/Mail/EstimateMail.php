<?php

namespace App\Mail;

use App\Models\Estimate;
use App\Models\User;
use DOMDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use function str_contains;

class EstimateMail extends Mailable
{
    use Queueable, SerializesModels;

    protected ?string $normalizedHtmlBodyCache = null;

    protected ?string $plainTextBodyCache = null;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Estimate $estimate,
        public User $user,
        public string $fromEmail,
        public string $emailSubject,
        public string $emailBody,
        public array $attachmentPaths = [],
        public ?string $emailTemplateName = null
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
            from: new Address($this->fromEmail, $fromName),
            subject: $this->emailSubject,
            metadata: [
                'estimate_id' => $this->estimate->id,
                'project_id' => $this->estimate->project_id,
                'email_type' => 'estimate',
                'email_template_name' => $this->emailTemplateName,
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: $this->preparedHtmlBody(),
            text: 'mail.estimate-plain',
            with: [
                'textBody' => $this->plainTextBody(),
            ],
        );
    }

    /**
     * Get the message headers.
     */
    public function headers(): Headers
    {
        $metadata = [
            'estimate_id' => $this->estimate->id,
            'email_type' => 'estimate',
        ];

        if ($this->estimate->project_id) {
            $metadata['project_id'] = $this->estimate->project_id;
        }

        if ($this->emailTemplateName) {
            $metadata['email_template_name'] = $this->emailTemplateName;
        }

        return new Headers(
            text: [
                'X-Email-Metadata' => json_encode($metadata),
            ],
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

    protected function preparedHtmlBody(): string
    {
        if ($this->normalizedHtmlBodyCache !== null) {
            return $this->normalizedHtmlBodyCache;
        }

        $html = trim($this->emailBody);

        if ($html === '') {
            return $this->normalizedHtmlBodyCache = '';
        }

        $normalized = $this->normalizeParagraphSpacing($html);

        if ($normalized === null) {
            // If HTML parsing failed, treat as plain text and convert newlines to <br> tags
            return $this->normalizedHtmlBodyCache = '<div style="margin:0;padding:0;white-space:pre-wrap;">' . nl2br(e($html)) . '</div>';
        }

        return $this->normalizedHtmlBodyCache = '<div style="margin:0;padding:0;">' . $normalized . '</div>';
    }

    protected function normalizeParagraphSpacing(string $html): ?string
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        $document = new DOMDocument('1.0', 'UTF-8');

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $html . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        if (! $loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);

            return null;
        }

        foreach ($document->getElementsByTagName('p') as $paragraph) {
            $style = (string) $paragraph->getAttribute('style');

            if (! str_contains(strtolower($style), 'margin')) {
                $style = trim($style);
                $style = $style !== '' ? rtrim($style, ';') . '; margin:0;' : 'margin:0;';
                $paragraph->setAttribute('style', $style);
            }
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);

            return null;
        }

        $innerHtml = '';

        foreach ($body->childNodes as $child) {
            $innerHtml .= $document->saveHTML($child);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        return trim($innerHtml);
    }

    protected function plainTextBody(): string
    {
        if ($this->plainTextBodyCache !== null) {
            return $this->plainTextBodyCache;
        }

        $html = trim($this->emailBody);

        if ($html === '') {
            return $this->plainTextBodyCache = '';
        }

        $normalized = str_ireplace(['<br />', '<br/>', '<br>'], "\n", $html);

        $normalized = preg_replace(
            [
                '/<\/p\s*>/i',
                '/<\/div\s*>/i',
                '/<\/li\s*>/i',
                '/<\/tr\s*>/i',
                '/<\/h[1-6]\s*>/i',
            ],
            [
                "\n\n",
                "\n",
                "\n",
                "\n",
                "\n\n",
            ],
            $normalized
        ) ?? $normalized;

        $text = html_entity_decode(strip_tags($normalized));

        $text = preg_replace(["/\r\n|\r/", "/\n{3,}/"], ["\n", "\n\n"], $text) ?? $text;

        return $this->plainTextBodyCache = trim($text);
    }
}
