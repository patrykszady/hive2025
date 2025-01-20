<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['date', 'origin', 'notes', 'user_id', 'lead_data', 'belongs_to_vendor_id', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d H:i:s',
            'deleted_at' => 'date:Y-m-d',
            'lead_data' => AsArrayObject::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(LeadStatus::class);
    }

    public function last_status(): HasOne
    {
        return $this->hasOne(LeadStatus::class)->orderBy('created_at', 'DESC')->latest();
    }
}
