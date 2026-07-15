<?php

namespace App\Models;

use App\Observers\CheckObserver;
use App\Scopes\CheckScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([CheckObserver::class])]
class Check extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['check_type', 'check_number', 'date', 'bank_account_id', 'user_id', 'vendor_id', 'belongs_to_vendor_id', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at', 'amount'];

    // protected $dates = ['date', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new CheckScope);
    }

    public function bank_account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Scanned check images cropped from bank statements (no global scope).
     */
    public function checkImages(): HasMany
    {
        return $this->hasMany(CheckImage::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function lienWaivers(): HasMany
    {
        return $this->hasMany(LienWaiver::class);
    }

    public function expensesMany(): BelongsToMany
    {
        return $this->belongsToMany(Expense::class, 'check_expense')->withTimestamps();
    }

    /**
     * Is this expense a DEDUCTION on this check (a reimbursement being settled
     * by it), as opposed to something the check is paying for?
     *
     * A reimbursement only deducts on its SETTLEMENT check — the check whose
     * payee is the party that owes the company (or, for user checks, the payee
     * who personally paid off someone else's owed expense). The same expense
     * attached to its own merchant-payment check counts positive.
     */
    public function isSettlementDeduction(Expense $expense): bool
    {
        $raw = $expense->getRawOriginal('reimbursment');
        if ($raw === null || trim((string) $raw) === '' || $raw === 'Client') {
            return false;
        }

        // User owes the company; settled through that user's payment check
        if ($this->user_id && (string) $raw === (string) $this->user_id) {
            return true;
        }

        // Vendor owes the company; settled through that vendor's payment check
        if ($this->vendor_id && $raw === 'V:'.$this->vendor_id) {
            return true;
        }

        // The check's payee personally paid off ANOTHER party's owed expense
        // (CheckShow's "Paid Off Other User Reimbursements" bucket)
        if ($this->user_id && $expense->paid_by && (int) $expense->paid_by === (int) $this->user_id) {
            return true;
        }

        return false;
    }

    /**
     * Recalculate this check's amount from its attached records.
     *
     * amount = timesheets + expenses, where reimbursement expenses being
     * SETTLED by this check (see isSettlementDeduction) count as deductions.
     * Their DB amounts stay positive; the negation only affects the stored
     * check total. Centralized here so timesheet payments, vendor payments
     * and every check/expense attach/detach flow agree on the math.
     */
    public function recalculateAmount(): void
    {
        $expenseSum = $this->expenses()->get()
            ->concat($this->expensesMany()->get())
            ->unique('id')
            ->sum(function ($expense) {
                $amount = (float) $expense->amount;

                return $this->isSettlementDeduction($expense) ? -abs($amount) : $amount;
            });

        $this->amount = $this->timesheets()->sum('amount') + $expenseSum;
        $this->save();
    }

    /**
     * Unified, human-friendly payment label for UI.
     */
    protected function paymentLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => match ($this->check_type) {
                'Transfer' => 'Transfer',
                'Check'    => 'Check',
                'Cash'     => 'Cash',
                default    => (string) $this->check_type,
            }
        );
    }

    protected function checkNumber(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->check_type === 'Check' ? $value : $this->id,
        );
        //->shouldCache();
    }

    public function getAmountDifferenceAttribute()
    {
        // If transactions_sum_amount is not loaded, calculate it
        $transactions_sum = $this->transactions_sum_amount ?? $this->transactions()->sum('amount');
        return $this->amount - $transactions_sum;
    }

    protected function owner(): Attribute
    {
        return Attribute::make(
            get: function () {
                // If check has a user, check for via_vendor relationship
                if ($this->user_id) {
                    // Get user's relationship with the current vendor context
                    $userVendorPivot = $this->user->vendors()
                        ->where('vendors.id', auth()->user()->vendor->id)
                        ->first();
                    
                    // If user has a via_vendor_id, get the vendor directly through the relationship
                    if ($userVendorPivot && $userVendorPivot->pivot->via_vendor_id) {
                        // Load the via_vendor through the Vendor model's relationship
                        $viaVendor = $userVendorPivot->vendors()
                            ->where('vendors.id', $userVendorPivot->pivot->via_vendor_id)
                            ->first();
                            
                        if ($viaVendor) {
                return trim($viaVendor->business_name . ($viaVendor->business_type ? ' - ' . $viaVendor->business_type : ''));
                        }
                    }
                    
                    // Otherwise use user's full name
                    return $this->user->full_name;
                } 
                // If check has a vendor, use vendor name
                elseif ($this->vendor) {
            return trim($this->vendor->business_name);
                }
                
                // Fallback if neither user nor vendor are valid
                return null;
            }
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->transactions->sum('amount') == $this->amount) {
                    return 'Complete';
                } elseif ($this->transactions->isNotEmpty() && $this->transactions->sum('amount') != $this->amount) {
                    return 'Missing Transactions';
                } else {
                    return 'No Transactions';
                }
            }
        );
    }

    /**
     * Get the appropriate color for the status badge
     */
    protected function statusColor(): Attribute
    {
        return Attribute::make(
            get: fn () => match($this->status) {
                'Complete' => 'green',
                'Missing Transactions' => 'yellow',
                'No Transactions' => 'red',
                default => 'zinc'
            }
        );
    }
}
