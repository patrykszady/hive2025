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
        // Insertion order IS lifecycle order — selectableStatuses() (and so
        // every dropdown) renders in this sequence, not by code number.
        1 => 'Invited',
        9 => 'Consult',
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
     * 
     * Colors chosen for visual distinction in dropdowns:
     * - Active (green): clearly "go/current"
     * - Complete (sky): clearly "done/cold" - distinct from green
     * - Cancelled/VIEW ONLY (red/gray): clearly "don't use"
     */
    protected const STATUS_COLORS = [
        1 => 'zinc',      // Invited
        2 => 'blue',      // Estimate
        3 => 'yellow',    // Awaiting Response
        4 => 'amber',     // Project Prep
        5 => 'lime',      // Scheduled
        6 => 'green',     // Active
        7 => 'sky',       // Complete - changed from teal for better distinction from green
        8 => 'orange',    // Service Call
        9 => 'purple',    // Consult - won lead waiting on the meeting
        10 => 'red',      // Cancelled
        11 => 'gray',     // VIEW ONLY
    ];

    /**
     * Badge color to RGB mappings for status indicator
     */
    protected const COLOR_RGB_MAP = [
        'zinc' => ['ring' => 'rgb(244, 244, 245)', 'dot' => 'rgb(113, 113, 122)'],
        'blue' => ['ring' => 'rgb(239, 246, 255)', 'dot' => 'rgb(59, 130, 246)'],
        'yellow' => ['ring' => 'rgb(254, 252, 232)', 'dot' => 'rgb(234, 179, 8)'],
        'amber' => ['ring' => 'rgb(255, 251, 235)', 'dot' => 'rgb(245, 158, 11)'],
        'lime' => ['ring' => 'rgb(247, 254, 231)', 'dot' => 'rgb(132, 204, 22)'],
        'green' => ['ring' => 'rgb(240, 253, 244)', 'dot' => 'rgb(34, 197, 94)'],
        'teal' => ['ring' => 'rgb(240, 253, 250)', 'dot' => 'rgb(20, 184, 166)'],
        'orange' => ['ring' => 'rgb(255, 247, 237)', 'dot' => 'rgb(249, 115, 22)'],
        'purple' => ['ring' => 'rgb(250, 245, 255)', 'dot' => 'rgb(168, 85, 247)'],
        'red' => ['ring' => 'rgb(254, 242, 242)', 'dot' => 'rgb(239, 68, 68)'],
        'gray' => ['ring' => 'rgb(249, 250, 251)', 'dot' => 'rgb(107, 114, 128)'],
        'sky' => ['ring' => 'rgb(240, 249, 255)', 'dot' => 'rgb(14, 165, 233)'],
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
        
        // When a project status is saved or deleted, re-index the parent project
        static::saved(function ($projectStatus) {
            if ($projectStatus->project) {
                $projectStatus->project->searchable();
            }
        });
        
        static::deleted(function ($projectStatus) {
            if ($projectStatus->project) {
                $projectStatus->project->searchable();
            }
        });
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
     * Accessor: ring color for status indicator
     */
    public function ringColor(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $badgeColor = self::STATUS_COLORS[$attributes['status_code'] ?? 1] ?? 'gray';
                return self::COLOR_RGB_MAP[$badgeColor]['ring'] ?? self::COLOR_RGB_MAP['gray']['ring'];
            }
        );
    }

    /**
     * Accessor: dot color for status indicator
     */
    public function dotColor(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $badgeColor = self::STATUS_COLORS[$attributes['status_code'] ?? 1] ?? 'gray';
                return self::COLOR_RGB_MAP[$badgeColor]['dot'] ?? self::COLOR_RGB_MAP['gray']['dot'];
            }
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
     * Get statuses with labels and colors for selects / badges
     */
    public static function selectableStatuses(): array
    {
        return collect(self::STATUS_LABELS)
            ->map(function (string $label, int $code) {
                return [
                    'code' => $code,
                    'label' => $label,
                    'color' => self::STATUS_COLORS[$code] ?? 'gray',
                ];
            })
            ->values()
            ->all();
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
