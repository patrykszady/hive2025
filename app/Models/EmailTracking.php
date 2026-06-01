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

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

