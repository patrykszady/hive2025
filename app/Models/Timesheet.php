<?php

namespace App\Models;

use App\Scopes\TimesheetScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Timesheet extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['date', 'user_id', 'vendor_id', 'project_id', 'hours', 'amount', 'paid_by', 'check_id', 'hourly', 'invoice', 'note', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new TimesheetScope);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(Hour::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }
}
