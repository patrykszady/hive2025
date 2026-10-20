<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One action task a project's calls and texts asked for, with every place it
 * came up (`sources`), how many times (`mentions`), and what became of it:
 * open, done (a later message reported it), dismissed, or turned into a
 * planner Task. Written by ProjectActionItemExtractor.
 *
 * @property array<int, array{kind: string, id: int, thread_id?: int|null, at: string, excerpt: string}> $sources
 */
class ProjectActionItem extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_TASK = 'task';

    /** Its day passed, or nobody mentioned it for weeks: out of the list without anyone acting on it. */
    public const STATUS_LAPSED = 'lapsed';

    protected $fillable = [
        'project_id',
        'title',
        'owner',
        'due_on',
        'status',
        'task_id',
        'mentions',
        'first_mentioned_at',
        'last_mentioned_at',
        'sources',
        'fingerprint',
        'embedding',
        'done_evidence',
        'done_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'first_mentioned_at' => 'datetime',
            'last_mentioned_at' => 'datetime',
            'done_at' => 'datetime',
            'sources' => 'array',
            'embedding' => 'array',
            'done_evidence' => 'array',
            'mentions' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Where a source can be read back: the thread on the Messages page, the
     * call on its Calls tab, or (an email — no dedicated tab yet) the
     * project page.
     *
     * @param  array{kind: string, id: int, thread_id?: int|null, at?: string}  $source
     */
    public static function sourceUrl(array $source, ?int $projectId = null): ?string
    {
        return match ($source['kind'] ?? null) {
            'sms' => route('sms.index', ['threadId' => $source['thread_id'] ?? null]),
            'call' => route('sms.index', ['activeTab' => 'calls', 'callId' => $source['id']]),
            'email' => $projectId ? route('projects.show', $projectId) : null,
            default => null,
        };
    }

    /** @param  array{kind: string, at?: string, subject?: ?string}  $source */
    public static function sourceLabel(array $source): string
    {
        $when = ! empty($source['at']) ? Carbon::parse($source['at'])->format('M j') : '';
        $noun = match ($source['kind'] ?? null) {
            'call' => 'call',
            'email' => 'email',
            default => 'text',
        };

        if ($noun === 'email' && ! empty($source['subject'])) {
            return trim(Str::limit((string) $source['subject'], 30).' '.$when);
        }

        return trim($noun.' '.$when);
    }

    /**
     * The line a task's notes cite its source with: "From a call Oct 19",
     * "From a text Oct 19", "From an email Oct 19" — or, for an email with a
     * subject (whose label is the subject), "From an email (Grout Oct 19)".
     *
     * @param  array{kind: string, at?: string, subject?: ?string}  $source
     */
    public static function sourcePhrase(array $source): string
    {
        if (($source['kind'] ?? null) === 'email' && ! empty($source['subject'])) {
            return 'From an email ('.self::sourceLabel($source).')';
        }

        return 'From '.self::sourceArticle($source).' '.self::sourceLabel($source);
    }

    /** The article "sourceLabel" wants in front of it: "a call", "an email". */
    public static function sourceArticle(array $source): string
    {
        return ($source['kind'] ?? null) === 'email' ? 'an' : 'a';
    }

    /** Still to be acted on: not done, not dismissed, not yet a Task. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** Open, or already a Task — the rows a new mention may still fold into. */
    public function scopeMergeable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_TASK]);
    }

    /**
     * The same words in the same order, ignoring case, punctuation and who
     * is responsible: an exact repeat is folded in without any model call.
     */
    public static function fingerprintFor(string $title): string
    {
        return sha1(self::normalizedWords($title));
    }

    public static function normalizedWords(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = (string) preg_replace('/[^a-z0-9$ ]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * One more mention of this item: another source, a later last-mention,
     * and an owner or due date it did not have yet.
     *
     * @param  array{owner: ?string, due_on: ?string, at: Carbon, source: array<string, mixed>}  $candidate
     */
    public function absorb(array $candidate): void
    {
        $sources = collect($this->sources ?? []);
        $source = $candidate['source'];
        $already = $sources->contains(fn (array $s) => ($s['kind'] ?? null) === $source['kind'] && (int) ($s['id'] ?? 0) === (int) $source['id'] && ($s['excerpt'] ?? null) === $source['excerpt']);

        if ($already) {
            return;
        }

        $this->sources = $sources->push($source)->values()->all();
        $this->mentions = count($this->sources);
        $this->first_mentioned_at = min($this->first_mentioned_at ?? $candidate['at'], $candidate['at']);
        $this->last_mentioned_at = max($this->last_mentioned_at ?? $candidate['at'], $candidate['at']);
        $this->owner = $this->owner ?: ($candidate['owner'] ?? null);
        $this->due_on = $this->due_on ?: ($candidate['due_on'] ?? null);
        $this->save();
    }
}
