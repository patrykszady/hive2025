<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallTranscript extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_TRANSCRIBING = 'transcribing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'call_log_id',
        'telnyx_recording_id',
        'telnyx_transcription_id',
        'engine',
        'language',
        'text',
        'segments',
        'status',
        'failure_reason',
        'summary_model',
        'summary',
        'action_items',
        'topics',
        'next_steps',
        'sentiment',
        'caller_intent',
        'summarized_at',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'action_items' => 'array',
            'topics' => 'array',
            'next_steps' => 'array',
            'summarized_at' => 'datetime',
        ];
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }
}
