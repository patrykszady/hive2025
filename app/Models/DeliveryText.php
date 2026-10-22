<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's delivery text, read once (App\Services\Deliveries\
 * DeliveryTextIntake): Studio 41's "Delivery is scheduled for Wed 10/28
 * 12-3pm at 949 SOUTH ELM COURT, PALATINE … order# S2472002" became a task
 * on the project at that address.
 */
class DeliveryText extends Model
{
    /** @use HasFactory<\Database\Factories\DeliveryTextFactory> */
    use HasFactory;

    public const OUTCOME_SCHEDULED = 'scheduled';

    public const OUTCOME_RESCHEDULED = 'rescheduled';

    public const OUTCOME_ALREADY_SCHEDULED = 'already_scheduled';

    /** A delivery, but no open project at its address. */
    public const OUTCOME_UNPLACED = 'unplaced';

    public const OUTCOME_NOT_DELIVERY = 'not_delivery';

    protected $fillable = [
        'sms_message_id',
        'belongs_to_vendor_id',
        'outcome',
        'project_id',
        'task_id',
        'supplier',
        'order_number',
        'delivery_date',
        'extracted',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'extracted' => 'array',
            'delivery_date' => 'date',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SmsMessage::class, 'sms_message_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
