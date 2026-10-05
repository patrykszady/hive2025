<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tender of an expense: how part (or all) of it was paid. A card line is
 * paid by a bank charge (transaction_id once matched); store credit, a gift
 * card, points and cash never reach the bank, so they count as paid and
 * shrink what is left to find among bank charges.
 */
class ExpensePayment extends Model
{
    /** @use HasFactory<\Database\Factories\ExpensePaymentFactory> */
    use HasFactory;

    public const CARD = 'card';

    public const STORE_CREDIT = 'store_credit';

    public const GIFT_CARD = 'gift_card';

    /** Store rewards spent as money: Home Depot's Pro Xtra Dollars. */
    public const POINTS = 'points';

    /** Amazon reports a gift card balance and reward points alike: a charge with no card. */
    public const GIFT_CARD_OR_POINTS = 'gift_card_or_points';

    public const CASH = 'cash';

    public const OTHER = 'other';

    /** Paid without a bank charge. */
    public const OFF_BANK = [self::STORE_CREDIT, self::GIFT_CARD, self::POINTS, self::GIFT_CARD_OR_POINTS, self::CASH];

    public const SOURCE_AMAZON = 'amazon';

    public const SOURCE_RECEIPT_TEXT = 'receipt_text';

    public const SOURCE_RECEIPT_READER = 'receipt_reader';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'expense_id',
        'expense_receipt_id',
        'method',
        'amount',
        'last_four',
        'brand',
        'paid_at',
        'source',
        'source_ref',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ExpenseReceipts::class, 'expense_receipt_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isOffBank(): bool
    {
        return in_array($this->method, self::OFF_BANK, true);
    }

    /** What paid it, in a word or two: "Rebate check", "Certificate", "Store credit", "Cash"… */
    public function methodName(): string
    {
        return match (true) {
            $this->brand === \App\Support\ReceiptTenders::REBATE_CHECK => 'Rebate check',
            $this->brand === \App\Support\ReceiptTenders::CERTIFICATE => 'Certificate',
            $this->method === self::CARD => trim('Card'.($this->brand ? ' ('.$this->brand.')' : '')),
            $this->method === self::STORE_CREDIT => 'Store credit',
            $this->method === self::GIFT_CARD => 'Gift card',
            $this->method === self::POINTS => 'Points',
            $this->method === self::GIFT_CARD_OR_POINTS => 'Gift card or points',
            $this->method === self::CASH => 'Cash',
            default => 'Other',
        };
    }

    /** The note under its row on the expense page. */
    public function notation(): string
    {
        $from = match ($this->source) {
            self::SOURCE_AMAZON => 'from the Amazon order',
            self::SOURCE_MANUAL => 'entered by hand',
            default => 'from the receipt',
        };

        return ($this->isOffBank() ? 'Paid without a bank charge · ' : '').$this->methodName().($this->last_four ? ' ••'.$this->last_four : '').' · '.$from;
    }

    /** "Card ••4849 (VISA)", "Store credit", "Pro Xtra Dollars"… */
    public function label(): string
    {
        return match ($this->method) {
            self::CARD => trim('Card'.($this->last_four ? ' ••'.$this->last_four : '').($this->brand ? ' ('.$this->brand.')' : '')),
            self::STORE_CREDIT => $this->brand ? 'Store credit ('.strtolower($this->brand).')' : 'Store credit',
            self::GIFT_CARD => $this->brand ? 'Gift card ('.strtolower($this->brand).')' : 'Gift card',
            self::POINTS => 'Points',
            self::GIFT_CARD_OR_POINTS => 'Gift card or points',
            self::CASH => 'Cash',
            default => 'Other',
        };
    }
}
