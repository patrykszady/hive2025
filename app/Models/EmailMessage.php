<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Laravel\Scout\Searchable;

/**
 * One email with a project stakeholder, read from a connected mailbox (see
 * App\Services\Emails\StakeholderEmailStore). `direction` is inbound (they
 * wrote to us) or outbound (we wrote to them). `client_id` / `vendor_id` /
 * `user_id` name the stakeholder; `project_id` is the job it is about —
 * set at once when the stakeholder has one candidate project, otherwise by
 * the AI read, which also fills summary/action_items like a call transcript.
 *
 * @property array<int, array{path: string, name: string, mime: string, size: int}>|null $attachments
 */
class EmailMessage extends Model
{
    use HasFactory;
    use Searchable;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $guarded = [];

    public function searchableAs(): string
    {
        return app()->environment('local') ? 'email_messages_index_dev' : 'email_messages_index';
    }

    /**
     * What the project page's communications search reads: the subject, who
     * wrote it, the AI summary with its action items, and the body.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'belongs_to_vendor_id' => $this->belongs_to_vendor_id ? (int) $this->belongs_to_vendor_id : null,
            'project_id' => $this->project_id ? (int) $this->project_id : null,
            'client_id' => $this->client_id ? (int) $this->client_id : null,
            'subject' => (string) ($this->subject ?? ''),
            'from_name' => (string) ($this->from_name ?? ''),
            'from_email' => (string) ($this->from_email ?? ''),
            'summary' => (string) ($this->summary ?? ''),
            'action_items' => self::searchableLines([...(array) ($this->action_items ?? []), ...(array) ($this->next_steps ?? [])]),
            'body' => mb_substr((string) ($this->body_text ?: $this->snippet ?: ''), 0, 20000),
            'sent_at_unix' => optional($this->sent_at)->timestamp ?? 0,
        ];
    }

    /**
     * A list of AI-read lines (action items, next steps) as one searchable text.
     *
     * @param  array<int, mixed>  $lines
     */
    public static function searchableLines(array $lines): string
    {
        return implode("\n", array_filter(array_map(fn ($line) => is_string($line) ? trim($line) : '', $lines)));
    }

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'attachments' => 'array',
            'candidate_project_ids' => 'array',
            'action_items' => 'array',
            'topics' => 'array',
            'next_steps' => 'array',
            'sent_at' => 'datetime',
            'summarized_at' => 'datetime',
            'unplaced_dismissed_at' => 'datetime',
        ];
    }

    /**
     * Email about a project: placed on it, or from its client while the AI
     * read that places it is still pending. Once read, an email none of the
     * client's projects fits stays off all of them.
     */
    public function scopeForProject(Builder $query, Project $project): Builder
    {
        return $query->where(function (Builder $about) use ($project) {
            $about->where('project_id', $project->id);

            if ($project->client_id) {
                $about->orWhere(fn (Builder $client) => $client
                    ->whereNull('project_id')
                    ->whereNull('summarized_at')
                    ->where('client_id', $project->client_id));
            }
        });
    }

    /**
     * Read by the AI, placed on no project, and not dismissed: the emails an
     * admin still has to sort (the unplaced inbox).
     */
    public function scopeUnplaced(Builder $query): Builder
    {
        return $query->whereNull('project_id')
            ->whereNotNull('summarized_at')
            ->whereNull('unplaced_dismissed_at');
    }

    public function isUnplaced(): bool
    {
        return $this->project_id === null && $this->summarized_at !== null && $this->unplaced_dismissed_at === null;
    }

    /**
     * A day-long signed link to one attachment. Mint it only for someone
     * who may see this email: the signature is the whole gate.
     */
    public function attachmentUrl(int $index, bool $thumb = false): string
    {
        return URL::temporarySignedRoute(
            'email-messages.attachment.signed',
            now()->addDay(),
            array_filter(['emailMessage' => $this->id, 'index' => $index, 'thumb' => $thumb ? 1 : null], fn ($value) => $value !== null),
        );
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }
}
