<?php

namespace App\Services;

use App\Models\EmailTracking;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns an inbound reply into a 'replied' event on the email thread it answers.
 *
 * Both tracking tables promote a 'replied' event to a thread's main status —
 * that display layer shipped long ago and has never once lit up, because
 * nothing wrote the event. This is the producer, built as its own service so
 * the matching logic is testable in isolation and every verdict is auditable.
 *
 * Correlation runs strongest evidence first, and each written event records
 * WHICH layer matched (metadata.matched_via):
 *
 *  1. thread        — the reply's Nylas thread_id equals a sent row's. Exact,
 *                     but only company-email (Nylas) sends have a thread_id.
 *  2. rfc           — the reply's In-Reply-To/References contain a sent row's
 *                     RFC Message-ID. Exact when the sending provider kept
 *                     our Message-ID on the wire; silently absent when not.
 *  3. subject       — the reply came from an address a sent row was addressed
 *                     to AND the subjects agree once reply/forward prefixes
 *                     are stripped ("Re: Your quote" ↔ "Your quote").
 *  4. recipient     — weakest: the latest sent row addressed to the replier
 *                     within a short window. Only consulted when the message
 *                     actually looks like a reply, and labelled so a wrong
 *                     badge can be traced to the guess that produced it.
 *
 * Layers 3 and 4 are gated on looksLikeReply(): thread and RFC matches are
 * reply-proof by construction, but recipient matching alone would badge a
 * client's unrelated NEW email as an answer to whatever we sent them last.
 */
class EmailReplyDetector
{
    /** Layer-4 lookback. Short on purpose: beyond this, a guess is a lie. */
    public const RECIPIENT_MATCH_WINDOW_DAYS = 30;

    /**
     * @param array{
     *   nylas_message_id: string,
     *   from_email: string,
     *   subject?: ?string,
     *   thread_id?: ?string,
     *   in_reply_to?: ?string,
     *   references?: ?string,
     *   message_at?: ?\DateTimeInterface,
     *   mailbox?: ?string,
     * } $reply
     */
    public function record(array $reply): ?EmailTracking
    {
        $nylasMessageId = trim((string) ($reply['nylas_message_id'] ?? ''));
        $fromEmail = strtolower(trim((string) ($reply['from_email'] ?? '')));

        if ($nylasMessageId === '' || $fromEmail === '') {
            return null;
        }

        // Sweeps overlap (crew@ and the personal inboxes both see a reply
        // CC'd to crew@) and reruns re-list messages: one reply, one event.
        $already = EmailTracking::withoutGlobalScopes()
            ->where('event_type', 'replied')
            ->where('metadata->nylas_message_id', $nylasMessageId)
            ->exists();

        if ($already) {
            return null;
        }

        [$sent, $matchedVia] = $this->matchSentRow($reply, $fromEmail);

        if (! $sent) {
            return null;
        }

        $event = EmailTracking::withoutGlobalScopes()->create([
            'belongs_to_vendor_id' => $sent->belongs_to_vendor_id,
            'project_id' => $sent->project_id,
            'lead_id' => $sent->lead_id,
            'message_id' => $sent->message_id,
            'thread_id' => $sent->thread_id,
            'email_template_name' => $sent->email_template_name,
            'event_type' => 'replied',
            'recipient_emails' => [$fromEmail],
            'metadata' => array_filter([
                'nylas_message_id' => $nylasMessageId,
                'subject' => isset($reply['subject']) ? Str::limit(trim((string) $reply['subject']), 500, '') : null,
                'mailbox' => $reply['mailbox'] ?? null,
                'matched_via' => $matchedVia,
                'sent_event_id' => $sent->id,
            ], fn ($v) => $v !== null && $v !== ''),
            'event_at' => $reply['message_at'] ?? now(),
        ]);

        Log::channel('nylas')->info('Reply detector: replied event filed', [
            'email_tracking_id' => $event->id,
            'sent_event_id' => $sent->id,
            'matched_via' => $matchedVia,
            'from' => $fromEmail,
        ]);

        return $event;
    }

