<?php

namespace App\Models;

use App\Scopes\ProjectStatusScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStatus extends Model
{
    use HasFactory;

    protected $table = 'project_status';

    protected $fillable = ['project_id', 'belongs_to_vendor_id', 'start_date', 'end_date', 'status_code', 'created_at', 'updated_at'];

    /**
     * Status code to title mapping
     */
    protected const STATUS_LABELS = [
        1 => 'Invited',
        2 => 'Estimate',
        3 => 'Response',
        4 => 'Prep',
        5 => 'Scheduled',
        6 => 'Active',
        7 => 'Complete',
        8 => 'Service Call',
        10 => 'Cancelled',
        11 => 'VIEW ONLY',
    ];

    /**
     * Status code to badge color mapping
     */
    protected const STATUS_COLORS = [
        1 => 'zinc',      // Invited
        2 => 'blue',      // Estimate
        3 => 'yellow',    // Awaiting Response
        4 => 'amber',     // Project Prep
        5 => 'lime',      // Scheduled
        6 => 'green',     // Active
        7 => 'teal',      // Complete
        8 => 'orange',    // Service Call
        10 => 'red',      // Cancelled
        11 => 'gray',     // VIEW ONLY
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'status_code' => 'integer',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new ProjectStatusScope);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Accessor: status title label derived from status_code
     */
    public function title(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => self::STATUS_LABELS[$attributes['status_code'] ?? 1] ?? 'Unknown'
        );
    }

    /**
     * Accessor: badge color for UI
     */
    public function badgeColor(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => self::STATUS_COLORS[$attributes['status_code'] ?? 1] ?? 'gray'
        );
    }

    /**
     * Static helper: Get status code from label
     */
    public static function getCodeForLabel(string $label): ?int
    {
        $reverse = array_flip(self::STATUS_LABELS);
        return $reverse[$label] ?? null;
    }

    /**
     * Static helper: Get label from status code
     */
    public static function getLabelForCode(int $code): string
    {
        return self::STATUS_LABELS[$code] ?? 'Unknown';
    }

    /**
     * Get the duration in days from start_date to today
     */
    // protected function duration(): Attribute
    // {
    //     return Attribute::make(
    //         get: function ($value, array $attributes) {
    //             if (!isset($attributes['start_date'])) {
    //                 return 0;
    //             }
                
    //             $startDate = Carbon::parse($attributes['start_date']);
    //             $today = Carbon::today();
                
    //             return $today->diffInDays($startDate);
    //         }
    //     );
    // }
}
