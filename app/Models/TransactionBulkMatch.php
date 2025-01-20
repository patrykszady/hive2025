<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionBulkMatch extends Model
{
    use HasFactory;

    protected $table = 'transactions_bulk_match';

    protected $fillable = ['amount', 'vendor_id', 'distribution_id', 'belongs_to_vendor_id', 'created_at', 'updated_at', 'options'];

    protected function casts(): array
    {
        return [
            'options' => AsArrayObject::class,
        ];
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function belongs_to_vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
