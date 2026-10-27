<?php

namespace App\Support\Sms;

use App\Models\SmsMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which text a reaction ("Emphasized “…”", "Reacted 😢 to …") or an iMessage
 * edit notice ("Edited to …") belongs to — the one rule shared by the
 * conversation (ConversationPresenter folds them into their text), deleting a
 * text (its reactions and edits go with it, ThreadAdminActions), and a
 * reaction or edit that arrives after its text was deleted (dropped as it is
 * saved, SmsMessage::booted). Patryk, 2026-10-26: "delete the reaction with
 * it — and if I delete a text before a reaction comes?"
 */
class MessageQuoteMatcher
{
    /** Similarity (similar_text %) an edit notice needs to be tied to a text. */
    public const EDIT_MIN_PERCENT = 40;

    /** The text a reaction quotes: the one containing (or contained in) the quote, closest in length — never another reaction. */
    public function tapbackTarget(SmsMessage $reaction, array $tapback, Collection $candidates): ?SmsMessage
    {
        $quoted = $this->normalize((string) ($tapback['quoted'] ?? ''));
        $quotedLength = mb_strlen($quoted);

        if ($quoted === '') {
            return null;
        }

        return $candidates
            ->filter(function (SmsMessage $candidate) use ($quoted, $reaction) {
                // Never another reaction or edit notice: two people reacting to
                // one long text quote it cut short alike, and each reaction
                // then matched the other more closely than the text itself,
                // so the text lost both badges (found 2026-10-26).
                if ($candidate->id === $reaction->id || ! $candidate->display_text || $this->isFollowUp($candidate)) {
                    return false;
                }
                $text = $this->normalize((string) $candidate->display_text);

                return $text !== '' && (str_contains($text, $quoted) || str_contains($quoted, $text));
            })
            ->sortBy(fn (SmsMessage $candidate) => abs(mb_strlen($this->normalize((string) $candidate->display_text)) - $quotedLength))
            ->first();
    }

    /**
     * The text an edit notice changes: same sender and direction, sent before
     * it, not itself an edit notice, the most similar — when similar enough.
     *
     * @param  Collection<int, int>|null  $excludeIds  edit notices already placed
     */
    public function editTarget(SmsMessage $edit, string $editedText, Collection $candidates, ?Collection $excludeIds = null): ?SmsMessage
    {
        $edited = $this->normalize($editedText);
        $excludeIds ??= collect();

        if ($edited === '') {
            return null;
        }

        $best = $candidates
            ->filter(fn (SmsMessage $candidate) => $candidate->id !== $edit->id
                && ! $excludeIds->contains($candidate->id)
                && $candidate->direction === $edit->direction
                && $candidate->from_number === $edit->from_number
                && ($candidate->created_at === null || $edit->created_at === null || $candidate->created_at->lte($edit->created_at))
                && trim((string) $candidate->display_text) !== ''
                && $candidate->parseRemoteEdit() === null)
            ->map(function (SmsMessage $candidate) use ($edited) {
                similar_text($this->normalize((string) $candidate->display_text), $edited, $percent);

                return ['message' => $candidate, 'percent' => $percent];
            })
            ->sortByDesc('percent')
            ->first();

        return $best && $best['percent'] >= self::EDIT_MIN_PERCENT ? $best['message'] : null;
    }

    /** The text this message reacts to or edits, among $candidates; null when it is neither. */
    public function targetOf(SmsMessage $message, Collection $candidates): ?SmsMessage
    {
        $edited = $message->parseRemoteEdit();
        if ($edited !== null) {
            return $this->editTarget($message, $edited, $candidates);
        }

        $tapback = $message->parseTapback();

        return $tapback && ! empty($tapback['emoji']) ? $this->tapbackTarget($message, $tapback, $candidates) : null;
    }

    /** Whether the message is a reaction or an edit notice at all (a cheap check before any query). */
    public function isFollowUp(SmsMessage $message): bool
    {
        if ($message->parseRemoteEdit() !== null) {
            return true;
        }

        $tapback = $message->parseTapback();

        return $tapback !== null && ! empty($tapback['emoji']);
    }

    /**
     * The reactions and edit notices that belong to $original — what goes
     * when it is deleted. Each is tied by the same rule the thread uses,
     * against the thread's other texts, so a reaction to another message with
     * the same words stays.
     *
     * @return Collection<int, SmsMessage>
     */
    public function followUpsOf(SmsMessage $original): Collection
    {
        if (! $original->thread_id) {
            return collect();
        }

        $thread = SmsMessage::query()
            ->where('thread_id', $original->thread_id)
            ->where('id', '!=', $original->id)
            ->where('created_at', '>=', now()->subYears(2))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
        $candidates = $thread->push($original);

        return $thread
            ->filter(fn (SmsMessage $message) => $message->id !== $original->id
                && ($message->created_at === null || $original->created_at === null || $message->created_at->gte($original->created_at))
                && $this->isFollowUp($message)
                && $this->targetOf($message, $candidates)?->id === $original->id)
            ->values();
    }

    /**
     * A reaction or edit notice that arrives for a text already deleted in
     * Hive: deleted too as it is saved (with whoever deleted its text, and
     * linked to that text — deleted_with_message_id — so restoring the text
     * brings it back). Only when no text still in the thread is a match — a
     * reaction to a live message with the same words is never dropped.
     */
    public function dropIfAboutDeleted(SmsMessage $message): bool
    {
        if (! $message->thread_id || $message->trashed() || ! $this->isFollowUp($message)) {
            return false;
        }

        $recent = SmsMessage::withTrashed()
            ->where('thread_id', $message->thread_id)
            ->where('id', '!=', $message->id)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        if ($this->targetOf($message, $recent->reject->trashed()->values())) {
            return false;
        }

        $deleted = $this->targetOf($message, $recent->filter->trashed()->values());
        if (! $deleted) {
            return false;
        }

        // Linked to its text, so restoring the text brings this reaction back with it.
        $message->forceFill(['deleted_by_user_id' => $deleted->deleted_by_user_id, 'deleted_with_message_id' => $deleted->deleted_with_message_id ?: $deleted->id])->saveQuietly();
        $message->delete();

        return true;
    }

    /**
     * Normalize text used when matching tapback quoted text to actual thread messages.
     */
    public function normalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = $this->repairMojibake($text);
        $text = Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->value();

        return $text;
    }

    /**
     * Lightweight mojibake repair for quote/body mismatches during tapback matching.
     */
    private function repairMojibake(string $text): string
    {
        if (! preg_match('/[ÃÂâðÄÅ]/u', $text)) {
            return $text;
        }

        $candidates = [
            $text,
            @mb_convert_encoding($text, 'Windows-1252', 'UTF-8'),
            @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'),
        ];

        $score = static function (string $value): int {
            $penalty = preg_match_all('/[ÃÂâðÄÅ]/u', $value, $m);
            $signal = preg_match_all('/["“”„óęąśłżźćń]/u', $value, $m2);

            return (int) $signal - ((int) $penalty * 2);
        };

        $best = $text;
        $bestScore = $score($text);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if (! mb_check_encoding($candidate, 'UTF-8')) {
                continue;
            }

            $candidateScore = $score($candidate);
            if ($candidateScore > $bestScore) {
                $best = $candidate;
                $bestScore = $candidateScore;
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $best);

        return is_string($clean) && $clean !== '' ? $clean : $best;
    }
}
