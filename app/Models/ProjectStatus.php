<?php

namespace App\Models;

use App\Scopes\ProjectStatusScope;
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
}
