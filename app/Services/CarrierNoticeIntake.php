<?php

namespace App\Services;

use App\Models\VendorDoc;
use App\Support\Ewccv\NoticeParser;
use App\Support\Insurance\CarrierNotice;
use App\Support\Insurance\PdfText;
use App\Support\Insurance\PolicyNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * An insurer's cancellation, non-renewal or reinstatement notice that
 * reached the certificates mailbox, pinned onto the certificate it concerns
 * with nobody reading the inbox (Patryk, 2026-10-14: hands free).
 *
 * The notice is matched by policy number, scored for likeness like the
 * EWCCV matcher: the same number (or one holding the other) is the policy;
 * a number one character off is the policy only when the insured's name
 * is in the notice too. EWCCV's own notices are left to ewccv:notices.
 */
class CarrierNoticeIntake
{
    /** Certificates that ended longer ago than this are not what a notice is about. */
    public const RECENT_DAYS = 120;

    public function __construct(protected PdfText $pdfText) {}

    /**
     * Null when the message is not an insurer's notice; otherwise what was
     * found and the docs it was pinned to.
     *
     * @param  array<string, mixed>  $message  a Nylas message (id, subject, from, body/snippet, date)
     * @param  list<array{id: string, type: string, content: string}>  $documents  its readable attachments
     * @return array{kind: string, effective_date: ?string, matched: list<int>}|null
     */
    public function handle(array $message, array $documents): ?array
    {
        if (NoticeParser::isFromEwccv($message)) {
            return null;
        }

        $text = trim(implode("\n", array_filter([
            (string) ($message['subject'] ?? ''),
            NoticeParser::text($message),
            ...array_map(fn (array $document) => $document['type'] === 'pdf' ? $this->pdfText->extract($document['content']) : '', $documents),
        ])));
        $kind = CarrierNotice::kind($text);
        if ($kind === null) {
            return null;
        }

        $effective = CarrierNotice::effectiveDate($text);
        $matches = $this->matchingDocs($text);
        $messageId = (string) ($message['id'] ?? '');
        $receivedAt = isset($message['date']) ? Carbon::createFromTimestamp((int) $message['date']) : now();

        foreach ($matches as ['doc' => $doc, 'score' => $score]) {
            $options = $doc->options ?? [];
            $seen = (array) ($options['carrier_notice']['message_ids'] ?? []);
            if ($messageId !== '' && in_array($messageId, $seen, true)) {
                continue;
            }
            $options['carrier_notice'] = [
                'kind' => $kind,
                'effective_date' => $effective?->toDateString(),
                'received_at' => $receivedAt->toIso8601String(),
                'from' => $this->sender($message),
                'subject' => Str::limit((string) ($message['subject'] ?? ''), 160),
                'excerpt' => Str::limit((string) preg_replace('/\s+/', ' ', $text), 300),
                'policy_score' => round($score, 2),
                'message_id' => $messageId,
                'message_ids' => array_values(array_unique([...$seen, $messageId])),
            ];
            $doc->options = $options;
            // saveQuietly: the observer would queue a fresh EWCCV lookup for a changed doc.
            $doc->saveQuietly();
        }

        Log::channel('vendor_docs')->info('Insurer notice read', [
            'message_id' => $messageId,
            'subject' => $message['subject'] ?? null,
            'kind' => $kind,
            'effective_date' => $effective?->toDateString(),
            'matched_docs' => $matches->map(fn (array $m) => $m['doc']->id)->all(),
        ]);

        return [
            'kind' => $kind,
            'effective_date' => $effective?->toDateString(),
            'matched' => $matches->map(fn (array $m) => $m['doc']->id)->values()->all(),
        ];
    }

    /**
     * The certificates on file the notice names, with how alike the policy
     * numbers are.
     *
     * @return Collection<int, array{doc: VendorDoc, score: float}>
     */
    protected function matchingDocs(string $text): Collection
    {
        $candidates = CarrierNotice::policyCandidates($text);
        if ($candidates === []) {
            return collect();
        }
        $upper = strtoupper($text);

        return VendorDoc::withoutGlobalScopes()
            ->whereNotNull('number')
            ->where('number', '!=', '')
            ->where(fn ($query) => $query->whereNull('expiration_date')->orWhereDate('expiration_date', '>=', now()->subDays(self::RECENT_DAYS)))
            ->with(['vendor' => fn ($query) => $query->withoutGlobalScopes()])
            ->get()
            ->map(function (VendorDoc $doc) use ($candidates, $upper) {
                $score = max(array_map(fn (string $candidate) => PolicyNumber::similarity($doc->number, $candidate), $candidates));

                return ['doc' => $doc, 'score' => $score, 'named' => $this->namesInsured($doc, $upper)];
            })
            ->filter(fn (array $m) => $m['score'] >= PolicyNumber::SAME || ($m['score'] >= PolicyNumber::LIKELY && $m['named']))
            ->map(fn (array $m) => ['doc' => $m['doc'], 'score' => $m['score']])
            ->values();
    }

    /** At least half of the vendor's distinctive name words appear in the notice. */
    protected function namesInsured(VendorDoc $doc, string $upperText): bool
    {
        $words = collect(preg_split('/[^A-Z0-9]+/', strtoupper((string) $doc->vendor?->business_name)))
            ->filter(fn (string $word) => strlen($word) >= 3 && ! in_array($word, ['INC', 'LLC', 'CORP', 'COMPANY', 'THE', 'AND', 'CONSTRUCTION', 'SERVICES', 'SERVICE'], true))
            ->unique()
            ->values();
        if ($words->isEmpty()) {
            return false;
        }

        return $words->filter(fn (string $word) => preg_match('/\b'.preg_quote($word, '/').'\b/', $upperText))->count() * 2 >= $words->count();
    }

    /** @param  array<string, mixed>  $message */
    protected function sender(array $message): ?string
    {
        $from = $message['from'][0] ?? null;
        if (! is_array($from)) {
            return null;
        }

        return trim(($from['name'] ?? '').' <'.($from['email'] ?? '').'>') ?: null;
    }
}
