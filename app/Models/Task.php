<?php

namespace App\Models;

use App\Models\Traits\Sortable;
use App\Observers\TaskObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([TaskObserver::class])]
class Task extends Model
{
    use HasFactory, SoftDeletes, Sortable;

    protected $fillable = ['title', 'project_id', 'start_date', 'end_date', 'order', 'options', 'type', 'vendor_id', 'user_ids', 'progress', 'notes', 'belongs_to_vendor_id', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'object',
            'user_ids' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    protected function scopeSortable($query, $task)
    {
        return $task->project->tasks();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function getUsersAttribute()
    {
        return User::whereIn('id', $this->user_ids ?? [])->get();
    }

    protected function vendorId(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => empty($value) ? null : $value,
        );
    }
}
