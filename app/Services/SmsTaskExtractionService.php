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
You extract a single schedulable job/task from a text message exchanged on a construction-management platform.

The message was sent on {$sentDate} ({$sentWeekday}). Resolve every relative date against that sent date and return an absolute calendar date (YYYY-MM-DD):
- A bare weekday like "Tuesday" means the soonest Tuesday strictly after the sent date (never the same day it was sent).
- "today", "this morning/afternoon/evening/tonight", or a time-only reference with NO day mentioned (e.g. "will be there around 8am", "see you at 2") means the sent date itself (the same calendar day the message was sent).
- "tomorrow" is the day after the sent date; "next week" advances by seven days from the equivalent weekday.

Convert clock ranges to 24-hour times. "7-8am" -> start_time 07:00, end_time 08:00. "between 2 and 4pm" -> 14:00 / 16:00. "around 8am" -> start_time 08:00 with end_time empty. If only one time is mentioned, set start_time and leave end_time empty. If no time is mentioned, leave both empty.

title: a short, capitalized task name describing the work (e.g. "Tile/Grout Repair", "Roofing"). Do NOT include the room, time, person's name, or pleasantries.
type: one of Task, Milestone, Meet, Reminder. Use "Meet" only for meetings/walkthroughs/consultations; otherwise default to "Task".
project_hint: any room, area, or project name referenced (e.g. "hall bath", "kitchen", "roof") or empty if none.
assignee_names: ALWAYS capture the first name of any specific person the message names as doing the work, stopping by, picking up, delivering, or being onsite (e.g. "Greg is stopping by" -> ["Greg"]; "Jerry will be there" -> ["Jerry"]; "pickup person is Greg Szady" -> ["Greg"]). Use the first name exactly as written. Ignore people who are only being greeted or thanked, or the sender's own signature line (e.g. "-PS", "-GS"). Empty array only when no person is named as doing something.
checklist: capture small actionable to-dos that are NOT their own scheduled job (e.g. "let's adjust your ring cameras" -> ["Adjust Ring cameras"]). Do not repeat the title verbatim. Empty array when there are none.
additional_tasks: when the message describes MORE THAN ONE distinct crew/job/arrival, return the first as the main task above and put each OTHER one here. Each has its own title, type, date, start_time, end_time, project_hint, assignee_names (e.g. "we will be there 9am with materials, drywall guys at 10am" -> main task is the 9am materials delivery, additional_tasks has the 10am drywall crew). Empty array when there is only one job.
has_task: true when the message describes concrete work happening or being scheduled (including a crew/worker arriving to do a job); false for chit-chat, confirmations with no work, or messages with no actionable job.

Worked example — if the message "Greg is stopping by onsite tomorrow. (Let's adjust your ring cameras on Monday)\n-PS" were sent on 2025-03-01 (Saturday), the correct output is:
{"has_task": true, "title": "Onsite Visit", "type": "Task", "date": "2025-03-02", "start_time": null, "end_time": null, "project_hint": "", "assignee_names": ["Greg"], "checklist": ["Adjust Ring cameras"], "additional_tasks": []}
This shows: the named worker ("Greg") goes in assignee_names, the parenthetical side task becomes a checklist item ("Adjust Ring cameras"), the "-PS" signature is ignored, and "tomorrow" resolves to the day after the sent date.

Worked example — if the message "Hi Zora, we will be there around 9am with materials, drywall guys will be there around 10am.\nThank you" were sent on 2025-03-01, the correct output is two tasks:
{"has_task": true, "title": "Materials Delivery", "type": "Task", "date": "2025-03-01", "start_time": "09:00", "end_time": null, "project_hint": "", "assignee_names": [], "checklist": [], "additional_tasks": [{"title": "Drywall Install", "type": "Task", "date": "2025-03-01", "start_time": "10:00", "end_time": null, "project_hint": "", "assignee_names": []}]}

Return JSON only, matching the provided schema. Do not invent details that are not in the message.
PROMPT;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['has_task', 'title', 'type', 'date', 'start_time', 'end_time', 'project_hint', 'assignee_names', 'checklist', 'additional_tasks'],
            'properties' => [
                'has_task' => ['type' => 'boolean'],
                'title' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => ['Task', 'Milestone', 'Meet', 'Reminder']],
                'date' => ['type' => ['string', 'null']],
                'start_time' => ['type' => ['string', 'null']],
                'end_time' => ['type' => ['string', 'null']],
                'project_hint' => ['type' => ['string', 'null']],
                'assignee_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                'checklist' => ['type' => 'array', 'items' => ['type' => 'string']],
                'additional_tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'type', 'date', 'start_time', 'end_time', 'project_hint', 'assignee_names'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['Task', 'Milestone', 'Meet', 'Reminder']],
                            'date' => ['type' => ['string', 'null']],
                            'start_time' => ['type' => ['string', 'null']],
                            'end_time' => ['type' => ['string', 'null']],
                            'project_hint' => ['type' => ['string', 'null']],
                            'assignee_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];

        $model = config('services.openai.task_extraction_model', 'gpt-4o');

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
