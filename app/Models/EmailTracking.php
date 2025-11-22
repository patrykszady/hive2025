<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTracking extends Model
{
    protected $table = 'email_tracking';

    protected $fillable = [
        'project_id',
        'nylas_message_id',
        'nylas_thread_id',
        'event_type',
        'recipient_email',
        'link_url',
        'ip_address',
        'user_agent',
        'metadata',
        'event_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'event_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
