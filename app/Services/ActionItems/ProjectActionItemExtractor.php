<?php

namespace App\Services\ActionItems;

use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\ProjectActionItemScan;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Services\EstimateAI\EmbeddingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The action tasks a project's phone calls, text messages and stakeholder
 * email add up to.
 *
 * Calls and emails already carry action items (CallTranscript::action_items
 * / EmailMessage::action_items, written by SummarizeCallTranscript and
 * SummarizeEmailMessage); texts get one model pass per batch of messages.
 * Neither calls nor threads are tied to a project, only to its client, so a
 * project's conversations are its client's: the threads on the client and
 * the calls with the client's people (their user ids and phone numbers).
 * Email, unlike calls and texts, is only read once it is placed on this
 * exact project (EmailMessage::project_id) — an email the AI could not
 * confidently place on one of the client's projects is never guessed at.
 *
 * The same task tends to come up more than once ("send Mark the window
 * invoice" on a call, "did you send the invoice?" by text two days later),
 * so a candidate that reads like an open item is folded into it (one more
 * mention, one more source) instead of becoming a second row, and a message
 * that reports a task done closes it. Matching is by embedding similarity
 * when OpenAI is configured and by word overlap otherwise.
 *
 * Runs are incremental: each run starts after the last call, the last
 * message per thread, and the last email an earlier run read
 * (ProjectActionItemScan).
 */
class ProjectActionItemExtractor
{
    /** Cosine above which two phrasings are the same task. */
    public const SIMILAR = 0.84;

    /** Cosine above which a "done" report closes an open task. */
    public const DONE_SIMILAR = 0.80;

    /** Word-overlap thresholds for the no-embeddings fallback. */
    public const SIMILAR_WORDS = 0.6;

    public const DONE_SIMILAR_WORDS = 0.5;

    /** Messages per model pass. */
    public const CHUNK = 30;

    /** Stored vector length: text-embedding-3 vectors keep their meaning when truncated. */
    public const EMBEDDING_DIMENSIONS = 256;

    private const STOPWORDS = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'about', 'before', 'after', 'will', 'should', 'into', 'onto', 'then', 'than', 'them', 'they', 'their', 'our', 'your', 'his', 'her', 'its', 'are', 'was', 'were', 'been', 'have', 'has', 'had', 'per', 'via', 'out', 'off'];

    public function __construct(protected EmbeddingService $embeddings, protected ProjectActionItemConsolidator $consolidator) {}

    /**
     * @return array{calls: int, messages: int, emails: int, created: int, merged: int, done: int, lapsed: int, consolidated: array{merged: int, done: int, dropped: int}}
     */
    public function run(Project $project, ?CarbonInterface $since = null, bool $fresh = false, bool $review = false): array
    {
        if ($fresh) {
            ProjectActionItem::where('project_id', $project->id)->delete();
            ProjectActionItemScan::where('project_id', $project->id)->delete();
        }

        $client = $project->client;
        $people = $client ? $client->users()->get() : collect();
        $userIds = $people->pluck('id');
        $phones = $people
            ->map(fn ($user) => substr((string) preg_replace('/\D/', '', (string) $user->cell_phone), -10))
            ->filter(fn ($digits) => strlen($digits) === 10)
            ->unique()
            ->values();
        $names = $people->mapWithKeys(fn ($user) => [substr((string) preg_replace('/\D/', '', (string) $user->cell_phone), -10) => (string) $user->first_name])->all();

        [$callCandidates, $callCount, $lastCallId] = $this->callCandidates($project, $userIds, $phones, $since);
        [$textCandidates, $messageCount, $threadBookmarks] = $this->textCandidates($project, $client?->id, $since, $names);
        [$emailCandidates, $emailCount, $lastEmailId] = $this->emailCandidates($project, $since);

        $candidates = collect([...$callCandidates, ...$textCandidates, ...$emailCandidates])
            ->sortBy(fn (array $candidate) => $candidate['at']->getTimestamp())
            ->values()
            ->all();

        $stats = $this->merge($project, $candidates);
        $stats['lapsed'] = $this->lapse($project);

        // A reader of the whole log decides what is the same task and what
        // already happened — worth a model pass only when something new came in.
        $stats['consolidated'] = ($stats['created'] + $stats['merged'] > 0 || $fresh || $review)
            ? $this->consolidator->consolidate($project)
            : ['merged' => 0, 'done' => 0, 'dropped' => 0];

        if ($lastCallId > 0) {
            ProjectActionItemScan::record($project->id, ProjectActionItemScan::KIND_CALLS, 0, $lastCallId);
        }
        foreach ($threadBookmarks as $threadId => $lastMessageId) {
            ProjectActionItemScan::record($project->id, ProjectActionItemScan::KIND_SMS_THREAD, $threadId, $lastMessageId);
        }
        if ($lastEmailId > 0) {
            ProjectActionItemScan::record($project->id, ProjectActionItemScan::KIND_EMAILS, 0, $lastEmailId);
        }

        return ['calls' => $callCount, 'messages' => $messageCount, 'emails' => $emailCount] + $stats;
    }

    // ── Calls ────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, int>  $userIds
     * @param  Collection<int, string>  $phones
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int} candidates, calls read, last call id
     */
    protected function callCandidates(Project $project, Collection $userIds, Collection $phones, ?CarbonInterface $since): array
    {
        $afterId = ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_CALLS);

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
            ->where('id', '>', $afterId)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->whereHas('transcript', fn ($query) => $query->whereNotNull('action_items'))
            ->orderBy('id')
            ->get();

        $candidates = [];
        foreach ($calls as $call) {
            foreach ((array) ($call->transcript?->action_items ?? []) as $item) {
                if (! is_string($item) || trim($item) === '') {
                    continue;
                }
                [$owner, $title] = $this->splitOwner($item);
                if (self::isTransient($title)) {
                    continue;
                }
                $at = Carbon::parse($call->created_at);
                $candidates[] = [
                    'title' => $title,
                    'owner' => $owner,
                    'due_on' => self::dueDateIn($title, $at),
                    'kind' => 'todo',
                    'at' => $at,
                    'source' => [
                        'kind' => 'call',
                        'id' => $call->id,
                        'thread_id' => null,
                        'at' => Carbon::parse($call->created_at)->toIso8601String(),
                        'excerpt' => Str::limit(trim($item), 180, ''),
                    ],
                ];
            }
        }

        return [$candidates, $calls->count(), (int) ($calls->max('id') ?? 0)];
    }

    /**
     * The call summaries write who is responsible three ways: "Patryk: Send
     * the invoice", "Patryk – Send the invoice" and "Patryk to send the
     * invoice". All three come back as ["Patryk", "Send the invoice"]; a bare
     * instruction keeps no owner.
     *
     * @return array{0: ?string, 1: string}
     */
    public function splitOwner(string $item): array
    {
        $item = trim($item);
        $name = '[\p{Lu}][\p{L}.\'\-]*(?: (?:and|&|or) [\p{Lu}][\p{L}.\'\-]*)?(?: \((?:or )?[^)]{1,30}\))?';

        if (preg_match('/^('.$name.'(?: [\p{L}.\'\-]+){0,2}):\s+(.{3,})$/u', $item, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        if (preg_match('/^('.$name.')\s+[–—-]\s+(.{3,})$/u', $item, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        if (preg_match('/^('.$name.') (?:to|will|should|needs? to|is to) (\p{Ll}.{2,})$/u', $item, $m)) {
            return [trim($m[1]), Str::ucfirst(trim($m[2]))];
        }

        return [null, $item];
    }

    /**
     * "Call Mark back", "circle back in an hour": done within the hour or
     * moot, never worth a row.
     */
    public static function isTransient(string $title): bool
    {
        $t = Str::lower($title);

        return (bool) preg_match('/\b(call|calls|calling|called|phone|ring|text|get|circle|follow|reach)\b[^.]{0,40}\bback\b/', $t)
            || str_contains($t, 'callback')
            || (bool) preg_match('/\breturn (the |his |her |their )?call\b/', $t)
            || (bool) preg_match('/\b(call|text) (\p{L}+ )?(later today|in a few minutes|in a minute|shortly|within (a few|two|five|ten) minutes)\b/u', $t);
    }

    /**
     * The day a relative cue in a call item points at, from the day of the
     * call in the business timezone: "tomorrow", "Monday morning", "by
     * Friday", "end of day", "this week", "next week", "in 3 days".
     */
    public static function dueDateIn(string $title, CarbonInterface $at): ?string
    {
        $day = Carbon::parse($at)->setTimezone((string) config('action_items.timezone', 'America/Chicago'))->startOfDay();
        $t = Str::lower($title);

        if (preg_match('/\b(today|tonight|end of (the )?day|eod|this (morning|afternoon|evening)|right away|immediately|asap|within the hour|later today)\b/', $t)) {
            return $day->toDateString();
        }
        if (preg_match('/\btomorrow\b/', $t)) {
            return $day->addDay()->toDateString();
        }
        if (preg_match('/\bin (\d{1,2}) days?\b/', $t, $m)) {
            return $day->addDays((int) $m[1])->toDateString();
        }
        if (preg_match('/\b(this|by (the )?end of (the |this )?|by the end of the)\s*week(end)?\b|\bthis weekend\b/', $t)) {
            return $day->next(str_contains($t, 'weekend') ? Carbon::SATURDAY : Carbon::FRIDAY)->toDateString();
        }
        if (preg_match('/\bnext week\b/', $t)) {
            return $day->next(Carbon::MONDAY)->addDays(4)->toDateString();
        }
        if (preg_match('/\b(?:on |by |this |next )?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $t, $m)) {
            $weekday = constant(Carbon::class.'::'.strtoupper($m[1]));

            return $day->next($weekday)->toDateString();
        }

        return null;
    }

    /**
     * Whether a text is worth reading for tasks: our automated schedule
     * texts and system notices read back as tasks of our own, and one- to
     * three-word replies never hold one (the SMS screen's own rule).
     */
    public static function isWorthReading(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || str_contains($text, 'View Schedule:') || str_contains($text, 'Upcoming tasks') || preg_match('#/s/[A-Za-z0-9]+#', $text)) {
            return false;
        }
        if (str_starts_with($text, '📞') || str_contains($text, 'requested a callback via the phone menu')) {
            return false;
        }

        return str_word_count($text) > 3;
    }

    /**
     * Open items whose day has passed, or that nobody mentioned for
     * config action_items.stale_days, leave the list as lapsed.
     */
    protected function lapse(Project $project): int
    {
        $today = Carbon::now()->setTimezone((string) config('action_items.timezone', 'America/Chicago'))->startOfDay();
        $grace = (int) config('action_items.overdue_grace_days', 1);
        $staleDays = (int) config('action_items.stale_days', 30);

        return ProjectActionItem::query()
            ->where('project_id', $project->id)
            ->open()
            ->where(function ($query) use ($today, $grace, $staleDays) {
                $query->where('due_on', '<', $today->copy()->subDays($grace)->toDateString())
                    ->orWhere('last_mentioned_at', '<', Carbon::now()->subDays($staleDays));
            })
            ->update(['status' => ProjectActionItem::STATUS_LAPSED]);
    }

    // ── Emails ───────────────────────────────────────────────────────────

    /**
     * Stakeholder email already carries action items from its own AI read
     * (EmailMessage::action_items, written by SummarizeEmailMessage) — same
     * format as a call's ("Patryk: Send the tile quote"), so the same
     * splitOwner/isTransient/dueDateIn apply.
     *
     * Unlike calls and texts, email is read by project id directly, not by
     * the client: an email the AI could not confidently place on one of the
     * client's projects (project_id still null after it was read) is never
     * guessed onto any of them.
     *
     * The bookmark can only advance past a contiguous run of already
     * summarized emails. SummarizeEmailMessage runs on a queue and can
     * finish out of order, so an earlier email that was still waiting on
     * its AI read the last time this ran must still be read once it is —
     * however many later emails have already been read past it.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int} candidates, emails read, bookmark id
     */
    protected function emailCandidates(Project $project, ?CarbonInterface $since): array
    {
        $afterId = ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_EMAILS);

        $emails = EmailMessage::query()
            ->where('project_id', $project->id)
            ->where('id', '>', $afterId)
            ->when($since, fn ($query) => $query->where('sent_at', '>=', $since))
            ->orderBy('id')
            ->get(['id', 'subject', 'sent_at', 'summarized_at', 'action_items']);

        $candidates = [];
        $count = 0;
        $lastId = $afterId;
        $blocked = false;

        foreach ($emails as $email) {
            if (! $email->summarized_at) {
                // Still waiting on its AI read: freeze the bookmark here so a
                // later run comes back for it once it is summarized.
                $blocked = true;

                continue;
            }

            if (! $blocked) {
                $lastId = $email->id;
            }
            $count++;

            foreach ((array) ($email->action_items ?? []) as $item) {
                if (! is_string($item) || trim($item) === '') {
                    continue;
                }
                [$owner, $title] = $this->splitOwner($item);
                if (self::isTransient($title)) {
                    continue;
                }
                $at = Carbon::parse($email->sent_at);
                $candidates[] = [
                    'title' => $title,
                    'owner' => $owner,
                    'due_on' => self::dueDateIn($title, $at),
                    'kind' => 'todo',
                    'at' => $at,
                    'source' => [
                        'kind' => 'email',
                        'id' => $email->id,
                        'thread_id' => null,
                        'at' => $at->toIso8601String(),
                        'excerpt' => Str::limit(trim($item), 180, ''),
                        'subject' => $email->subject,
                    ],
                ];
            }
        }

        return [$candidates, $count, $lastId];
    }

    // ── Texts ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $names  last-10-digit phone => first name
     * @return array{0: list<array<string, mixed>>, 1: int, 2: array<int, int>} candidates, messages read, thread id => last message id
     */
    protected function textCandidates(Project $project, ?int $clientId, ?CarbonInterface $since, array $names): array
    {
        $threads = SmsGroupThread::query()
            ->where(function ($query) use ($project, $clientId) {
                $query->where('project_id', $project->id);
                if ($clientId) {
                    $query->orWhere('client_id', $clientId);
                }
            })
            ->get();

        $candidates = [];
        $bookmarks = [];
        $count = 0;

        foreach ($threads as $thread) {
            $afterId = ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_SMS_THREAD, $thread->id);
            $messages = SmsMessage::query()
                ->where('thread_id', $thread->id)
                ->where('id', '>', $afterId)
                ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
                ->orderBy('id')
                ->get()
                ->filter(fn (SmsMessage $message) => self::isWorthReading((string) ($message->display_text ?? $message->text)))
                ->values();

            if ($messages->isEmpty()) {
                continue;
            }

            $count += $messages->count();
            $bookmarks[$thread->id] = (int) $messages->max('id');

            foreach ($messages->chunk(self::CHUNK) as $chunk) {
                $candidates = [...$candidates, ...$this->extractFromMessages($project, $thread, $chunk->values(), $names)];
            }
        }

        return [$candidates, $count, $bookmarks];
    }

    /**
     * One model pass over a run of messages: the tasks they ask for and the
     * tasks they report done, each tied to the messages it came from.
     *
     * @param  Collection<int, SmsMessage>  $messages
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    protected function extractFromMessages(Project $project, SmsGroupThread $thread, Collection $messages, array $names): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return [];
        }

        $byId = $messages->keyBy('id');
        $lines = $messages->map(function (SmsMessage $message) use ($names) {
            $digits = substr((string) preg_replace('/\D/', '', (string) $message->from_number), -10);
            $speaker = $message->direction === 'outbound' ? 'GS' : ($names[$digits] ?? 'Client');
            $text = trim((string) preg_replace('/\s+/', ' ', (string) ($message->display_text ?? $message->text)));

            return sprintf('#%d [%s] %s: %s', $message->id, Carbon::parse($message->created_at)->format('Y-m-d H:i D'), $speaker, Str::limit($text, 600, '…'));
        })->implode("\n");

        $people = collect($names)->values()->filter()->unique()->implode(', ');
        $system = <<<PROMPT
You read a text-message thread between a remodeling company ("GS", the contractor; its people sign as -PS for Patryk, -GS for Greg) and its client ({$people}) about the project at {$project->short_address}.
Each line is "#id [date time weekday] Speaker: text".

Return two kinds of items:
- "todo": a concrete task someone agreed or asked to do — a short, specific, verb-first instruction ("Send Mark the latest window invoice", "Clear the basement before Ramjack on Wednesday"). Set "owner" to the first name of the person responsible, or "GS" when it is the company side and no one in particular. Resolve relative dates (tomorrow, Monday, Wednesday at 11) into "due_date" as YYYY-MM-DD using that message's date; null when there is none.
- "done": a task that a message reports as finished, phrased as the task itself ("Thanks! Greg has check in hand" → "Give Greg the window check").

Skip pleasantries, acknowledgements, questions already answered in these messages, confirmations that leave nothing to do, and photo announcements. Do not invent tasks that are not in the text. List for each item the message ids it comes from. Return JSON only, matching the schema.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'owner', 'due_date', 'kind', 'message_ids'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'owner' => ['type' => ['string', 'null']],
                            'due_date' => ['type' => ['string', 'null']],
                            'kind' => ['type' => 'string', 'enum' => ['todo', 'done']],
                            'message_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => (string) config('services.openai.task_extraction_model', 'gpt-4.1'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "Messages:\n{$lines}"],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'thread_action_items', 'strict' => true, 'schema' => $schema],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProjectActionItemExtractor: text extraction request failed', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);

            return [];
        }

        $content = $response->successful() ? data_get($response->json(), 'choices.0.message.content') : null;
        $parsed = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($parsed) || ! is_array($parsed['items'] ?? null)) {
            Log::warning('ProjectActionItemExtractor: text extraction returned nothing usable', ['thread_id' => $thread->id, 'status' => $response->status(), 'body' => Str::limit((string) $response->body(), 300)]);

            return [];
        }

        $candidates = [];
        foreach ($parsed['items'] as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $ids = collect((array) ($item['message_ids'] ?? []))->map(fn ($id) => (int) $id)->filter(fn ($id) => $byId->has($id))->values();
            $first = $ids->isNotEmpty() ? $byId->get($ids->first()) : $messages->last();
            $dueOn = null;
            if (is_string($item['due_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['due_date'])) {
                $dueOn = $item['due_date'];
            }
            $owner = trim((string) ($item['owner'] ?? ''));
            $kind = ($item['kind'] ?? 'todo') === 'done' ? 'done' : 'todo';
            if ($kind === 'todo' && self::isTransient($title)) {
                continue;
            }

            $candidates[] = [
                'title' => $title,
                'owner' => $owner !== '' ? $owner : null,
                'due_on' => $dueOn,
                'kind' => $kind,
                'at' => Carbon::parse($first->created_at),
                'source' => [
                    'kind' => 'sms',
                    'id' => $first->id,
                    'thread_id' => $thread->id,
                    'at' => Carbon::parse($first->created_at)->toIso8601String(),
                    'excerpt' => Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) ($first->display_text ?? $first->text))), 180, '…'),
                ],
            ];
        }

        return $candidates;
    }

    // ── Grouping ─────────────────────────────────────────────────────────

    /**
     * Fold each candidate into the open item it repeats, or create it; close
     * open items that a "done" candidate reports finished.
     *
     * @param  list<array<string, mixed>>  $candidates  oldest first
     * @return array{created: int, merged: int, done: int}
     */
    protected function merge(Project $project, array $candidates): array
    {
        $stats = ['created' => 0, 'merged' => 0, 'done' => 0];
        if ($candidates === []) {
            return $stats;
        }

        /** @var Collection<int, ProjectActionItem> $open */
        $open = ProjectActionItem::query()->where('project_id', $project->id)->mergeable()->get();

        $vectors = $this->vectorsFor(array_map(fn (array $c) => $c['title'], $candidates));

        foreach ($candidates as $index => $candidate) {
            $vector = $vectors[$index] ?? null;

            if ($candidate['kind'] === 'done') {
                $match = $this->closest($open, $candidate['title'], $vector, self::DONE_SIMILAR, self::DONE_SIMILAR_WORDS);
                if ($match && $match->status !== ProjectActionItem::STATUS_DONE) {
                    $match->update([
                        'status' => ProjectActionItem::STATUS_DONE,
                        'done_evidence' => $candidate['source'],
                        'done_at' => $candidate['at'],
                    ]);
                    $open = $open->reject(fn (ProjectActionItem $item) => $item->is($match))->values();
                    $stats['done']++;
                }

                continue;
            }

            $fingerprint = ProjectActionItem::fingerprintFor($candidate['title']);
            $match = $open->first(fn (ProjectActionItem $item) => $item->fingerprint === $fingerprint)
                ?? $this->closest($open, $candidate['title'], $vector, self::SIMILAR, self::SIMILAR_WORDS);

            if ($match) {
                $match->absorb($candidate);
                $stats['merged']++;

                continue;
            }

            $item = ProjectActionItem::create([
                'project_id' => $project->id,
                'title' => $candidate['title'],
                'owner' => $candidate['owner'],
                'due_on' => $candidate['due_on'],
                'status' => ProjectActionItem::STATUS_OPEN,
                'mentions' => 1,
                'first_mentioned_at' => $candidate['at'],
                'last_mentioned_at' => $candidate['at'],
                'sources' => [$candidate['source']],
                'fingerprint' => $fingerprint,
                'embedding' => $vector,
            ]);
            $open->push($item);
            $stats['created']++;
        }

        return $stats;
    }

    /**
     * Vectors for the titles, truncated for storage; null entries when
     * embeddings are unavailable (then matching falls back to word overlap).
     *
     * @param  list<string>  $titles
     * @return array<int, list<float>|null>
     */
    protected function vectorsFor(array $titles): array
    {
        if (! $this->embeddings->enabled() || $titles === []) {
            return array_fill(0, count($titles), null);
        }

        $vectors = [];
        foreach (array_chunk($titles, 100, true) as $chunk) {
            $embedded = $this->embeddings->embed(array_values($chunk));
            $keys = array_keys($chunk);
            foreach ($keys as $i => $key) {
                $vector = $embedded[$i] ?? null;
                $vectors[$key] = is_array($vector) ? array_map(fn ($v) => round((float) $v, 5), array_slice($vector, 0, self::EMBEDDING_DIMENSIONS)) : null;
            }
        }

        return $vectors;
    }

    /**
     * The open item most like this title, when it is like it enough.
     *
     * @param  Collection<int, ProjectActionItem>  $open
     * @param  list<float>|null  $vector
     */
    protected function closest(Collection $open, string $title, ?array $vector, float $minCosine, float $minWords): ?ProjectActionItem
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($open as $item) {
            $score = ($vector !== null && is_array($item->embedding) && $item->embedding !== [])
                ? EmbeddingService::cosine($vector, $item->embedding)
                : -1.0;
            $threshold = $minCosine;

            if ($score < 0) {
                $score = self::wordOverlap($title, $item->title);
                $threshold = $minWords;
            }

            if ($score >= $threshold && $score > $bestScore) {
                $best = $item;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** Jaccard overlap of the meaningful words in two titles. */
    public static function wordOverlap(string $a, string $b): float
    {
        $wordsA = self::words($a);
        $wordsB = self::words($b);
        if ($wordsA === [] || $wordsB === []) {
            return 0.0;
        }
        $union = array_unique([...$wordsA, ...$wordsB]);

        return count(array_intersect($wordsA, $wordsB)) / count($union);
    }

    /** @return list<string> */
    protected static function words(string $text): array
    {
        $words = explode(' ', ProjectActionItem::normalizedWords($text));

        return array_values(array_unique(array_filter($words, fn (string $w) => strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))));
    }
}
