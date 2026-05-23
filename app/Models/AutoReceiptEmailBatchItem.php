<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoReceiptEmailBatchItem extends Model
{
    protected $fillable = [
        'batch_id',
        'expense_receipt_id',
        'attachment_index',
    ];

    protected $casts = [
        'attachment_index' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AutoReceiptEmailBatch::class, 'batch_id');
    }

    public function expenseReceipt(): BelongsTo
    {
        return $this->belongsTo(ExpenseReceipts::class, 'expense_receipt_id');
    }
}
