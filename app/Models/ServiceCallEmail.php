<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client email the service-call intake judged (see
 * App\Services\ServiceCalls\ServiceCallEmailIntake). `opened` rows carry the
 * project moved to Service Call and the task created for the request.
 */
class ServiceCallEmail extends Model
{
    use HasFactory;

    public const OUTCOME_OPENED = 'opened';

    public const OUTCOME_NOT_SERVICE = 'not_service';

    public const OUTCOME_FOLLOW_UP = 'follow_up';

    public const OUTCOME_ALREADY_HANDLED = 'already_handled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'verdict' => 'array',
            'confidence' => 'float',
            'received_at' => 'datetime',
            'emailed_at' => 'datetime',
            'texted_at' => 'datetime',
        ];
    }

    public function isOpened(): bool
    {
        return $this->outcome === self::OUTCOME_OPENED;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withoutGlobalScopes();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withoutGlobalScopes();
    }
}
