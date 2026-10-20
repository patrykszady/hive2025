<?php

namespace App\Http\Resources\Api\App;

use App\Models\CallLog;
use App\Models\CallTranscript;
use App\Services\Calls\CallHistory;
use App\Support\Calls\CallTranscriptPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A call as the app's Calls tab shows it, the same facts the web calls list
 * shows: the real other party and their name, the effective status, how many
 * consecutive repeats the row stands for, and the AI summary. With
 * `withDetail()` it adds what the web call detail panel shows: a signed,
 * playable recording link, notes and the cleaned transcript.
 *
 * @mixin CallLog
 */
class CallLogResource extends JsonResource
{
    protected int $count = 1;

    protected bool $detail = false;

    protected ?CallHistory $history = null;

    /** @var array<string, string> phone → answering team member's name */
    protected array $answeredBy = [];

    public function grouped(int $count, CallHistory $history, array $answeredBy = []): static
    {
        $this->count = $count;
        $this->history = $history;
        $this->answeredBy = $answeredBy;

        return $this;
    }

    public function withDetail(CallHistory $history): static
    {
        $this->detail = true;
        $this->history = $history;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $history = $this->history ?? app(CallHistory::class);
        $status = CallHistory::effectiveStatus($this->resource);
        $phone = $history->otherPartyNumber($this->resource);
        $transcript = $this->resource->relationLoaded('transcript') ? $this->resource->transcript : null;
        $hasRecording = (bool) ($this->recording_path && $this->recording_disk) || filled($this->recording_url);

        $row = [
            'id' => $this->id,
            'direction' => $this->direction,
            'status' => $status,
            'missed' => $status === CallLog::STATUS_MISSED,
            'blocked' => $status === 'blocked',
            'count' => $this->count,
            'other_party' => [
                'name' => $history->nameFor($phone),
                'phone' => $phone,
            ],
            // Where this call's contact lives — mirrors the web call detail
            // panel's name link (call-detail.blade.php's $contactUrl); the
            // app navigates to its own client/vendor screen instead of a web
            // route. Null for an unknown caller.
            'contact' => $history->contactFor($phone),
            'answered_by' => $this->answeredBy[(string) $this->forwarded_to] ?? ($this->detail ? $this->resource->agentUser()?->full_name : null),
            'started_at' => $this->created_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'has_voicemail' => (bool) $this->has_voicemail,
            'has_recording' => $hasRecording,
            'has_transcript' => (bool) ($transcript && ($transcript->summary || $transcript->text)),
            'summary' => $transcript?->summary,
        ];

        if (! $this->detail) {
            return $row;
        }

        $presenter = app(CallTranscriptPresenter::class);

        return $row + [
            'recording_url' => $this->recording_path && $this->recording_disk && (int) $this->duration_seconds > 0
                ? URL::temporarySignedRoute('calls.recording.signed', now()->addHours(2), ['call' => $this->id])
                : null,
            'notes' => $this->notes,
            'blocked_number' => $history->isBlocked($request->user(), $phone),
            'transcript' => $transcript ? [
                'status' => $transcript->status,
                'processing' => in_array($transcript->status, [CallTranscript::STATUS_PENDING, CallTranscript::STATUS_TRANSCRIBING], true),
                'summary' => $transcript->summary,
                'language' => $transcript->language,
                'sentiment' => $transcript->sentiment,
                'caller_intent' => $transcript->caller_intent,
                'action_items' => array_values((array) ($transcript->action_items ?? [])),
                'topics' => array_values((array) ($transcript->topics ?? [])),
                'next_steps' => array_values((array) ($transcript->next_steps ?? [])),
                'segments' => collect($presenter->segments($this->resource))
                    ->map(fn (array $segment) => ['speaker' => $segment['speaker'] ?? null, 'text' => $segment['text']])
                    ->values()
                    ->all(),
            ] : ($hasRecording && $this->recording_started_at ? ['status' => 'pending', 'processing' => true, 'summary' => null, 'language' => null, 'sentiment' => null, 'caller_intent' => null, 'action_items' => [], 'topics' => [], 'next_steps' => [], 'segments' => []] : null),
        ];
    }
}
