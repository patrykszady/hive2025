<?php

namespace App\Services\Sms;

use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\GroupSmsService;
use Illuminate\Support\Collection;

/**
 * Forward one or more messages from one thread into another — shared by the
 * web conversation (App\Livewire\Sms\SmsConversation::forwardableThreads()/
 * forwardMessages()) and the app's forward endpoints.
 */
class ThreadForwarder
{
    /**
     * Threads the acting user may forward into, excluding the source thread —
     * mirrors SmsConversation::forwardableThreads() exactly (same visibility,
     * search, eager loads, ordering and limit).
     */
    public function candidateThreads(User $user, bool $isClientUser, int $excludeThreadId, string $search = ''): Collection
    {
        if ($isClientUser) {
            $clientIds = $user->clients()->pluck('clients.id');
            $query = SmsGroupThread::query()->whereIn('client_id', $clientIds);
        } else {
            $vendorId = $user->vendor?->id;
            if (! $vendorId) {
                return collect();
            }
            $query = SmsGroupThread::query()->visibleToVendor($vendorId);
        }

        return $query
            ->where('id', '!=', $excludeThreadId)
            ->when(trim($search) !== '', function ($q) use ($search) {
                $term = '%'.trim($search).'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', $term))
                        ->orWhereHas('subjectVendor', fn ($v) => $v->where('business_name', 'like', $term))
                        ->orWhereHas('project', fn ($p) => $p->where('address', 'like', $term));
                });
            })
            ->with(['client:id,business_name', 'subjectVendor:id,business_name,options', 'project:id,address'])
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();
    }

    /**
     * Forward the given messages (in order) from the source thread into the
     * target thread as one combined "Forwarded" message — mirrors
     * SmsConversation::forwardMessages()'s body-building and media-merging
     * exactly, so the app and the web produce byte-identical forwards.
     *
     * @param  array<int, int>  $messageIds
     * @return array{ok: bool, reason: ?string, count: int}
     */
    public function forward(SmsGroupThread $sourceThread, SmsGroupThread $targetThread, array $messageIds, int $actorId, GroupSmsService $smsService): array
    {
        if ($targetThread->hasPendingOptIn()) {
            return ['ok' => false, 'reason' => 'pending_opt_in', 'count' => 0];
        }

        $messages = SmsMessage::where('thread_id', $sourceThread->id)
            ->whereIn('id', $messageIds)
            ->where('status', '!=', 'scheduled')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return ['ok' => false, 'reason' => 'not_found', 'count' => 0];
        }

        $forwardedSections = [];
        $forwardedMediaUrls = [];

        foreach ($messages as $message) {
            $body = trim((string) $message->display_text);
            if ($body !== '') {
                $forwardedSections[] = $body;
            }

            $mediaUrls = collect(is_array($message->media_urls) ? $message->media_urls : [])
                ->merge(collect(data_get($message->raw_payload, 'media', []))->pluck('url')->all())
                ->filter(static fn ($url) => is_string($url) && trim($url) !== '')
                ->map(static fn (string $url) => trim($url))
                ->values()
                ->all();

            if (! empty($mediaUrls)) {
                $forwardedMediaUrls = array_merge($forwardedMediaUrls, $mediaUrls);
            }
        }

        $forwardedMediaUrls = array_values(array_unique($forwardedMediaUrls));
        $forwardedBody = implode("\n\n", $forwardedSections);
        $forwardedText = $forwardedBody === ''
            ? 'Forwarded'
            : "Forwarded\n\n{$forwardedBody}";

        $smsService->sendToThread($targetThread, $forwardedText, $forwardedMediaUrls, $actorId);

        return ['ok' => true, 'reason' => null, 'count' => $messages->count()];
    }
}
