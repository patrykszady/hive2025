<?php

namespace App\Http\Controllers\Sms;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SmsCacheController extends Controller
{
    /**
     * Snapshot recent threads + messages + recent calls so the service worker
     * (and Alpine on the page) can render an instant offline-capable view.
     */
    public function __invoke(): JsonResponse
    {
        $user = auth()->user();
        $isClient = (bool) $user->is_browsing_as_client;

        $cacheKey = 'sms.cache.user.' . $user->id;

        $payload = Cache::remember($cacheKey, 15, function () use ($user, $isClient) {
            $threads = SmsGroupThread::query()
                ->with([
                    'project:id,address',
                    'client',
                    'subjectVendor:id,business_name,options',
                    'latestMessage',
                    'latestMessage.sentByUser:id,first_name',
                    'threadParticipants:id,thread_id,phone_number',
                ])
                ->when($isClient, function ($q) use ($user) {
                    $clientIds = $user->clients()->pluck('clients.id');
                    $q->whereIn('client_id', $clientIds);
                })
                ->when(! $isClient, function ($q) use ($user) {
                    $vendorId = $user->vendor?->id;
                    if ($vendorId) {
                        $q->visibleToVendor($vendorId);
                    }
                })
                ->orderByDesc('last_activity_at')
                ->limit(30)
                ->get();

            // Last 30 messages per top thread (enough for instant paint).
            $topThreadIds = $threads->take(5)->pluck('id')->all();

            $messages = SmsMessage::query()
                ->whereIn('thread_id', $topThreadIds)
                ->select(['id', 'thread_id', 'direction', 'from_number', 'to_numbers', 'text', 'media_urls', 'status', 'created_at', 'sent_by_user_id'])
                ->orderByDesc('created_at')
                ->limit(150)
                ->get()
                ->groupBy('thread_id')
                ->map(fn ($group) => $group->take(30)->reverse()->values());

            $calls = collect();
            if (! $isClient) {
                $calls = CallLog::query()
                    ->orderByDesc('created_at')
                    ->limit(25)
                    ->get(['id', 'direction', 'from_number', 'to_number', 'caller_name', 'status', 'duration_seconds', 'has_voicemail', 'created_at']);
            }

            return [
                'generated_at' => now()->toIso8601String(),
                'threads' => $threads->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'name_data' => $t->name_data,
                    'last_activity_at' => optional($t->last_activity_at)->toIso8601String(),
                    'project' => $t->project ? ['id' => $t->project->id, 'address' => $t->project->address] : null,
                    'client' => $t->client ? ['id' => $t->client->id, 'name' => $t->client->name] : null,
                    'subject_vendor' => $t->subjectVendor ? ['id' => $t->subjectVendor->id, 'name' => $t->subjectVendor->name ?: $t->subjectVendor->business_name] : null,
                    'participants' => $t->threadParticipants->pluck('phone_number')->values(),
                    'latest_message' => $t->latestMessage ? [
                        'id' => $t->latestMessage->id,
                        'direction' => $t->latestMessage->direction,
                        'from_number' => $t->latestMessage->from_number,
                        'text' => mb_substr((string) $t->latestMessage->text, 0, 160),
                        'sent_by' => $t->latestMessage->sentByUser?->first_name,
                        'created_at' => optional($t->latestMessage->created_at)->toIso8601String(),
                    ] : null,
                ])->values(),
                'messages' => $messages,
                'calls' => $calls,
            ];
        });

        return response()->json($payload)
            ->header('Cache-Control', 'private, max-age=15');
    }
}
