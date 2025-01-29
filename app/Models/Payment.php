<?php

namespace App\Models;

use App\Scopes\PaymentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = ['distribution_id', 'project_id', 'amount', 'date', 'reference', 'transaction_id', 'belongs_to_vendor_id', 'parent_client_payment_id', 'check_id', 'note', 'created_by_user_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new PaymentScope);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function payments_grouped(): HasMany
    {
        return $this->hasMany(Payment::class, 'id', 'parent_client_payment_id');
    }
}
