<?php

namespace App\Services\Communications;

use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything said with a project's stakeholders, newest first, in one list:
 * calls (with their AI summary), texts and emails. The same people and
 * threads the action-item extractor reads — calls and threads belong to the
 * client, so a project's are its client's plus anything filed on the project
 * itself — and the emails StakeholderEmailStore kept for it.
 *
 * Callers authorize the project first: none of these models scope access.
 * Shared by the web project page and the Hive Contractors app.
 */
class ProjectCommunicationsTimeline
{
    /** What the Hive app's timeline shows (it doesn't know meetings yet). */
    public const KINDS = ['call', 'sms', 'email'];

    /** Everything the web timeline shows: the app's kinds plus in-person meetings. */
    public const ALL_KINDS = [...self::KINDS, 'meeting'];

    /**
     * @param  list<string>  $kinds
     * @return array{items: list<array<string, mixed>>, next_before: ?string}
     */
    public function forProject(Project $project, ?Carbon $before = null, int $limit = 50, array $kinds = self::KINDS): array
    {
        $limit = max(1, min($limit, 100));
        $kinds = array_values(array_intersect($kinds, self::ALL_KINDS)) ?: self::KINDS;
        $people = $project->client_id
            ? User::withoutGlobalScopes()->whereHas('clients', fn ($query) => $query->withoutGlobalScopes()->where('clients.id', $project->client_id))->get()
            : collect();

        $items = collect();

        if (in_array('call', $kinds, true)) {
            $items = $items->concat($this->calls($project, $people, $before, $limit));
        }
        if (in_array('sms', $kinds, true)) {
            $items = $items->concat($this->texts($project, $people, $before, $limit));
        }
        if (in_array('email', $kinds, true)) {
            $items = $items->concat($this->emails($project, $before, $limit));
        }
        if (in_array('meeting', $kinds, true)) {
            $items = $items->concat($this->meetings($project, $before, $limit));
        }

        $page = $items->sortByDesc(fn (array $item) => $item['at'].'|'.$item['kind'].'|'.str_pad((string) $item['id'], 12, '0', STR_PAD_LEFT))
            ->take($limit)
            ->values();

        return [
            'items' => $page->all(),
            'next_before' => $page->count() === $limit ? $page->last()['at'] : null,
        ];
    }

