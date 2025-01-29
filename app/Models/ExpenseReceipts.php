<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseReceipts extends Model
{
    use HasFactory;

    protected $table = 'expense_receipts_data';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receipt_items' => 'json',
        ];
    }

    // protected $fillable = ['expense_id', 'receipt_html' , 'receipt_filename'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function getNotesAttribute($value)
    {
        if (! empty($this->receipt_items->handwritten_notes)) {
            $handwritten_notes = $this->receipt_items->handwritten_notes;
            $handwritten_notes = implode(' | ', $handwritten_notes);
        } else {
            $handwritten_notes = false;
        }

        if (isset($this->receipt_items->purchase_order)) {
            $purchase_order = $this->receipt_items->purchase_order;
        } else {
            $purchase_order = false;
        }

        $notes = array_filter([$handwritten_notes, $purchase_order]);
        $notes = implode(' | ', $notes);

        return $notes;
    }

    public function getReceiptItemsAttribute($value)
    {
        if ($value == null) {
            $receipt_items = null;
        } else {
            $receipt_items = json_decode($value);
        }

        return $receipt_items;
    }

    // public function getReceiptItemsAttribute($value)
    // {
    //     if($value == NULL){
    //         $receipt_items = NULL;
    //     }else{
    //         $receipt_items = json_decode($value);
    //         if(!is_null($receipt_items->items)){
    //             foreach($receipt_items->items as $item){
    //                 if(isset($item->valueObject->Price->valueNumber)){
    //                     $item->price_each = $item->valueObject->Price->valueNumber;
    //                 }elseif(isset($item->valueObject->Price->valueCurrency)){
    //                     $item->price_each = $item->valueObject->Price->valueCurrency->amount;
    //                 }elseif(isset($item->valueObject->UnitPrice->valueCurrency)){
    //                     $item->price_each = $item->valueObject->UnitPrice->valueCurrency->amount;
    //                 }else{
    //                     $item->price_each = NULL;
    //                 }

    //                 if(isset($item->valueObject->TotalPrice->valueNumber)){
    //                     $item->price_total = $item->valueObject->TotalPrice->valueNumber;
    //                 }elseif(isset($item->valueObject->TotalPrice->valueCurrency)){
    //                     $item->price_total = $item->valueObject->TotalPrice->valueCurrency->amount;
    //                 }elseif(isset($item->valueObject->TotalPrice->valueNumber)){
    //                     $item->price_total = $item->valueObject->TotalPrice->valueNumber;
    //                 }elseif(isset($item->valueObject->Amount)){
    //                     $item->price_total = $item->valueObject->Amount->valueCurrency->amount;
    //                 }else{
    //                     $item->price_total = NULL;
    //                 }

    //                 if(isset($item->valueObject->Quantity->valueNumber)){
    //                     $item->quantity = $item->valueObject->Quantity->valueNumber;
    //                 }else{
    //                     if($item->price_each == $item->price_total){
    //                         $item->quantity = 1;
    //                     }else{
    //                         if(!is_null($item->price_total) && !is_null($item->price_each)){
    //                             $item->quantity = $item->price_total / $item->price_each;
    //                         }else{
    //                             $item->quantity = 1;
    //                         }
    //                     }
    //                 }

    //                 if(isset($item->valueObject->Description)){
    //                     $item->desc = $item->valueObject->Description->valueString;
    //                 }else{
    //                     $item->desc = $item->content;
    //                 }

    //                 $item->product_code = isset($item->valueObject->ProductCode->valueString) ? '# ' . $item->valueObject->ProductCode->valueString : NULL;
    //             }
    //         }
    //     }

    //     return $receipt_items;
    // }

    // public function getHandwrittenAttribute($value)
    // {
    //     $notes = $this->receipt_items->handwritten_notes;
    //     if($notes){
    //         return implode(", ", $notes);
    //     }else{
    //         return NULL;
    //     }
    // }

    // public function getReceiptDateAttribute($value)
    // {
    //     if(is_string($this->receipt_items->transaction_date)){
    //         $date = Carbon::parse($this->receipt_items->transaction_date);
    //     }else{
    //         $date = Carbon::parse($this->receipt_items->transaction_date->valueDate);
    //         // if(is_string($this->receipt_items->transaction_date)){
    //         //     $date = Carbon::parse($this->receipt_items->transaction_date->valueDate);
    //         // }else{
    //         //     $date = Carbon::parse($this->receipt_items->transaction_date->valueDate);
    //         // }
    //     }

    //     return $date;
    // }

    // public function getTaxAttribute($value)
    // {
    //     try {
    //         $this_subtotal = $this->subtotal;
    //     } catch (\Exception $e) {
    //         $this_subtotal = NULL;
    //     }

    //     if(is_string($this->receipt_items->total_tax) || is_float($this->receipt_items->total_tax)){
    //         $tax = $this->receipt_items->total_tax;
    //     }else{
    //         if(isset($this->receipt_items->total_tax->valueNumber)){
    //             $tax = $this->receipt_items->total_tax->valueNumber;
    //         }else{
    //             if(isset($this->total) && !is_null($this_subtotal)){
    //                 $tax = $this->total - $this->subtotal;
    //             }else{
    //                 $tax = FALSE;
    //             }
    //         }
    //     }

    //     return $tax;
    // }

    // public function getSubtotalAttribute($value)
    // {
    //     if($this->receipt_items->subtotal){
    //         // dd(is_numeric($this->receipt_items->subtotal));
    //         // if(is_string($this->receipt_items->subtotal) || is_float($this->receipt_items->subtotal)){
    //         if(is_numeric($this->receipt_items->subtotal)){
    //             $subtotal = $this->receipt_items->subtotal;
    //         }else{
    //             $subtotal = $this->receipt_items->subtotal->valueNumber;
    //         }
    //     }else{
    //         if(isset($this->total) && isset($this->tax)){
    //             $subtotal = $this->total - $this->tax;
    //         }else{
    //             $subtotal = FALSE;
    //         }
    //     }

    //     return $subtotal;
    // }

    // public function getTotalAttribute($value)
    // {
    //     if($this->expense->amount != $this->receipt_items->total){
    //         $total = $this->expense->amount;
    //     }else{
    //         $total = $this->receipt_items->total;
    //     }
    //     // if($this->receipt_items->total){
    //     //     $total = $this->receipt_items->total;
    //     // }else{
    //     //     $total = FALSE;
    //     // }

    //     return $total;
    // }
}
