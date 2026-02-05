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
        public ?string $replyToEmail,
        public string $emailSubject,
        public string $emailBody,
        public array $attachmentPaths = [],
        public ?string $emailTemplateName = null,
        public ?string $senderIp = null,
        public ?string $trackingId = null,
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

        $replyToEmail = (string) ($this->replyToEmail ?? '');

        return new Envelope(
            from: new Address($this->fromEmail, $fromName),
            replyTo: $replyToEmail !== '' ? [new Address($replyToEmail, $fromName)] : [],
            bcc: [new Address($this->fromEmail, $fromName)],
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
            'sender_email' => $this->user->email,
        ];

        if ($this->estimate->project_id) {
            $metadata['project_id'] = $this->estimate->project_id;
        }

        if ($this->emailTemplateName) {
            $metadata['email_template_name'] = $this->emailTemplateName;
        }

        if ($this->senderIp) {
            $metadata['sender_ip'] = $this->senderIp;
        }

        if ($this->trackingId) {
            $metadata['tracking_id'] = $this->trackingId;
        }

        return new Headers(text: [
            'X-Email-Metadata' => json_encode($metadata),
        ]);
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
            $body = '<div style="margin:0;padding:0;white-space:pre-wrap;">' . nl2br(e($html)) . '</div>';

            return $this->normalizedHtmlBodyCache = $this->wrapHtmlDocumentWithUtf8($body);
        }

        $body = '<div style="margin:0;padding:0;">' . $normalized . '</div>';

        return $this->normalizedHtmlBodyCache = $this->wrapHtmlDocumentWithUtf8($body);
    }


    protected function wrapHtmlDocumentWithUtf8(string $bodyHtml): string
    {
        // If the editor/body already provides a full HTML document, don't double-wrap it.
        // We still ensure a UTF-8 declaration is present.
        if (preg_match('/<\s*html\b/i', $bodyHtml) === 1) {
            if (preg_match('/<\s*meta\s+charset\s*=\s*["\']?utf-?8["\']?/i', $bodyHtml) === 1) {
                return $bodyHtml;
            }

            // Try to inject <meta charset="utf-8"> into an existing <head>.
            if (preg_match('/<\s*head\b[^>]*>/i', $bodyHtml) === 1) {
                return preg_replace(
                    '/(<\s*head\b[^>]*>)/i',
                    '$1<meta charset="utf-8">',
                    $bodyHtml,
                    1
                ) ?? $bodyHtml;
            }

            // No <head> tag; prepend a charset meta.
            return '<meta charset="utf-8">' . $bodyHtml;
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body style="margin:0;padding:0;">'
            . $bodyHtml
            . '</body></html>';
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

        // If the body is comprised of <p> blocks (as produced by the rich editor), render it
        // using <br> separators instead of relying on <p> default spacing (which varies wildly
        // across email clients, especially Outlook).
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body) {
            $elementChildren = [];
            foreach ($body->childNodes as $node) {
                if ($node->nodeType === XML_ELEMENT_NODE) {
                    $elementChildren[] = $node;
                }
                if ($node->nodeType === XML_TEXT_NODE && trim((string) $node->nodeValue) !== '') {
                    $elementChildren[] = $node;
                }
            }

            $onlyParagraphElements = collect($elementChildren)
                ->every(function ($node): bool {
                    if ($node instanceof \DOMText) {
                        return false;
                    }

                    return $node instanceof \DOMElement && strtolower($node->tagName) === 'p';
                });

            if ($onlyParagraphElements) {
                $out = '';
                $pendingBlankLine = false;

                foreach ($elementChildren as $node) {
                    if (! ($node instanceof \DOMElement) || strtolower($node->tagName) !== 'p') {
                        continue;
                    }

                    $paragraphHtml = '';
                    foreach ($node->childNodes as $child) {
                        $paragraphHtml .= $document->saveHTML($child);
                    }

                    $textContent = str_replace("\xc2\xa0", ' ', (string) $node->textContent);
                    $isEmpty = trim($textContent) === '' && preg_match('/<\s*br\b/i', $paragraphHtml) !== 1;

                    if ($isEmpty || trim($paragraphHtml) === '') {
                        $pendingBlankLine = true;
                        continue;
                    }

                    if ($out !== '') {
                        $out .= $pendingBlankLine ? '<br><br>' : '<br>';
                    }

                    $out .= trim($paragraphHtml);
                    $pendingBlankLine = false;
                }

                libxml_clear_errors();
                libxml_use_internal_errors($previousUseInternalErrors);

                return trim($out);
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

        $result = trim($innerHtml);

        return $result;
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
