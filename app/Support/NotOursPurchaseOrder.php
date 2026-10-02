<?php

namespace App\Support;

use App\Models\Distribution;
use App\Models\Expense;

/**
 * A purchase order saying an order is not the company's: "Not Greg home",
 * "not patryk home", "Not greghome". Amazon orders placed on the shared
 * account for someone's home carry one. Paid with that person's own card,
 * the expense is not the company's at all; charged to a business card, it
 * was that person's home purchase paid by the business and belongs on their
 * "<Name> - Home" distribution. expenses:remove-not-ours sorts them nightly.
 * The PO matchers never read one as a match ("Not Greg home" scored 1.0
 * against Greg - Home before this).
 */
final class NotOursPurchaseOrder
{
    public static function matches(mixed $purchaseOrder): bool
    {
        return self::normalize($purchaseOrder) !== null
            && preg_match('/^not( |$)/', self::normalize($purchaseOrder)) === 1;
    }

    /** Does any of the expense's receipts carry a "Not …" purchase order? */
    public static function onExpense(Expense $expense): bool
    {
        return self::purchaseOrderOn($expense) !== null;
    }

    /** The expense's first "Not …" purchase order, or null. */
    public static function purchaseOrderOn(Expense $expense): ?string
    {
        if (! $expense->relationLoaded('receipts')) {
            $expense->load('receipts');
        }

        foreach ($expense->receipts as $receipt) {
            $items = $receipt->receipt_items ?? null;
            $purchaseOrders = is_array($items) ? (array) ($items['purchase_order'] ?? []) : [];

            foreach ($purchaseOrders as $purchaseOrder) {
                if (self::matches($purchaseOrder)) {
                    return (string) $purchaseOrder;
                }
            }
        }

        return null;
    }

    /**
     * The "<Name> - Home" distribution a "Not <name> home" purchase order
     * names, at the company: "Not Greg home", "Not greghome" and the typo
     * "Not Greg hom" all give Greg - Home. Null unless exactly one fits.
     */
    public static function homeDistributionId(string $purchaseOrder, int $belongsToVendorId): ?int
    {
        $normalized = self::normalize($purchaseOrder);
        if ($normalized === null || ! self::matches($purchaseOrder)) {
            return null;
        }

        $named = str_replace(' ', '', (string) preg_replace('/^not ?/', '', $normalized));
        if ($named === '') {
            return null;
        }

        $fits = Distribution::withoutGlobalScopes()
            ->where('vendor_id', $belongsToVendorId)
            ->get(['id', 'name'])
            ->filter(function (Distribution $distribution) use ($named): bool {
                if (! preg_match('/^(.+?)\s*-\s*home$/i', trim((string) $distribution->name), $m)) {
                    return false;
                }

                $person = (string) preg_replace('/[^a-z0-9]/', '', strtolower($m[1]));
                if ($person === '' || ! str_starts_with($named, $person)) {
                    return false;
                }

                $rest = substr($named, strlen($person));

                return $rest === '' || str_starts_with('home', $rest);
            });

        return $fits->count() === 1 ? (int) $fits->first()->id : null;
    }

    private static function normalize(mixed $purchaseOrder): ?string
    {
        if (! is_string($purchaseOrder)) {
            return null;
        }

        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($purchaseOrder)));

        return $normalized === '' ? null : $normalized;
    }
}
