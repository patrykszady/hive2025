<?php

namespace App\Services\Calls;

use App\Models\AppCallingEvent;
use App\Models\CallLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Intake for the iPhone app's on-device calling log. Calls placed while the
 * app is suspended, backgrounded, or driven from Recents, Siri, or the
 * locked screen run where the Metro log channel is dead, so failures there
 * are otherwise invisible — the app keeps a small log of its own and
 * uploads it here the next time it can reach the server.
 *
 * Each event lands on the dedicated 'app-calling' log channel (readable
 * over SSH, like 'telnyx') and in app_calling_events, so a call's device
 * timeline can later show on /calls/{id} — see CallLogResource.
 */
class AppCallingDiagnostics
{
    /**
     * @param  array<int, array{at: string, level: string, message: string, context: array<string, mixed>|null, call_log_id: int|null, call_uuid: string|null}>  $events
     * @return int how many events were stored
     */
    public function ingest(User $user, array $events): int
    {
        $device = (string) request()->header('User-Agent');
        $receivedAt = now();
        $rows = [];

        foreach ($events as $event) {
            $callLogId = $this->visibleCallLogId($user, $event['call_log_id'] ?? null);
            $occurredAt = Carbon::parse($event['at']);
            $context = $event['context'] ?? null;

            Log::channel('app-calling')->log($this->logLevel($event['level']), $event['message'], [
                'user_id' => $user->id,
                'device' => $device,
                'at' => $occurredAt->toIso8601String(),
                'call_log_id' => $callLogId,
                'call_uuid' => $event['call_uuid'] ?? null,
                'context' => $context,
            ]);

            $rows[] = [
                'user_id' => $user->id,
                'call_log_id' => $callLogId,
                'call_uuid' => $event['call_uuid'] ?? null,
                'level' => $event['level'],
                'message' => $event['message'],
                'context' => $context !== null ? json_encode($context) : null,
                'occurred_at' => $occurredAt,
                'created_at' => $receivedAt,
            ];
        }

        if ($rows !== []) {
            AppCallingEvent::query()->insert($rows);
        }

        return count($rows);
    }

    /**
     * Only a call_log_id the uploading user may actually see keeps its
     * link — an id for someone else's call (or one that no longer exists)
     * is stored unlinked rather than rejecting the whole batch.
     */
    protected function visibleCallLogId(User $user, ?int $callLogId): ?int
    {
        if (! $callLogId) {
            return null;
        }

        return CallLog::query()->visibleToMessagesUser($user)->whereKey($callLogId)->exists() ? $callLogId : null;
    }

    protected function logLevel(string $level): string
    {
        return match ($level) {
            'warn' => 'warning',
            'error' => 'error',
            default => 'info',
        };
    }
}
