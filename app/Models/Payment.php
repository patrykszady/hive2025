<?php

namespace App\Models;

use App\Observers\PaymentObserver;
use App\Scopes\PaymentScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;

#[ObservedBy([PaymentObserver::class])]
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function lienWaiver(): HasOne
    {
        return $this->hasOne(LienWaiver::class);
    }

    public function payments_grouped(): HasMany
    {
        return $this->hasMany(Payment::class, 'id', 'parent_client_payment_id');
    }
    
    /**
     * Get all related payments, either by transaction_id or parent_client_payment_id
     */
    public function payments(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Case 1: Payment has a transaction_id
                if ($this->transaction_id) {
                    return Payment::where('transaction_id', $this->transaction_id)->get();
                }
                
                // Case 2: Payment is part of a group with parent_client_payment_id
                if ($this->parent_client_payment_id) {
                    // Get all payments with the same parent, plus the parent itself
                    return Payment::where('id', $this->parent_client_payment_id)
                        ->orWhere('parent_client_payment_id', $this->parent_client_payment_id)
                        ->get();
                }
                
                // Case 3: Payment is a parent payment with children
                $hasChildren = Payment::where('parent_client_payment_id', $this->id)->exists();
                if ($hasChildren) {
                    return Payment::where('id', $this->id)
                        ->orWhere('parent_client_payment_id', $this->id)
                        ->get();
                }
                
                // Case 4: Standalone payment
                return collect([$this]);
            }
        );
    }
    
    /**
     * Group transaction payments by parent_client_payment_id
     */
    public function getGroupedTransactionPaymentsAttribute(): Collection
    {
        $payments = $this->payments;
        
        if ($payments->isEmpty()) {
            return collect();
        }
        
        // Eager load the client relationship for all projects
        // $payments->load('project.client');
        
        return $payments
            ->groupBy(function($payment) {
                return $payment->parent_client_payment_id ?? $payment->id;
            })
            ->map(function($clientPayments, $groupId) {
                $firstPayment = $clientPayments->first();
                
                return [
                    'id' => $groupId,
                    'client' => $firstPayment->project->client, // Changed clientName to client object
                    'reference' => $firstPayment->reference,
                    'totalAmount' => $clientPayments->sum('amount'),
                    'payments' => $clientPayments
                ];
            })
            ->values();
    }
}
