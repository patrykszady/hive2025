<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptLineItemDesc extends Model
{
    protected $fillable = [
        'expense_receipt_id',
        'item_index',
        'vendor_id',
        'sku',
        'area',
        'product_url',
        'product_image_url',
    ];

    protected function casts(): array
    {
        return [
            'area' => 'array',
        ];
    }

    public function expenseReceipt(): BelongsTo
    {
        return $this->belongsTo(ExpenseReceipts::class, 'expense_receipt_id');
    }
}
