<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class TransactionBulkMatch extends Model
{
    use HasFactory;

    protected $table = 'transactions_bulk_match';

    protected $fillable = ['amount', 'vendor_id', 'distribution_id', 'belongs_to_vendor_id', 'created_at', 'updated_at', 'options'];

    protected function casts(): array
    {
        return [
            'options' =>'array',
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

    /**
     * Find the best matching bulk match rule for a given vendor and amount.
     *
     * Specific amount matches are checked first, then ANY matches as fallback.
     *
     * @return self|null The matching rule, or null if no match found.
     */
    public static function findMatchForAmount(int $vendorId, float $amount, ?string $description = null): ?self
    {
        $matches = static::where('vendor_id', $vendorId)->get();

        // First pass: check specific amount matches (non-ANY)
        foreach ($matches as $match) {
            $amountType = $match->options['amount_type'] ?? 'ANY';

            if ($amountType === 'ANY') {
                continue;
            }

            if ($match->amount === null) {
                continue;
            }

            $amountMatches = match ($amountType) {
                '=' => (float) $amount === (float) $match->amount,
                '>=' => (float) $amount >= (float) $match->amount,
                '<=' => (float) $amount <= (float) $match->amount,
                '>' => (float) $amount > (float) $match->amount,
                '<' => (float) $amount < (float) $match->amount,
                default => false,
            };

            if (! $amountMatches) {
                continue;
            }

            // Check description if the match has one
            if (! empty($match->options['desc']) && $description !== null) {
                if (stripos($description, $match->options['desc']) === false) {
                    continue;
                }
            }

            return $match;
        }

        // Second pass: fallback to ANY matches
        foreach ($matches as $match) {
            $amountType = $match->options['amount_type'] ?? 'ANY';

            if ($amountType !== 'ANY') {
                continue;
            }

            // Check description if the match has one
            if (! empty($match->options['desc']) && $description !== null) {
                if (stripos($description, $match->options['desc']) === false) {
                    continue;
                }
            }

            return $match;
        }

        return null;
    }

    /**
     * Apply this match's split rules to an expense, creating ExpenseSplits records.
     */
    public function applySplits(Expense $expense, float $amount): void
    {
        if (empty($this->options['splits'])) {
            return;
        }

        $allPreviousSplits = [];

        foreach ($this->options['splits'] as $index => $split) {
            if (($split['amount_type'] ?? '$') === '%') {
                $percentAmount = $amount * (float) $split['amount'];

                if ($index === array_key_last($this->options['splits'])) {
                    $splitAmount = round($amount - collect($allPreviousSplits)->sum('amount'), 2);
                } else {
                    $splitAmount = round($percentAmount, 2);
                    $allPreviousSplits[$index]['amount'] = $splitAmount;
                }
            } else {
                $splitAmount = $split['amount'];
            }

            ExpenseSplits::create([
                'amount' => $splitAmount,
                'expense_id' => $expense->id,
                'project_id' => null,
                'distribution_id' => $split['distribution_id'],
                'reimbursment' => null,
                'note' => null,
                'belongs_to_vendor_id' => $this->belongs_to_vendor_id,
                'created_by_user_id' => 0,
            ]);
        }
    }
}
