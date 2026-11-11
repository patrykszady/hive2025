<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Support\Facades\Log;

/**
 * The office flags a purchase the client pays back with a standalone R, and
 * a receipt carrying that R sets its expense's Reimbursement = Client. The
 * R reaches a receipt two ways:
 *
 * A. TYPED INTO THE ORDER'S PO / JOB NAME when the purchase is made — Home
 *    Depot's "PO/JOB NAME: 2600 R", a Menards PO, an Amazon "PO# 1856_R".
 *    The receipt reader keeps it as receipt_items.purchase_order, so this
 *    works for every receipt, whatever brought it in (a vendor's email, the
 *    Amazon fetch, the Menards crawl, a scan). Of 110 past expenses whose
 *    receipt text has such a PO line, 71 were marked Client by hand
 *    (2026-11-11).
 *
 *    Anything that finds the R marks the expense, and nothing here ever
 *    takes the mark off again: a later scan without an R leaves it
 *    (Patryk, 2026-11-11).
 *
 * B. HAND-WRITTEN ON THE PAPER, normally inside a circle, beside the job
 *    number. Only paper the office handled is read for it: receipts off the
 *    office scanner (the Epson "Receipt Scans" emails —
 *    auto_receipt_message_id) and receipts someone uploaded with "Upload
 *    Receipt". Three readings, in this order:
 *
 *  1. The picture, read while the receipt is processed
 *     (ScannedReceiptMarkReader, an Azure image analyzer; kept as
 *     receipt_items.client_reimbursement_mark_image): "true" marks,
 *     "false" does not.
 *  2. When the picture is "unknown" or was not read: the receipt reader's
 *     own answer (the analyzer's ClientReimbursementMark field →
 *     receipt_items.client_reimbursement_mark).
 *  3. When that is "unknown" or absent too: a standalone R in the
 *     handwriting it transcribed (receipt_items.handwritten_notes —
 *     "949 | R", "1400 R").
 *
 * Measured 2026-11-11 on 112 scans: the picture got 47 of 47 marks and no
 * false alarm; the receipt reader's answer and the transcribed notes each
 * got about three quarters, because the document reader works from the text
 * it transcribed and the hand-written R is often dropped.
 */
class ReceiptReimbursementMark
{
    public const CLIENT = 'Client';

    public const YES = 'true';

    public const NO = 'false';

    public const UNKNOWN = 'unknown';

    /** A PO mark that a later scan without an R took off again. */
    public const OVERRULED_BY_PAPER = 'purchase_order_overruled_by_paper';

    /**
     * Mark the receipt's expense when the receipt was just saved with the R,
     * or its data just gained it (some fields are filled by a later job). An
     * expense someone already gave a reimbursement, or one that is split, is
     * left alone — and so is one whose mark was already there before this
     * save, so clearing the field by hand sticks.
     */
    public function apply(ExpenseReceipts $receipt): bool
    {
        $officePaper = self::isOfficePaper($receipt);

        if (! $receipt->expense_id || ! self::flagsClient($receipt->receipt_items, $officePaper)) {
            return false;
        }

        $markIsNew = $receipt->wasRecentlyCreated
            || ($receipt->wasChanged('receipt_items') && ! self::flagsClient(self::decode($receipt->getOriginal('receipt_items')), $officePaper));

        if (! $markIsNew) {
            return false;
        }

        $expense = Expense::withoutGlobalScopes()->find($receipt->expense_id);

        if (! $expense || filled($expense->getRawOriginal('reimbursment')) || $expense->splits()->exists()) {
            return false;
        }

        $expense->reimbursment = self::CLIENT;
        $expense->save();

        Log::info('Receipt carries the R: expense marked Reimbursement = Client', [
            'expense_id' => $expense->id,
            'receipt_id' => $receipt->id,
        ]);

        return true;
    }

    /**
     * Whether a receipt's data flags its expense as client-reimbursed: an R
     * in the order's PO / job name (any receipt), or the hand-written R
     * (paper the office handled only).
     *
     * @param  array<string, mixed>|null  $receiptItems
     */
    public static function flagsClient(?array $receiptItems, bool $officePaper): bool
    {
        return self::purchaseOrderCarriesMark($receiptItems) || ($officePaper && self::carriesMark($receiptItems));
    }

    /**
     * Whether the order's PO / job name has an R standing on its own:
     * "2600 R", "288_R", "#3154 R", "630 red barn R", "1400 r" — never the
     * R of "ROSEWOOD" or "RD".
     *
     * @param  array<string, mixed>|null  $receiptItems
     */
    public static function purchaseOrderCarriesMark(?array $receiptItems): bool
    {
        foreach (\Illuminate\Support\Arr::flatten((array) ($receiptItems['purchase_order'] ?? [])) as $purchaseOrder) {
            if (is_string($purchaseOrder) && self::hasStandaloneR($purchaseOrder, anyCase: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An R with no letter or digit on either side (a space, "_", "#", "|" or
     * nothing). A typed PO counts a lowercase r too ("1400 r"); transcribed
     * handwriting does not, where a stray lowercase letter is usually noise.
     */
    public static function hasStandaloneR(string $text, bool $anyCase = false): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{N}])(?:R|Ⓡ)(?![\p{L}\p{N}])/u'.($anyCase ? 'i' : ''), $text);
    }

    /**
     * Paper the office handled: a scan off the office scanner, or a receipt
     * someone uploaded ("Upload Receipt") — those are the ones whose picture
     * is looked at when they are processed.
     */
    public static function isOfficePaper(ExpenseReceipts $receipt): bool
    {
        return (bool) $receipt->auto_receipt_message_id
            || array_key_exists('client_reimbursement_mark_image', (array) $receipt->receipt_items);
    }

    /**
     * Whether a receipt carries the R (circled, or written bare after the
     * job number). The picture decides; when it could not tell or was not
     * looked at, the receipt reader's answer and then the transcribed
     * handwriting decide.
     *
     * @param  array<string, mixed>|null  $receiptItems
     */
    public static function carriesMark(?array $receiptItems): bool
    {
        $picture = $receiptItems['client_reimbursement_mark_image'] ?? null;

        return match ($picture) {
            self::YES => true,
            self::NO => false,
            default => match ($receiptItems['client_reimbursement_mark'] ?? null) {
                self::YES => true,
                self::NO => false,
                default => self::notesCarryMark($receiptItems),
            },
        };
    }

    /**
     * The reader's answer as stored: "true", "false" or "unknown" (also for
     * a missing or unreadable field).
     */
    public static function answerFrom(mixed $field): string
    {
        $value = is_array($field)
            ? ($field['valueString'] ?? $field['content'] ?? (array_key_exists('valueBoolean', $field) ? ($field['valueBoolean'] ? self::YES : self::NO) : null))
            : $field;
        $value = is_bool($value) ? ($value ? self::YES : self::NO) : mb_strtolower(trim((string) $value));

        return match ($value) {
            'true', 'yes' => self::YES,
            'false', 'no' => self::NO,
            default => self::UNKNOWN,
        };
    }

    /**
     * Whether the handwriting read off a receipt has an R standing on its
     * own: "R", "(R)", "949 | R", "1400 R" — never the R of "Rob" or "RD".
     *
     * @param  array<string, mixed>|null  $receiptItems
     */
    public static function notesCarryMark(?array $receiptItems): bool
    {
        foreach ((array) ($receiptItems['handwritten_notes'] ?? []) as $note) {
            if (is_string($note) && self::hasStandaloneR($note)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    protected static function decode(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }
}
