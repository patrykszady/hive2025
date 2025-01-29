<?php

namespace App\Models;

use App\Models\Scopes\ExpenseSplitsScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseSplits extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'expense_splits';

    protected $fillable = ['amount', 'note', 'project_id', 'distribution_id', 'receipt_items', 'expense_id', 'reimbursment', 'belongs_to_vendor_id', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

    // protected $dates = ['date', 'deleted_at'];
    protected $appends = ['date', 'vendor_id'];

    protected function casts(): array
    {
        return [
            'receipt_items' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new ExpenseSplitsScope);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }

    public function getDateAttribute()
    {
        return $this->expense->date;
    }

    public function getVendorAttribute()
    {
        return $this->expense->vendor;
    }

    public function getVendorIdAttribute()
    {
        return $this->expense->vendor_id;
    }
}