    /** @return array{0: ?EmailTracking, 1: ?string} */
    protected function matchSentRow(array $reply, string $fromEmail): array
    {
        // Layer 1 — Nylas thread.
        $threadId = trim((string) ($reply['thread_id'] ?? ''));
        if ($threadId !== '') {
            $sent = $this->sentQuery()
                ->where('thread_id', $threadId)
                ->latest('event_at')
                ->first();

            if ($sent) {
                return [$sent, 'thread'];
            }
        }

        // Layer 2 — RFC Message-IDs named by the reply's threading headers.
        $referencedIds = $this->referencedMessageIds($reply);
        if ($referencedIds !== []) {
            $sent = $this->sentQuery()
                ->where(function ($q) use ($referencedIds) {
                    foreach ($referencedIds as $id) {
                        $q->orWhere('metadata->rfc_message_id', $id);
                    }
                })
                ->latest('event_at')
                ->first();

            if ($sent) {
                return [$sent, 'rfc'];
            }
        }

        if (! $this->looksLikeReply($reply)) {
            return [null, null];
        }

        // Layer 3 — same recipient, same conversation subject. Prefix
        // stripping has to happen in PHP, so bound the candidate set: the
        // newest 50 sends to this address in half a year is generous for a
        // conversation still getting replies.
        $bareSubject = $this->bareSubject((string) ($reply['subject'] ?? ''));
        if ($bareSubject !== '') {
            $sent = $this->sentQuery()
                ->whereJsonContains('recipient_emails', $fromEmail)
                ->where('event_at', '>=', now()->subDays(180))
                ->latest('event_at')
                ->limit(50)
                ->get()
                ->first(fn (EmailTracking $row) => $this->bareSubject(
                    (string) (($row->metadata['subject'] ?? ''))
                ) === $bareSubject);

            if ($sent) {
                return [$sent, 'subject'];
            }
        }

        // Layer 4 — latest recent send to this address. A guess, and says so.
        $sent = $this->sentQuery()
            ->whereJsonContains('recipient_emails', $fromEmail)
            ->where('event_at', '>=', now()->subDays(self::RECIPIENT_MATCH_WINDOW_DAYS))
            ->latest('event_at')
            ->first();

        return [$sent, $sent ? 'recipient' : null];
    }

    protected function sentQuery()
    {
        return EmailTracking::withoutGlobalScopes()->where('event_type', 'sent');
    }

    /**
     * RFC Message-IDs the reply claims to answer, angle brackets stripped.
     * In-Reply-To names the direct parent; References lists the whole chain,
     * so a reply-to-a-reply still reaches the message we originally sent.
     *
     * @return string[]
     */
    protected function referencedMessageIds(array $reply): array
    {
        $raw = trim(((string) ($reply['in_reply_to'] ?? '')) . ' ' . ((string) ($reply['references'] ?? '')));

        if ($raw === '') {
            return [];
        }

        preg_match_all('/<([^<>\s]+)>/', $raw, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Reply signal for the heuristic layers: RFC threading headers (already
     * consumed above, but their PRESENCE still marks a reply) or a localized
     * Re:-family prefix. Mirrors the triage in CrewLeadEmailService.
     */
    protected function looksLikeReply(array $reply): bool
    {
        if (trim((string) ($reply['in_reply_to'] ?? '')) !== '' || trim((string) ($reply['references'] ?? '')) !== '') {
            return true;
        }

        return (bool) preg_match('/^\s*(re|aw|sv|vs|odp)\s*(\[\d+\])?\s*:/i', (string) ($reply['subject'] ?? ''));
    }

    /** "Re: Re[2]: Fwd: Your quote" → "your quote". Empty when nothing remains. */
    protected function bareSubject(string $subject): string
    {
        $subject = trim($subject);

        // Strip stacked reply/forward prefixes, localized forms included.
        while (preg_match('/^\s*(re|aw|sv|vs|odp|fw|fwd|tr|wg|pd)\s*(\[\d+\])?\s*:\s*/i', $subject, $m)) {
            $subject = substr($subject, strlen($m[0]));
        }

        return mb_strtolower(trim($subject));
    }
}
