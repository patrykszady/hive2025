<?php

namespace App\Services\Deliveries;

use App\Models\AppNotification;
use App\Models\DeliveryText;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\GroupSmsService;
use App\Services\Tasks\TaskWriter;
use App\Support\AdminAlerts;
use App\Support\StreetAddress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A supplier's delivery text becomes a task on the project it is going to.
 * Studio 41 texted "Delivery is scheduled for Wed 10/28 12-3pm at 949 SOUTH
 * ELM COURT, PALATINE … Reply 1 to CONFIRM … order# S2472002" from a number
 * nobody had saved (2026-10-21), and it was put on the schedule by hand.
 *
 * The reader (DeliveryTextReader) says whether a text is a delivery and
 * when, where and for which order; the project is the one open project at
 * that address, matched here in code — never the model's guess. A later
 * text for the same order (or the same supplier's delivery that month)
 * moves the task instead of adding another. Admins are alerted either way;
 * a delivery with no open project at its address is only alerted, with a
 * link to the text. A placed delivery that asks to be confirmed ("Reply 1 to
 * CONFIRM … delivery will be canceled") is answered with that keyword, once
 * — nothing else is ever texted back.
 */
class DeliveryTextIntake
{
    /** Prep, Scheduled, Active, Service Call: a project that takes deliveries. */
    public const OPEN_STATUS_CODES = [4, 5, 6, 8];

    /** Days either side of the delivery day an earlier task for it is looked for. */
    private const SAME_DELIVERY_DAYS = 21;

    /** Only texts that talk about a delivery are sent to the model. */
    private const LOOKS_LIKE_DELIVERY = '/\b(deliver\w*|shipment|shipping|shipped|arriv\w*|truck|driver|drop[- ]?off|pick[- ]?up|will[- ]call)\b/i';

    public function __construct(
        protected DeliveryTextReader $reader,
        protected TaskWriter $tasks,
    ) {
    }

    /**
     * Judge one inbound text. Returns the decision, or null when there was
     * nothing to judge (off, outbound, a client's conversation, no delivery
     * words) or the model could not be asked (the next run tries again).
     */
    public function consider(SmsMessage $message, bool $dryRun = false): ?DeliveryText
    {
        if (! $dryRun && ! config('deliveries.sms_intake.enabled')) {
            return null;
        }

        if (! $message->isInbound()) {
            return null;
        }

        $decided = DeliveryText::query()->where('sms_message_id', $message->id)->first();

        if ($decided && ! $dryRun) {
            return $decided;
        }

        $thread = $message->thread_id ? SmsGroupThread::withoutGlobalScopes()->find($message->thread_id) : null;
        $text = trim((string) $message->display_text);

        // A client's conversation is theirs (planner action items read it).
        if (! $thread || ! $thread->vendor_id || $thread->client_id || $text === '' || ! preg_match(self::LOOKS_LIKE_DELIVERY, $text)) {
            return null;
        }

        $reading = $this->reader->read($text, $message->created_at ?? now());

        if ($reading === null) {
            return null;
        }

        $tenantId = (int) $thread->vendor_id;
        $decision = new DeliveryText([
            'sms_message_id' => $message->id,
            'belongs_to_vendor_id' => $tenantId,
            'supplier' => $reading['supplier'],
            'order_number' => $reading['order_number'],
            'delivery_date' => $reading['date'],
            'extracted' => $reading,
        ]);

        if (! $reading['is_delivery'] || $reading['confidence'] < (float) config('deliveries.sms_intake.min_confidence', 0.7) || ! $reading['date']) {
            return $this->record($decision, DeliveryText::OUTCOME_NOT_DELIVERY, $reading['reason'], $dryRun);
        }

        $project = $this->projectFor($tenantId, $reading['address'], $thread);

        if (! $project) {
            $this->record($decision, DeliveryText::OUTCOME_UNPLACED, 'No one open project at '.($reading['address'] ?: 'an unstated address').'.', $dryRun);

            if (! $dryRun) {
                $this->alert($tenantId, 'delivery_unplaced', 'Delivery text not on a project: '.$this->headline($reading),
                    'No open project at '.($reading['address'] ?: 'the address (none given)').'. Add it to the right project from the text.', $this->threadUrl($thread), $decision);
            }

            return $decision;
        }

        $decision->project_id = $project->id;
        $existing = $this->existingTask($project, $reading);
        $actor = $this->actorFor($tenantId);
        $confirmReply = $this->confirmReplyFor($reading, $text);

        if (! $actor) {
            Log::warning('Delivery texts: no admin to act as', ['vendor_id' => $tenantId]);

            return null;
        }

        if ($existing && $this->sameSlot($existing, $reading)) {
            $decision->task_id = $existing->id;
            $this->record($decision, DeliveryText::OUTCOME_ALREADY_SCHEDULED, $dryRun && $confirmReply ? 'Would reply "'.$confirmReply.'" to confirm.' : null, $dryRun);

            // Already on the schedule, but this text still asks to be confirmed.
            if ($confirmReply && ! $dryRun && $this->confirm($thread, $message, $confirmReply, $decision)) {
                $existing->forceFill(['notes' => trim($this->confirmedLine($confirmReply)."\n\n".$existing->notes)])->saveQuietly();
                $this->alert($tenantId, 'delivery_confirmed', 'Delivery confirmed: '.$this->headline($reading),
                    'Replied "'.$confirmReply.'" to their text, so they will not cancel it.', '/projects/'.$project->id, $decision);
            }

            return $decision;
        }

        if ($dryRun) {
            $decision->task_id = $existing?->id;

            return $this->record($decision, $existing ? DeliveryText::OUTCOME_RESCHEDULED : DeliveryText::OUTCOME_SCHEDULED, $confirmReply ? 'Would reply "'.$confirmReply.'" to confirm.' : null, true);
        }

        $task = $this->actingAs($actor, fn () => $existing
            ? $this->tasks->update($existing, $this->taskData($project, $reading, $message, $thread, $existing, $confirmReply))
            : $this->tasks->store($this->taskData($project, $reading, $message, $thread, null, $confirmReply)));

        $decision->task_id = $task->id;
        $this->record($decision, $existing ? DeliveryText::OUTCOME_RESCHEDULED : DeliveryText::OUTCOME_SCHEDULED, null, false);

        $confirmed = $confirmReply && $this->confirm($thread, $message, $confirmReply, $decision);
        $title = ($existing ? 'Delivery moved: ' : 'Delivery scheduled: ').$this->headline($reading);
        $body = trim($this->windowText($reading).' at '.($project->address ?: $reading['address']).' ('.($project->project_name ?: 'project').').'
            .($confirmed ? ' Confirmed: replied "'.$confirmReply.'" to their text.' : ($reading['needs_confirmation'] ? ' They cancel unless it is confirmed — reply to their text.' : '')));

        $this->alert($tenantId, $existing ? 'delivery_rescheduled' : 'delivery_scheduled', $title, $body,
            $reading['needs_confirmation'] && ! $confirmed ? $this->threadUrl($thread) : '/projects/'.$project->id, $decision);

        return $decision;
    }

    /**
     * The confirm keyword to send, when auto-confirm is on, the text asks to
     * be confirmed, and the text itself names that reply ("Reply 1 to
     * CONFIRM"). Only a short keyword is ever sent — never anything the
     * model wrote.
     */
    protected function confirmReplyFor(array $reading, string $text): ?string
    {
        $reply = trim((string) ($reading['confirm_reply'] ?? ''));

        if (! config('deliveries.sms_intake.auto_confirm') || ! $reading['needs_confirmation'] || preg_match('/^[A-Za-z0-9]{1,5}$/', $reply) !== 1) {
            return null;
        }

        $quoted = preg_quote($reply, '/');

        return preg_match('/\breply\s+["\']?'.$quoted.'["\']?\s+(?:to\s+)?confirm|(?<![A-Za-z0-9])'.$quoted.'["\']?\s+to\s+confirm/i', $text) === 1 ? $reply : null;
    }

    /**
     * Send the confirm reply, once per text. Returns false when it was
     * already answered.
     */
    protected function confirm(SmsGroupThread $thread, SmsMessage $message, string $reply, DeliveryText $decision): bool
    {
        $alreadyAnswered = SmsMessage::withoutGlobalScopes()
            ->where('thread_id', $thread->id)
            ->where('direction', SmsMessage::DIRECTION_OUTBOUND)
            ->where('id', '>', $message->id)
            ->whereRaw('TRIM(text) = ?', [$reply])
            ->exists();

        if ($alreadyAnswered) {
            return false;
        }

        $sent = app(GroupSmsService::class)->sendToThread($thread, $reply);

        $decision->forceFill(['confirmed_at' => now(), 'confirmation_sms_message_id' => $sent->id])->save();

        return true;
    }

    protected function confirmedLine(string $reply): string
    {
        return 'Confirmed by text on '.now()->setTimezone('America/Chicago')->format('M j, Y g:i A').' (replied "'.$reply.'").';
    }

    /**
     * The one open project at the delivery's address — or the thread's own
     * project when the text came in on a project thread.
     */
    protected function projectFor(int $tenantId, ?string $address, SmsGroupThread $thread): ?Project
    {
        $open = fn (Project $project) => in_array((int) $project->latestStatus?->status_code, self::OPEN_STATUS_CODES, true);
        // A queue worker has no signed-in user, so the statuses' tenant scope
        // would hide them all.
        $status = ['latestStatus' => fn ($query) => $query->withoutGlobalScopes()];

        if ($thread->project_id) {
            $project = Project::withoutGlobalScopes()->with($status)->whereNull('deleted_at')
                ->where('belongs_to_vendor_id', $tenantId)->find($thread->project_id);

            if ($project && $open($project)) {
                return $project;
            }
        }

        $key = StreetAddress::matchKey($address);

        if ($key === null) {
            return null;
        }

        $houseNumber = explode(' ', $key)[0];
        $matches = Project::withoutGlobalScopes()
            ->with($status)
            ->whereNull('deleted_at')
            ->where('belongs_to_vendor_id', $tenantId)
            ->where('address', 'like', $houseNumber.' %')
            ->get()
            ->filter(fn (Project $project) => StreetAddress::sameHouse($address, $project->address, $project->city))
            ->filter($open)
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** An earlier task for this delivery: same order number, or the supplier's delivery near that day. */
    protected function existingTask(Project $project, array $reading): ?Task
    {
        $day = Carbon::parse($reading['date']);
        $title = $this->taskTitle($reading);

        return Task::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->whereBetween('start_date', [$day->copy()->subDays(self::SAME_DELIVERY_DAYS)->toDateString(), $day->copy()->addDays(self::SAME_DELIVERY_DAYS)->toDateString()])
            ->where(function ($query) use ($reading, $title) {
                $query->where('title', $title);

                if ($reading['order_number']) {
                    $query->orWhere('notes', 'like', '%'.$reading['order_number'].'%');
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    protected function sameSlot(Task $task, array $reading): bool
    {
        // Task options are cast to an object, so the slot is read with data_get().
        $slot = 'time_settings.'.$reading['date'];

        return $task->start_date?->toDateString() === $reading['date']
            && ($reading['start_time'] === null || data_get($task->options, $slot.'.start_time') === $reading['start_time'])
            && ($reading['end_time'] === null || data_get($task->options, $slot.'.end_time') === $reading['end_time']);
    }

    /** @return array<string, mixed> */
    protected function taskData(Project $project, array $reading, SmsMessage $message, SmsGroupThread $thread, ?Task $existing = null, ?string $confirmReply = null): array
    {
        $note = $this->taskNote($reading, $message, $thread, $confirmReply);

        return [
            'title' => $existing?->title ?: $this->taskTitle($reading),
            'project_id' => $project->id,
            'type' => $existing?->type ?: 'Task',
            'vendor_id' => $existing?->vendor_id,
            'user_ids' => $existing?->user_ids ?? [],
            'order' => $existing?->order,
            'dates' => [$reading['date']],
            'time_settings' => $reading['start_time']
                ? [$reading['date'] => ['use_time' => true, 'start_time' => $reading['start_time'], 'end_time' => $reading['end_time']]]
                : [],
            'notes' => $existing ? trim($note."\n\n".$existing->notes) : $note,
        ];
    }

    protected function taskTitle(array $reading): string
    {
        return Str::limit(($reading['supplier'] ?: 'Supplier').' delivery', 80, '');
    }

    protected function taskNote(array $reading, SmsMessage $message, SmsGroupThread $thread, ?string $confirmReply = null): string
    {
        $line = trim(($reading['supplier'] ?: 'Supplier')
            .($reading['order_number'] ? ' order '.$reading['order_number'] : '')
            .': delivery '.$this->windowText($reading)
            .($reading['address'] ? ' at '.$reading['address'] : '')
            .($reading['summary'] ? '; '.$reading['summary'] : '').'.');

        if ($confirmReply) {
            $line .= ' '.$this->confirmedLine($confirmReply);
        } elseif ($reading['needs_confirmation']) {
            $line .= ' They cancel unless it is confirmed — reply to their text (Messages, thread '.$thread->id.').';
        }

        $sent = ($message->created_at ?? now())->copy()->setTimezone('America/Chicago')->format('M j, Y g:i A');

        return $line."\n\nText from ".($message->from_number ?: 'an unknown number')." on {$sent}:\n\"".Str::limit(Str::squish((string) $message->display_text), 900).'"';
    }

    /** "Wed 10/28, 12–3 pm" */
    protected function windowText(array $reading): string
    {
        $day = Carbon::parse($reading['date'])->format('D n/j');

        if (! $reading['start_time']) {
            return $day;
        }

        $time = fn (string $value) => Carbon::createFromFormat('H:i', $value)->format(str_ends_with($value, ':00') ? 'g' : 'g:i');
        $meridiem = fn (string $value) => Carbon::createFromFormat('H:i', $value)->format('a') === 'am' ? 'am' : 'pm';

        return $reading['end_time']
            ? $day.', '.$time($reading['start_time']).'–'.$time($reading['end_time']).' '.$meridiem($reading['end_time'])
            : $day.', '.$time($reading['start_time']).' '.$meridiem($reading['start_time']);
    }

    /** "Studio 41, Wed 10/28" */
    protected function headline(array $reading): string
    {
        return ($reading['supplier'] ?: 'Supplier').', '.Carbon::parse($reading['date'])->format('D n/j');
    }

    protected function threadUrl(SmsGroupThread $thread): string
    {
        return '/messages?threadId='.$thread->id;
    }

    protected function record(DeliveryText $decision, string $outcome, ?string $reason, bool $dryRun): DeliveryText
    {
        $decision->outcome = $outcome;
        $decision->reason = $reason ? Str::limit($reason, 500, '') : null;

        if (! $dryRun) {
            $decision->save();
        }

        return $decision;
    }

    protected function alert(int $tenantId, string $type, string $title, string $body, string $path, DeliveryText $decision): void
    {
        $admins = $this->admins($tenantId)->pluck('users.id');

        foreach ($admins as $userId) {
            AppNotification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'action_url' => $path,
                'data' => ['delivery_text_id' => $decision->id, 'project_id' => $decision->project_id, 'task_id' => $decision->task_id, 'sms_message_id' => $decision->sms_message_id],
            ]);
        }

        AdminAlerts::push($admins, $type, $title, $body, url($path));
    }

    protected function admins(int $tenantId)
    {
        return User::withoutGlobalScopes()->whereHas('vendors', fn ($query) => $query->withoutGlobalScopes()
            ->where('vendors.id', $tenantId)
            ->where('user_vendor.role_id', 1));
    }

    protected function actorFor(int $tenantId): ?User
    {
        return $this->admins($tenantId)->orderBy('users.id')->first();
    }

    /**
     * Tasks are stamped with the signed-in user (TaskObserver::creating), and
     * this runs from a queue worker.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function actingAs(User $actor, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($actor);

        try {
            return $callback();
        } finally {
            if ($previous) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
