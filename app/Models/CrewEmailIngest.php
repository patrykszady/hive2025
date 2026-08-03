<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per message seen in the crew@gs.construction shared mailbox.
 *
 * @see database/migrations/2026_08_02_120000_create_crew_email_ingests_table.php
 */
class CrewEmailIngest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_LEAD = 'lead';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'recipients' => 'array',
        'message_at' => 'datetime',
        'is_lead' => 'boolean',
        'confidence' => 'float',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