    /**
     * @param  Collection<int, User>  $people
     * @return Collection<int, array<string, mixed>>
     */
    protected function calls(Project $project, Collection $people, ?Carbon $before, int $limit): Collection
    {
        $userIds = $people->pluck('id');
        $phones = $this->phones($people);

        return CallLog::query()
            ->with('transcript')
            ->where(function ($query) use ($project, $userIds, $phones) {
                $query->where('project_id', $project->id);
                if ($userIds->isNotEmpty()) {
                    $query->orWhereIn('user_id', $userIds)->orWhereIn('contact_user_id', $userIds);
                }
                foreach ($phones as $phone) {
                    $query->orWhere('from_number', 'like', "%{$phone}")->orWhere('to_number', 'like', "%{$phone}");
                }
            })
            ->when($before, fn ($query) => $query->where('created_at', '<', $before))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (CallLog $call) use ($people): array {
                $inbound = $call->direction === 'incoming';
                $number = $inbound ? $call->from_number : $call->to_number;

                return [
                    'kind' => 'call',
                    'id' => $call->id,
                    'at' => Carbon::parse($call->created_at)->toIso8601String(),
                    'direction' => $inbound ? 'inbound' : 'outbound',
                    'who' => $this->nameForNumber($people, (string) $number) ?? ($call->caller_name ?: (string) $number),
                    'title' => $inbound ? 'Incoming call' : 'Outgoing call',
                    'preview' => (string) ($call->transcript?->summary ?? ''),
                    'duration_seconds' => (int) $call->duration_seconds,
                    'status' => $call->status,
                    'has_voicemail' => (bool) $call->has_voicemail,
                    'action_items' => (array) ($call->transcript?->action_items ?? []),
                ];
            });
    }

    /**
     * @param  Collection<int, User>  $people
     * @return Collection<int, array<string, mixed>>
     */
    protected function texts(Project $project, Collection $people, ?Carbon $before, int $limit): Collection
    {
        $threadIds = SmsGroupThread::query()
            ->where(function ($query) use ($project) {
                $query->where('project_id', $project->id);
                if ($project->client_id) {
                    $query->orWhere('client_id', $project->client_id);
                }
            })
            ->pluck('id');

        if ($threadIds->isEmpty()) {
            return collect();
        }

        return SmsMessage::query()
            ->with('sentByUser')
            ->whereIn('thread_id', $threadIds)
            ->when($before, fn ($query) => $query->where('created_at', '<', $before))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (SmsMessage $message) use ($people): array {
                $inbound = $message->direction === 'inbound';
                $who = $inbound
                    ? ($this->nameForNumber($people, (string) $message->from_number) ?? (string) $message->from_number)
                    : (trim((string) ($message->sentByUser?->first_name ?? '')) ?: 'Team');

                return [
                    'kind' => 'sms',
                    'id' => $message->id,
                    'thread_id' => $message->thread_id,
                    'at' => Carbon::parse($message->created_at)->toIso8601String(),
                    'direction' => $inbound ? 'inbound' : 'outbound',
                    'who' => $who,
                    'title' => $inbound ? 'Text from '.$who : 'Text to client',
                    'preview' => Str::limit((string) ($message->display_text ?? $message->text ?? ''), 500),
                    'media_count' => count((array) $message->media_urls),
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function emails(Project $project, ?Carbon $before, int $limit): Collection
    {
        return EmailMessage::query()
            ->where('belongs_to_vendor_id', $project->belongs_to_vendor_id)
            ->forProject($project)
            ->when($before, fn ($query) => $query->where('sent_at', '<', $before))
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get()
            ->map(fn (EmailMessage $email): array => [
                'kind' => 'email',
                'id' => $email->id,
                'at' => $email->sent_at->toIso8601String(),
                'direction' => $email->direction,
                'who' => $email->isInbound() ? ($email->from_name ?: $email->from_email) : ($email->from_name ?: 'Team'),
                'title' => $email->subject ?: '(no subject)',
                'preview' => (string) ($email->summary ?: $email->snippet),
                'attachment_count' => count((array) $email->attachments),
                'action_items' => (array) ($email->action_items ?? []),
            ]);
    }

    /**
     * @param  Collection<int, User>  $people
     * @return Collection<int, string>
     */
    protected function phones(Collection $people): Collection
    {
        return $people
            ->map(fn (User $user) => substr((string) preg_replace('/\D/', '', (string) $user->cell_phone), -10))
            ->filter(fn (string $digits) => strlen($digits) === 10)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, User>  $people
     */
    protected function nameForNumber(Collection $people, string $number): ?string
    {
        $digits = substr((string) preg_replace('/\D/', '', $number), -10);

        if (strlen($digits) !== 10) {
            return null;
        }

        $user = $people->first(fn (User $person) => substr((string) preg_replace('/\D/', '', (string) $person->cell_phone), -10) === $digits);

        return $user ? (trim($user->first_name.' '.$user->last_name) ?: null) : null;
    }

    /**
     * In-person meetings recorded on this project (MeetingRecording), once
     * stopped — transcribing or done.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function meetings(Project $project, ?Carbon $before, int $limit): Collection
    {
        return \App\Models\MeetingRecording::query()
            ->with('recordedBy:id,first_name,last_name,nickname')
            ->where('project_id', $project->id)
            ->whereIn('status', [\App\Models\MeetingRecording::STATUS_PROCESSING, \App\Models\MeetingRecording::STATUS_READY])
            // An uploaded file sits at the time of the meeting, not of the upload.
            ->when($before, fn ($query) => $query->whereRaw('COALESCE(started_at, created_at) < ?', [$before]))
            ->orderByRaw('COALESCE(started_at, created_at) DESC')
            ->limit($limit)
            ->get()
            ->map(fn (\App\Models\MeetingRecording $meeting): array => [
                'kind' => 'meeting',
                'id' => $meeting->id,
                'at' => Carbon::parse($meeting->started_at ?? $meeting->created_at)->toIso8601String(),
                'direction' => 'outbound',
                'who' => $meeting->recordedBy ? \App\Support\Sms\ConversationPresenter::preferredUserDisplayName($meeting->recordedBy) : 'Meeting',
                'title' => $meeting->status === \App\Models\MeetingRecording::STATUS_PROCESSING ? 'In-person meeting (transcribing…)' : 'In-person meeting',
                'preview' => (string) ($meeting->summary ?? ''),
                'duration_seconds' => (int) $meeting->duration_seconds,
                'status' => $meeting->status,
                'action_items' => (array) ($meeting->action_items ?? []),
            ]);
    }
}
