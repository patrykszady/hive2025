<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a village's permit portal said about one permit number: the house it
 * was issued for, its status and what it is for. One row per portal and
 * number, so the expense matcher asks each portal once; a number the portal
 * did not know is kept too (found = false) and asked again after a week.
 */
class PermitLookup extends Model
{
    public const RETRY_NOT_FOUND_AFTER_DAYS = 7;

    protected $fillable = [
        'portal',
        'permit_number',
        'found',
        'address',
        'city',
        'state',
        'zip',
        'status',
        'case_type',
        'description',
        'raw',
        'looked_up_at',
    ];

    protected function casts(): array
    {
        return [
            'found' => 'boolean',
            'raw' => 'array',
            'looked_up_at' => 'datetime',
        ];
    }

    /** A miss old enough to ask the portal about again (permits get issued later). */
    public function isStaleMiss(): bool
    {
        return ! $this->found
            && ($this->looked_up_at === null || $this->looked_up_at->lt(now()->subDays(self::RETRY_NOT_FOUND_AFTER_DAYS)));
    }
}
