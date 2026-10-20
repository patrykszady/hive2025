<?php

namespace App\Services\Sms;

use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Meilisearch-backed thread search, shared by the web Messages page
 * (App\Livewire\Sms\SmsThreadList::threads()) and the app's thread list
 * (App\Http\Controllers\Api\App\V1\ThreadController::index()), so a search
 * finds the exact same threads on both. Searches the thread index (which
 * embeds each thread's participant/client/vendor names and its 15 most
 * recent messages) and the dedicated message index (full history), merges
 * and dedupes the matching thread ids; ordering newest-activity-first is
 * left to the caller's own query, same as before this was extracted.
 */
class ThreadSearch
{
    /**
     * @return list<int>
     *
     * @throws \Throwable when Meilisearch itself is unavailable (including
     *         scout.driver being null) — callers catch this and fall back
     *         to {@see applyLikeFallback()} instead, same as SmsThreadList
     *         always has.
     */
    public function matchingThreadIds(string $search, ?int $vendorId, string $subjectFilter, int $limit): array
    {
        $meili = SmsGroupThread::search($search);

        if ($vendorId) {
            $meili->where('vendor_visibility_ids', $vendorId);
        }

        // Scout's builder has no whereNotNull(); filter on indexed 0/1
        // flags. ('unread' is per-user state, not indexed — applied on the
        // DB query the caller builds from these ids.)
        if ($subjectFilter === 'client') {
            $meili->where('has_client', 1);
        } elseif ($subjectFilter === 'vendor') {
            $meili->where('has_subject_vendor', 1);
        }

        $ids = $meili->take(max($limit, 50))->keys()->all();

        // Also match on the dedicated message index so searches hit body
        // text anywhere in a thread's history (the thread index only embeds
        // its most recent messages).
        try {
            $messageIds = SmsMessage::search($search)->take(max($limit, 50))->keys()->all();

            if (! empty($messageIds)) {
                $messageThreadIds = SmsMessage::query()
                    ->whereIn('id', $messageIds)
                    ->whereNotNull('thread_id')
                    ->orderByDesc('created_at')
                    ->pluck('thread_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $ids = array_merge(array_map('intval', $ids), $messageThreadIds);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return array_values(array_unique($ids));
    }

    /**
     * The LIKE-query fallback used when Meilisearch itself is unavailable —
     * participants JSON, project address, message text, and trailing phone
     * digits (≥4 of them) — exactly what SmsThreadList's SQL fallback
     * matched on before this was shared.
     *
     * @param  Builder<SmsGroupThread>  $query
     * @return Builder<SmsGroupThread>
     */
    public function applyLikeFallback(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search): void {
            $q->whereJsonContains('participants', $search)
                ->orWhereHas('project', fn (Builder $pq) => $pq->where('address', 'like', "%{$search}%"))
                ->orWhereHas('messages', fn (Builder $mq) => $mq->where('text', 'like', "%{$search}%"));

            // Phone digit search — match participants by trailing digits
            // (e.g. "6349" matches +18473376349).
            $digits = preg_replace('/\D/', '', $search);
            if (is_string($digits) && strlen($digits) >= 4) {
                $q->orWhereHas('threadParticipants', fn (Builder $pq) => $pq->where('phone_number', 'like', "%{$digits}%"));
            }
        });
    }
}
