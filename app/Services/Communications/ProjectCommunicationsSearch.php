<?php

namespace App\Services\Communications;

use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Models\MeetingRecording;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\SmsMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Search everything said on a project — calls (summary, transcript, action
 * items), texts, emails, in-person meetings and the planner's action items —
 * for a word like "Allowances".
 *
 * Meilisearch finds the matching ids per index; the database then decides
 * which of them belong to this project, through the same queries the
 * timeline uses (ProjectCommunicationsTimeline), so a result is always a row
 * the card could have shown. When an index is unavailable that kind falls
 * back to a plain LIKE.
 *
 * Callers authorize the project first, as with the timeline.
 */
class ProjectCommunicationsSearch
{
    /** The planner's action items: a kind only the search shows. */
    public const ACTION_ITEM = 'action_item';

    public const KINDS = [...ProjectCommunicationsTimeline::ALL_KINDS, self::ACTION_ITEM];

    /** Rows shown for one search. */
    public const LIMIT = 50;

    /** Index hits read per kind before the database narrows them to the project. */
    protected const HITS = 1000;

    /** Characters kept on each side of the matched word in a row's excerpt. */
    protected const EXCERPT_RADIUS = 70;

    public function __construct(protected ProjectCommunicationsTimeline $timeline) {}

    /**
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    public function search(Project $project, string $term, array $kinds = self::KINDS, int $limit = self::LIMIT): array
    {
        $term = trim($term);
        $kinds = array_values(array_intersect($kinds, self::KINDS)) ?: self::KINDS;

        if ($term === '') {
            return [];
        }

        $constrain = [];
        foreach (array_intersect($kinds, ProjectCommunicationsTimeline::ALL_KINDS) as $kind) {
            $constrain[$kind] = $this->constraintFor($kind, $project, $term);
        }

        $items = collect();

        if ($constrain !== []) {
            $items = $items->concat($this->timeline->forProject($project, null, $limit, array_keys($constrain), $constrain)['items']);
        }
        if (in_array(self::ACTION_ITEM, $kinds, true)) {
            $items = $items->concat($this->actionItems($project, $term, $limit));
        }

        $page = $items->sortByDesc(fn (array $item) => $item['at'])->take($limit)->values();

        return $this->withExcerpts($page, $term)->all();
    }

    /**
     * What narrows one kind's timeline query to the rows matching the term.
     */
    protected function constraintFor(string $kind, Project $project, string $term): \Closure
    {
        $ids = $this->indexHits(fn (): Collection => match ($kind) {
            'call' => CallLog::search($term)->take(self::HITS)->keys(),
            'sms' => $this->textHits($project, $term),
            'email' => EmailMessage::search($term)->where('belongs_to_vendor_id', $project->belongs_to_vendor_id)->take(self::HITS)->keys(),
            'meeting' => MeetingRecording::search($term)->where('project_id', $project->id)->take(self::HITS)->keys(),
        });

        if ($ids !== null) {
            return fn (Builder $query) => $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return match ($kind) {
            'call' => fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('notes', 'like', $like)
                ->orWhereHas('transcript', fn (Builder $transcript) => $transcript->where('summary', 'like', $like)->orWhere('text', 'like', $like))),
            'sms' => fn (Builder $query) => $query->where('text', 'like', $like),
            'email' => fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('subject', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('body_text', 'like', $like)),
            'meeting' => fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('summary', 'like', $like)->orWhere('transcript', 'like', $like)),
        };
    }

    /**
     * Texts are searched inside the project's own threads, so a common word
     * never runs out of hits on other clients' conversations.
     *
     * @return Collection<int, mixed>
     */
    protected function textHits(Project $project, string $term): Collection
    {
        $threadIds = $this->timeline->threadIds($project);

        return $threadIds->isEmpty()
            ? collect()
            : SmsMessage::search($term)->whereIn('thread_id', $threadIds->all())->take(self::HITS)->keys();
    }

    /**
     * The ids an index matched, or null when the index could not answer
     * (down, or not built yet) and the caller should fall back to LIKE.
     *
     * @param  \Closure(): Collection<int, mixed>  $search
     * @return list<int>|null
     */
    protected function indexHits(\Closure $search): ?array
    {
        try {
            return $search()->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The project's planner action items matching the term, whatever became
     * of them (open, done, on the planner, dismissed).
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function actionItems(Project $project, string $term, int $limit): Collection
    {
        $ids = $this->indexHits(fn (): Collection => ProjectActionItem::search($term)->where('project_id', $project->id)->take(self::HITS)->keys());

        return ProjectActionItem::query()
            ->where('project_id', $project->id)
            ->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids))
            ->when($ids === null, fn (Builder $query) => $query->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderByDesc('last_mentioned_at')
            ->limit($limit)
            ->get()
            ->map(function (ProjectActionItem $item) use ($project): array {
                $source = collect($item->sources ?? [])->sortByDesc(fn (array $source) => $source['at'] ?? '')->first();
                $status = match ($item->status) {
                    ProjectActionItem::STATUS_OPEN => 'Open',
                    ProjectActionItem::STATUS_DONE => 'Done',
                    ProjectActionItem::STATUS_TASK => 'On the planner',
                    ProjectActionItem::STATUS_DISMISSED => 'Dismissed',
                    ProjectActionItem::STATUS_LAPSED => 'Lapsed',
                    default => Str::headline((string) $item->status),
                };

                return [
                    'kind' => self::ACTION_ITEM,
                    'id' => $item->id,
                    'at' => Carbon::parse($item->last_mentioned_at ?? $item->created_at)->toIso8601String(),
                    'direction' => 'outbound',
                    'who' => (string) $item->title,
                    'title' => 'Action item · '.$status.($item->owner ? ' · '.$item->owner : ''),
                    'preview' => $source ? ProjectActionItem::sourcePhrase($source) : '',
                    'status' => $item->status,
                    'source_kind' => $source['kind'] ?? null,
                    'source_id' => isset($source['id']) ? (int) $source['id'] : null,
                    'url' => $source && in_array($source['kind'] ?? null, ['sms', 'call'], true)
                        ? ProjectActionItem::sourceUrl($source, $project->id)
                        : null,
                ];
            });
    }

    /**
     * Give each row the words around its match (`match`), read from the full
     * text — a call's row shows its summary, but the word may only be in the
     * transcript.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    protected function withExcerpts(Collection $items, string $term): Collection
    {
        $ids = fn (string $kind) => $items->where('kind', $kind)->pluck('id')->all();

        $calls = CallLog::query()->with('transcript')->whereIn('id', $ids('call'))->get()->keyBy('id');
        $texts = SmsMessage::query()->whereIn('id', $ids('sms'))->get()->keyBy('id');
        $emails = EmailMessage::query()->whereIn('id', $ids('email'))->get()->keyBy('id');
        $meetings = MeetingRecording::query()->whereIn('id', $ids('meeting'))->get()->keyBy('id');
        $actionItems = ProjectActionItem::query()->whereIn('id', $ids(self::ACTION_ITEM))->get()->keyBy('id');

        return $items->map(function (array $item) use ($term, $calls, $texts, $emails, $meetings, $actionItems): array {
            $texts_ = match ($item['kind']) {
                'call' => ($call = $calls->get($item['id'])) ? [
                    $call->transcript?->summary,
                    ...(array) ($call->transcript?->action_items ?? []),
                    ...(array) ($call->transcript?->next_steps ?? []),
                    $call->notes,
                    $call->transcript?->text,
                ] : [],
                'sms' => ($text = $texts->get($item['id'])) ? [$text->display_text ?? $text->text] : [],
                'email' => ($email = $emails->get($item['id'])) ? [
                    $email->summary,
                    ...(array) ($email->action_items ?? []),
                    ...(array) ($email->next_steps ?? []),
                    $email->body_text,
                ] : [],
                'meeting' => ($meeting = $meetings->get($item['id'])) ? [
                    $meeting->summary,
                    ...(array) ($meeting->action_items ?? []),
                    ...(array) ($meeting->next_steps ?? []),
                    $meeting->transcript,
                ] : [],
                self::ACTION_ITEM => ($actionItem = $actionItems->get($item['id']))
                    ? array_map(fn ($source) => $source['excerpt'] ?? null, (array) ($actionItem->sources ?? []))
                    : [],
                default => [],
            };

            $item['match'] = $this->excerpt(array_filter($texts_, 'is_string'), $term);

            return $item;
        });
    }

    /**
     * The first place any searched word appears, with a little either side.
     *
     * @param  array<int, string>  $texts
     */
    protected function excerpt(array $texts, string $term): ?string
    {
        foreach ($texts as $text) {
            $text = trim((string) preg_replace('/\s+/', ' ', $text));

            foreach (self::words($term) as $word) {
                $at = mb_stripos($text, $word);

                if ($at === false) {
                    continue;
                }

                $start = max(0, $at - self::EXCERPT_RADIUS);
                $length = mb_strlen($word) + ($at - $start) + self::EXCERPT_RADIUS;

                return ($start > 0 ? '…' : '').trim(mb_substr($text, $start, $length)).($start + $length < mb_strlen($text) ? '…' : '');
            }
        }

        return null;
    }

    /**
     * The searched words worth marking, longest first.
     *
     * @return list<string>
     */
    public static function words(string $term): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($term)) ?: [], fn (string $word) => mb_strlen($word) >= 2));
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $words;
    }
}
