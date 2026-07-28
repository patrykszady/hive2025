<?php

namespace App\Models;

use App\Scopes\EmailTrackingScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTracking extends Model
{
    protected $table = 'email_tracking';

    protected $fillable = [
        'belongs_to_vendor_id',
        'project_id',
        'lead_id',
        'message_id',
        'thread_id',
        'email_template_name',
        'event_type',
        'recipient_emails',
        'link_url',
        'ip_address',
        'user_agent',
        'metadata',
        'event_at',
    ];

    protected $casts = [
        'recipient_emails' => 'array',
        'metadata' => 'array',
        'event_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new EmailTrackingScope);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Client-facing sends only — internal/vendor-facing templates never show on
     * a project, client or lead card. ONE definition: the card's query, its
     * skeleton row count and the page-level guards all call this, so a page can
     * never paint a card that the loaded query then removes.
     */
    public function scopeClientFacing($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('email_template_name')
                ->orWhereNotIn('email_template_name', ['Vendor Payment', 'Lien Waiver Signing Request', 'Draw Package']);
        });
    }

    /**
     * Everything a project page should see: the project's own emails plus
     * lead replies for the project's client (the consult email precedes the
     * project). Single source for the page guard and the tracking card.
     */
    public function scopeForProjectAndItsLeads($query, int $projectId)
    {
        $clientId = Project::withoutGlobalScopes()->whereKey($projectId)->value('client_id');
        $leadIds = Lead::idsForClient($clientId ? (int) $clientId : null);

        return $query->where(fn ($q) => $q
            ->where('project_id', $projectId)
            ->orWhereIn('lead_id', $leadIds));
    }

    /** Everything a client page should see: project emails + lead replies. */
    public function scopeForClientAndItsLeads($query, int $clientId)
    {
        $leadIds = Lead::idsForClient($clientId);

        return $query->where(fn ($q) => $q
            ->whereHas('project', fn ($p) => $p->where('client_id', $clientId))
            ->orWhereIn('lead_id', $leadIds));
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

