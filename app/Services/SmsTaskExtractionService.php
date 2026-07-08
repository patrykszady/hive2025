<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsTaskExtractionService
{
    /**
     * Extract a schedulable Hive task from a single SMS message.
     *
     * Returns null when AI is unavailable or no task could be parsed. On
     * success returns a normalized array describing the proposed task. All
     * relative dates ("Tuesday", "next week") are resolved against the date
     * the message was sent so that, e.g., "would Tuesday work" maps to the
     * upcoming Tuesday after the message.
     *
     * @return array{
     *     has_task: bool,
     *     title: string,
     *     type: string,
     *     date: ?string,
     *     start_time: ?string,
     *     end_time: ?string,
     *     project_hint: ?string,
     *     assignee_names: array<int, string>,
     *     checklist: array<int, string>
     * }|null
     */
    public function extract(string $message, CarbonInterface $sentAt): ?array
    {
        $message = trim($message);

        if ($message === '') {
            return null;
        }

        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            Log::warning('SmsTaskExtractionService: OPENAI_API_KEY not configured');

            return null;
        }

        $sentDate = $sentAt->toDateString();
        $sentWeekday = $sentAt->format('l');

        $system = <<<PROMPT
You extract one or more schedulable jobs/tasks from a text message exchanged on a construction-management platform. A single message often contains SEVERAL requests — capture EVERY one, not just the first.

The message was sent on {$sentDate} ({$sentWeekday}). Resolve every relative date against that sent date and return an absolute calendar date (YYYY-MM-DD):
- A bare weekday like "Tuesday" means the soonest Tuesday strictly after the sent date (never the same day it was sent).
- "today", "this morning/afternoon/evening/tonight", or a time-only reference with NO day mentioned (e.g. "will be there around 8am", "see you at 2") means the sent date itself (the same calendar day the message was sent).
- "tomorrow" is the day after the sent date; "next week" advances by seven days from the equivalent weekday.

Convert clock ranges to 24-hour times. "7-8am" -> start_time 07:00, end_time 08:00. "between 2 and 4pm" -> 14:00 / 16:00. "around 8am" -> start_time 08:00 with end_time empty. If only one time is mentioned, set start_time and leave end_time empty. If no time is mentioned, leave both empty.

title: a VERY short task name — 2 to 4 words max, a concise noun phrase (e.g. "Grout Repair", "Cabinet Trim", "Outlet Repair", "Roofing"). Name the thing to fix, NOT the symptom or impact. Never restate the sentence. Do NOT include the room, time, person's name, the word "impacting/affecting", or pleasantries. "The new trim on our cabinet is impacting the microwave drawer" -> title "Cabinet Trim" (NOT "Correct Cabinet Trim Impacting Microwave Drawer").
type: one of Task, Milestone, Meet, Reminder. Use "Meet" for meetings, walkthroughs, consultations, or when the sender wants to talk/discuss something ("to discuss", "your thoughts on", "can we talk about") -> Meet; otherwise default to "Task".
project_hint: any room, area, or project name referenced (e.g. "hall bath", "kitchen", "roof") or empty if none.
assignee_names: ALWAYS capture the first name of any specific person the message names as doing the work, stopping by, picking up, delivering, or being onsite (e.g. "Greg is stopping by" -> ["Greg"]; "Jerry will be there" -> ["Jerry"]; "pickup person is Greg Szady" -> ["Greg"]). Use the first name exactly as written. Ignore people who are only being greeted or thanked, or the sender's own signature line (e.g. "-PS", "-GS"). Empty array only when no person is named as doing something.
checklist: capture small actionable to-dos as SHORT imperative phrases of 2 to 5 words each (e.g. "let's adjust your ring cameras" -> ["Adjust Ring cameras"]; "the trim is impacting the microwave drawer" -> ["Adjust cabinet trim"]). Never copy a full sentence — the original message is kept as the task notes. Do not repeat the title verbatim. Empty array when there are none.
additional_tasks: when the message describes MORE THAN ONE distinct request/job/issue/crew/arrival, return the first as the main task above and put each OTHER one here. Each has its own title, type, date, start_time, end_time, project_hint, assignee_names, notes (e.g. "we will be there 9am with materials, drywall guys at 10am" -> main task is the 9am materials delivery, additional_tasks has the 10am drywall crew). A numbered or bulleted list ("1)", "2)", "-", "a.") of requests or punch-list items ALWAYS means one task per list item. Empty array when there is only one job.
notes: for THIS task only, the exact portion of the message that describes it — the specific request/issue text in the client's own words, with any list number/bullet removed. EXCLUDE greetings ("Hi ..."), "hope all is well", sign-offs ("Thanks. Tom"), and the text of OTHER tasks. For a single-task message use the whole relevant sentence(s). Example: for item "1) grout repair at counter/backsplash interface" the notes are "grout repair at counter/backsplash interface".
has_task: true whenever the message asks to schedule, perform, fix, repair, replace, inspect, review, or discuss any work — even when NO date or time is given (a request like "please add me to your schedule for the following..." or a punch list of issues still counts). false only for pure chit-chat, greetings, thanks, or confirmations with no actionable work.

Worked example — if the message "Greg is stopping by onsite tomorrow. (Let's adjust your ring cameras on Monday)\n-PS" were sent on 2025-03-01 (Saturday), the correct output is:
{"has_task": true, "title": "Onsite Visit", "type": "Task", "date": "2025-03-02", "start_time": null, "end_time": null, "project_hint": "", "notes": "Greg is stopping by onsite tomorrow.", "assignee_names": ["Greg"], "checklist": ["Adjust Ring cameras"], "additional_tasks": []}
This shows: the named worker ("Greg") goes in assignee_names, the parenthetical side task becomes a checklist item ("Adjust Ring cameras"), the "-PS" signature is ignored, and "tomorrow" resolves to the day after the sent date.

Worked example — if the message "Hi Zora, we will be there around 9am with materials, drywall guys will be there around 10am.\nThank you" were sent on 2025-03-01, the correct output is two tasks:
{"has_task": true, "title": "Materials Delivery", "type": "Task", "date": "2025-03-01", "start_time": "09:00", "end_time": null, "project_hint": "", "notes": "we will be there around 9am with materials", "assignee_names": [], "checklist": [], "additional_tasks": [{"title": "Drywall Install", "type": "Task", "date": "2025-03-01", "start_time": "10:00", "end_time": null, "project_hint": "", "notes": "drywall guys will be there around 10am", "assignee_names": []}]}

Worked example — a punch list with no dates. If the message "Hi Patryk and Greg. Hope all is well. Please add me to your schedule for the following:\n1) grout repair at counter/backsplash interface\n2) outlet in pantry still shows open ground (initially flagged by the inspector)\n3) to discuss your thoughts on the cooktop which goes off and relights when drawers are opened.\nThanks. Tom" were sent on 2025-03-01, there are THREE tasks (has_task true, no dates):
{"has_task": true, "title": "Grout Repair", "type": "Task", "date": null, "start_time": null, "end_time": null, "project_hint": "counter/backsplash", "notes": "grout repair at counter/backsplash interface", "assignee_names": [], "checklist": [], "additional_tasks": [{"title": "Repair Pantry Outlet (Open Ground)", "type": "Task", "date": null, "start_time": null, "end_time": null, "project_hint": "pantry", "notes": "outlet in pantry still shows open ground (initially flagged by the inspector)", "assignee_names": []}, {"title": "Discuss Cooktop Issue", "type": "Meet", "date": null, "start_time": null, "end_time": null, "project_hint": "kitchen", "notes": "to discuss your thoughts on the cooktop which goes off and relights when drawers are opened. Basically just wondering if you've run into this a lot on your other projects.", "assignee_names": []}]}
This shows: each numbered item becomes its own task, its notes hold ONLY that item's text (number removed, no greetings/sign-off), a "to discuss" item is type Meet, and missing dates are null while has_task stays true.

Return JSON only, matching the provided schema. Do not invent details that are not in the message.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['has_task', 'title', 'type', 'date', 'start_time', 'end_time', 'project_hint', 'notes', 'assignee_names', 'checklist', 'additional_tasks'],
            'properties' => [
                'has_task' => ['type' => 'boolean'],
                'title' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => ['Task', 'Milestone', 'Meet', 'Reminder']],
                'date' => ['type' => ['string', 'null']],
                'start_time' => ['type' => ['string', 'null']],
                'end_time' => ['type' => ['string', 'null']],
                'project_hint' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'assignee_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                'checklist' => ['type' => 'array', 'items' => ['type' => 'string']],
                'additional_tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'type', 'date', 'start_time', 'end_time', 'project_hint', 'notes', 'assignee_names'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['Task', 'Milestone', 'Meet', 'Reminder']],
                            'date' => ['type' => ['string', 'null']],
                            'start_time' => ['type' => ['string', 'null']],
                            'end_time' => ['type' => ['string', 'null']],
                            'project_hint' => ['type' => ['string', 'null']],
                            'notes' => ['type' => ['string', 'null']],
                            'assignee_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];

        $model = config('services.openai.task_extraction_model', 'gpt-4.1');

        $response = Http::withToken($apiKey)
            ->timeout(45)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Message:\n{$message}"],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'sms_task',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('SmsTaskExtractionService: OpenAI request failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        $parsed = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($parsed)) {
            Log::error('SmsTaskExtractionService: invalid JSON from OpenAI', [
                'content' => $content,
            ]);

            return null;
        }

        return $this->normalize($parsed, $message, $sentAt);
    }

    /**
     * Coerce the raw model output into the documented shape.
     *
    * @param  array<string, mixed>  $parsed
     * @return array{
     *     has_task: bool,
     *     title: string,
     *     type: string,
     *     date: ?string,
     *     start_time: ?string,
     *     end_time: ?string,
     *     project_hint: ?string,
     *     assignee_names: array<int, string>,
     *     checklist: array<int, string>,
     *     additional_tasks: array<int, array<string, mixed>>
     * }
     */
    protected function normalize(array $parsed, string $message, CarbonInterface $sentAt): array
    {
        $type = is_string($parsed['type'] ?? null) ? $parsed['type'] : 'Task';

        if (! in_array($type, ['Task', 'Milestone', 'Meet', 'Reminder'], true)) {
            $type = 'Task';
        }

        $date = $this->cleanString($parsed['date'] ?? null);
        $startTime = $this->cleanString($parsed['start_time'] ?? null);
        $endTime = $this->cleanString($parsed['end_time'] ?? null);

        if ($date && $startTime && $this->shouldRollTimeOnlyTaskToTomorrow($message, $date, $startTime, $sentAt)) {
            $date = $sentAt->copy()->addDay()->toDateString();
        }

        return [
            'has_task' => (bool) ($parsed['has_task'] ?? false),
            'title' => trim((string) ($parsed['title'] ?? '')),
            'type' => $type,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'project_hint' => $this->cleanString($parsed['project_hint'] ?? null),
            'notes' => $this->cleanString($parsed['notes'] ?? null),
            'assignee_names' => $this->cleanStringList($parsed['assignee_names'] ?? null),
            'checklist' => $this->cleanStringList($parsed['checklist'] ?? null),
            'additional_tasks' => $this->normalizeAdditionalTasks($parsed['additional_tasks'] ?? null, $message, $sentAt),
        ];
    }

    /**
     * Coerce secondary tasks (extra crews/jobs in the same message) into clean rows.
     *
     * @return array<int, array{title: string, type: string, date: ?string, start_time: ?string, end_time: ?string, project_hint: ?string, assignee_names: array<int, string>}>
     */
    protected function normalizeAdditionalTasks(mixed $value, string $message, CarbonInterface $sentAt): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tasks = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $type = is_string($row['type'] ?? null) && in_array($row['type'], ['Task', 'Milestone', 'Meet', 'Reminder'], true)
                ? $row['type']
                : 'Task';

            $date = $this->cleanString($row['date'] ?? null);
            $startTime = $this->cleanString($row['start_time'] ?? null);

            if ($date && $startTime && $this->shouldRollTimeOnlyTaskToTomorrow($message, $date, $startTime, $sentAt)) {
                $date = $sentAt->copy()->addDay()->toDateString();
            }

            $tasks[] = [
                'title' => $title,
                'type' => $type,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $this->cleanString($row['end_time'] ?? null),
                'project_hint' => $this->cleanString($row['project_hint'] ?? null),
                'notes' => $this->cleanString($row['notes'] ?? null),
                'assignee_names' => $this->cleanStringList($row['assignee_names'] ?? null),
            ];
        }

        return $tasks;
    }

    protected function shouldRollTimeOnlyTaskToTomorrow(string $message, string $date, string $startTime, CarbonInterface $sentAt): bool
    {
        if ($this->hasExplicitDateCue($message)) {
            return false;
        }

        if ($date !== $sentAt->toDateString()) {
            return false;
        }

        try {
            $taskTime = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$startTime}", $sentAt->getTimezone());
        } catch (\Throwable) {
            return false;
        }

        return $taskTime->lessThanOrEqualTo($sentAt);
    }

    protected function hasExplicitDateCue(string $message): bool
    {
        return (bool) preg_match('/\b(today|tomorrow|tonight|this\s+(morning|afternoon|evening)|next\s+week|next\s+month|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|\d{1,2}\/\d{1,2})\b/i', $message);
    }

    /**
     * Normalize a model-provided list into a clean array of non-empty strings.
     *
     * @return array<int, string>
     */
    protected function cleanStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_map(
            fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value
        );

        return array_values(array_filter($items, fn (string $item): bool => $item !== ''));
    }

    protected function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
