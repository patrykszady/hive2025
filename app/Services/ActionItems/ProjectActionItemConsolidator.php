<?php

namespace App\Services\ActionItems;

use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The model's review of a project's open action items, in two passes.
 *
 * Closing pass: batches of items beside the window's call summaries, recent
 * texts and recent email, deciding keep / done (a quoted log line shows it
 * happened or became moot) / drop (not a task). Small batches keep every
 * item in view — one pass over 226 items closed four and one of them
 * wrongly.
 *
 * Merging pass: every still-open title at once, no log, grouping the ones
 * that state the same task in other words ("pull the trigger on the Extend
 * windows" / "place the window order"), which embedding similarity
 * (ProjectActionItemExtractor::merge) does not reach.
 *
 * Every decision is reversible by status: a merged item keeps every source
 * on the one that remains, a done or dropped one keeps the reason.
 */
class ProjectActionItemConsolidator
{
    /** Open items per review; the newest are the ones worth the tokens. */
    public const MAX_ITEMS = 300;

    /** Items per closing batch. */
    public const CLOSE_BATCH = 50;

    public const MAX_CALL_SUMMARIES = 60;

    public const MAX_TEXTS = 80;

    public const MAX_EMAILS = 60;

    /**
     * @return array{merged: int, done: int, dropped: int}
     */
    public function consolidate(Project $project): array
    {
        $stats = ['merged' => 0, 'done' => 0, 'dropped' => 0];
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return $stats;
        }

        $items = ProjectActionItem::query()
            ->where('project_id', $project->id)
            ->open()
            ->orderByDesc('last_mentioned_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        if ($items->isEmpty()) {
            return $stats;
        }

        $since = Carbon::now()->subDays((int) config('action_items.stale_days', 30));
        $log = $this->conversationLog($project, $since);

        foreach ($items->chunk(self::CLOSE_BATCH) as $batch) {
            $decisions = $this->decideClosures($project, $batch->values(), $log, $apiKey);
            if ($decisions !== null) {
                $closed = $this->applyClosures($batch, $decisions);
                $stats['done'] += $closed['done'];
                $stats['dropped'] += $closed['dropped'];
            }
        }

        $open = $items->filter(fn (ProjectActionItem $item) => $item->fresh()?->status === ProjectActionItem::STATUS_OPEN)->values();
        if ($open->count() >= 2) {
            $groups = $this->decideMerges($project, $open, $apiKey);
            if ($groups !== null) {
                $stats['merged'] = $this->applyMerges($open, $groups);
            }
        }

        return $stats;
    }

    /**
     * @param  Collection<int, ProjectActionItem>  $items
     * @return list<array{id: int, action: string, reason: string}>|null
     */
    protected function decideClosures(Project $project, Collection $items, string $log, string $apiKey): ?array
    {
        $itemLines = $items->map(fn (ProjectActionItem $item) => sprintf(
            '#%d | %s | %s | due %s | mentioned %s..%s (%d×)',
            $item->id,
            $item->owner ?: '-',
            Str::limit($item->title, 200, '…'),
            $item->due_on?->toDateString() ?? '-',
            $item->first_mentioned_at?->format('Y-m-d') ?? '-',
            $item->last_mentioned_at?->format('Y-m-d') ?? '-',
            $item->mentions,
        ))->implode("\n");

        $system = <<<PROMPT
You keep the open task list for a home remodeling project ({$project->short_address}) between the contractor (GS; people: Patryk, Greg, the crew) and the client. The items below were extracted from phone calls, text messages and stakeholder email, so some have since happened. Today is {$this->today()}.

Decide for EVERY item id, using only the conversation log (call summaries, texts and email, oldest first) as evidence:
- "done": a log entry explicitly says the task was completed, delivered, paid, ordered, scheduled, decided, or that its event has passed or the plan changed so it no longer applies. In reason, quote the log line (date and words) that shows it. Do not infer completion from related activity.
- "drop": not something a person has to do — a statement, the crew's normal scheduled construction work, or a reminder of something already decided. Give the reason.
- "keep": everything else, including anything you are not sure about.
Return JSON only, matching the schema, with exactly one decision per item id.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['decisions'],
            'properties' => [
                'decisions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'action', 'reason'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'action' => ['type' => 'string', 'enum' => ['keep', 'done', 'drop']],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $parsed = $this->ask($project, $apiKey, 'action_item_closures', $system, "OPEN ITEMS:\n{$itemLines}\n\nCONVERSATION LOG:\n{$log}", $schema);
        if (! is_array($parsed['decisions'] ?? null)) {
            return null;
        }

        return collect($parsed['decisions'])
            ->filter(fn ($d) => is_array($d) && isset($d['id'], $d['action']))
            ->map(fn (array $d) => ['id' => (int) $d['id'], 'action' => (string) $d['action'], 'reason' => Str::limit(trim((string) ($d['reason'] ?? '')), 300, '…')])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ProjectActionItem>  $items
     * @return list<array{keep_id: int, merge_ids: list<int>, reason: string}>|null
     */
    protected function decideMerges(Project $project, Collection $items, string $apiKey): ?array
    {
        $itemLines = $items->map(fn (ProjectActionItem $item) => sprintf(
            '#%d | %s | %s | %s',
            $item->id,
            $item->owner ?: '-',
            Str::limit($item->title, 200, '…'),
            $item->last_mentioned_at?->format('Y-m-d') ?? '-',
        ))->implode("\n");

        $system = <<<PROMPT
These are the open tasks of a home remodeling project ({$project->short_address}), extracted from phone calls, texts and email one per mention, so the same task appears more than once in different words or at different levels of detail (ordering the Extend windows; scheduling Ram Jack; picking up the water heater; getting Agnes's door quote).

Group the items that are the same task. For each group name keep_id — the clearest, most complete wording, preferring the most recent — and merge_ids, the others. Two different tasks on the same topic (research the heater / pick up the heater) are NOT a group; a task for Mark and the same task for Patryk are NOT a group. Leave out items that stand alone. Return JSON only, matching the schema.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['groups'],
            'properties' => [
                'groups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['keep_id', 'merge_ids', 'reason'],
                        'properties' => [
                            'keep_id' => ['type' => 'integer'],
                            'merge_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $parsed = $this->ask($project, $apiKey, 'action_item_merges', $system, "OPEN ITEMS:\n{$itemLines}", $schema);
        if (! is_array($parsed['groups'] ?? null)) {
            return null;
        }

        return collect($parsed['groups'])
            ->filter(fn ($g) => is_array($g) && isset($g['keep_id']) && is_array($g['merge_ids'] ?? null))
            ->map(fn (array $g) => ['keep_id' => (int) $g['keep_id'], 'merge_ids' => array_values(array_map('intval', $g['merge_ids'])), 'reason' => Str::limit(trim((string) ($g['reason'] ?? '')), 300, '…')])
            ->values()
            ->all();
    }

    /**
     * One structured request; null when nothing usable came back.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    protected function ask(Project $project, string $apiKey, string $name, string $system, string $user, array $schema): ?array
    {
        try {
            $response = Http::withToken($apiKey)
                ->timeout(180)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => (string) config('services.openai.task_extraction_model', 'gpt-4.1'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => $name, 'strict' => true, 'schema' => $schema],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProjectActionItemConsolidator: request failed', ['project_id' => $project->id, 'pass' => $name, 'error' => $e->getMessage()]);

            return null;
        }

        $content = $response->successful() ? data_get($response->json(), 'choices.0.message.content') : null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($parsed)) {
            Log::warning('ProjectActionItemConsolidator: nothing usable returned', ['project_id' => $project->id, 'pass' => $name, 'status' => $response->status(), 'body' => Str::limit((string) $response->body(), 300)]);

            return null;
        }

        return $parsed;
    }

    /**
     * @param  Collection<int, ProjectActionItem>  $items
     * @param  list<array{id: int, action: string, reason: string}>  $decisions
     * @return array{done: int, dropped: int}
     */
    protected function applyClosures(Collection $items, array $decisions): array
    {
        $stats = ['done' => 0, 'dropped' => 0];
        $byId = $items->keyBy('id');
        $now = Carbon::now();

        foreach ($decisions as $decision) {
            $item = $byId->get($decision['id']);
            if (! $item || $item->status !== ProjectActionItem::STATUS_OPEN) {
                continue;
            }
            if ($decision['action'] === 'done') {
                $item->update([
                    'status' => ProjectActionItem::STATUS_DONE,
                    'done_at' => $now,
                    'done_evidence' => ['kind' => 'model', 'id' => 0, 'at' => $now->toIso8601String(), 'excerpt' => $decision['reason']],
                ]);
                $stats['done']++;
            } elseif ($decision['action'] === 'drop') {
                $item->update([
                    'status' => ProjectActionItem::STATUS_DISMISSED,
                    'done_evidence' => ['kind' => 'model', 'id' => 0, 'at' => $now->toIso8601String(), 'excerpt' => $decision['reason']],
                ]);
                $stats['dropped']++;
            }
        }

        return $stats;
    }

    /**
     * @param  Collection<int, ProjectActionItem>  $items
     * @param  list<array{keep_id: int, merge_ids: list<int>, reason: string}>  $groups
     */
    protected function applyMerges(Collection $items, array $groups): int
    {
        $byId = $items->keyBy('id');
        $merged = 0;
        $now = Carbon::now();

        foreach ($groups as $group) {
            $target = $byId->get($group['keep_id']);
            if (! $target || $target->status !== ProjectActionItem::STATUS_OPEN) {
                continue;
            }
            foreach ($group['merge_ids'] as $id) {
                $item = $byId->get($id);
                if (! $item || $item->is($target) || $item->status !== ProjectActionItem::STATUS_OPEN) {
                    continue;
                }
                foreach ((array) ($item->sources ?? []) as $source) {
                    $target->absorb([
                        'owner' => $item->owner,
                        'due_on' => $item->due_on?->toDateString(),
                        'at' => Carbon::parse($source['at'] ?? $item->last_mentioned_at),
                        'source' => $source,
                    ]);
                }
                $item->update([
                    'status' => ProjectActionItem::STATUS_DISMISSED,
                    'done_evidence' => ['kind' => 'merged', 'id' => $target->id, 'at' => $now->toIso8601String(), 'excerpt' => $group['reason']],
                ]);
                $merged++;
            }
        }

        return $merged;
    }

    /**
     * The window's call summaries, latest texts and latest email, oldest
     * first, as the evidence for "done".
     */
    protected function conversationLog(Project $project, Carbon $since): string
    {
        $client = $project->client;
        $people = $client ? $client->users()->get() : collect();
        $userIds = $people->pluck('id');
        $phones = $people
            ->map(fn ($user) => substr((string) preg_replace('/\D/', '', (string) $user->cell_phone), -10))
            ->filter(fn ($digits) => strlen($digits) === 10)
            ->unique()
            ->values();
        $names = $people->mapWithKeys(fn ($user) => [substr((string) preg_replace('/\D/', '', (string) $user->cell_phone), -10) => (string) $user->first_name])->all();

        $calls = CallLog::query()
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
            ->where('created_at', '>=', $since)
            ->whereHas('transcript', fn ($query) => $query->whereNotNull('summary'))
            ->orderByDesc('created_at')
            ->limit(self::MAX_CALL_SUMMARIES)
            ->get()
            ->reverse();

        $lines = [];
        foreach ($calls as $call) {
            $transcript = $call->transcript;
            $nextSteps = collect((array) ($transcript?->next_steps ?? []))->filter()->implode('; ');
            $lines[] = sprintf(
                "[call %s] %s%s",
                Carbon::parse($call->created_at)->format('Y-m-d'),
                Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $transcript?->summary)), 700, '…'),
                $nextSteps !== '' ? ' Next: '.Str::limit($nextSteps, 300, '…') : '',
            );
        }

        $threadIds = SmsGroupThread::query()
            ->where(function ($query) use ($project, $client) {
                $query->where('project_id', $project->id);
                if ($client) {
                    $query->orWhere('client_id', $client->id);
                }
            })
            ->pluck('id');

        if ($threadIds->isNotEmpty()) {
            $texts = SmsMessage::query()
                ->whereIn('thread_id', $threadIds)
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')
                ->limit(self::MAX_TEXTS * 2)
                ->get()
                ->filter(fn (SmsMessage $message) => ProjectActionItemExtractor::isWorthReading((string) ($message->display_text ?? $message->text)))
                ->take(self::MAX_TEXTS)
                ->reverse();

            foreach ($texts as $message) {
                $digits = substr((string) preg_replace('/\D/', '', (string) $message->from_number), -10);
                $speaker = $message->direction === 'outbound' ? 'GS' : ($names[$digits] ?? 'Client');
                $lines[] = sprintf(
                    '[text %s] %s: %s',
                    Carbon::parse($message->created_at)->format('Y-m-d'),
                    $speaker,
                    Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) ($message->display_text ?? $message->text))), 240, '…'),
                );
            }
        }

        // Email is only evidence once it is placed on this exact project —
        // same rule the extractor uses, so an email the AI could not confidently
        // place on one of the client's projects is never guessed onto this log.
        $emails = EmailMessage::query()
            ->where('project_id', $project->id)
            ->where('sent_at', '>=', $since)
            ->whereNotNull('summary')
            ->orderByDesc('sent_at')
            ->limit(self::MAX_EMAILS)
            ->get()
            ->reverse();

        foreach ($emails as $email) {
            $nextSteps = collect((array) ($email->next_steps ?? []))->filter()->implode('; ');
            $lines[] = sprintf(
                "[email %s] %s%s",
                Carbon::parse($email->sent_at)->format('Y-m-d'),
                Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $email->summary)), 700, '…'),
                $nextSteps !== '' ? ' Next: '.Str::limit($nextSteps, 300, '…') : '',
            );
        }

        return $lines === [] ? '(nothing in the window)' : implode("\n", $lines);
    }

    protected function today(): string
    {
        return Carbon::now()->setTimezone((string) config('action_items.timezone', 'America/Chicago'))->format('l, Y-m-d');
    }
}
