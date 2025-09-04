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

    protected $fillable = ['project_id', 'belongs_to_vendor_id', 'start_date', 'end_date', 'title', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
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
