<?php

namespace App\Support\Transactions;

use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Support\Collection;

/**
 * One rule per "Match As" text (Patryk, 2026-11-13: "review the match vendor
 * database .. it might have duplicates (should be prevented to create a
 * duplicate via livewire validator when submitting the form)"). The table
 * held nine sets of the same rule saved more than once — "Dsp Motorsports"
 * four times, "Frontier Communications" twice on one night — because a save
 * never looked at what was already there.
 *
 * Two rules are the same when their text is, read as a person reads it ("\*"
 * is "*", case and spacing aside), and they apply to the same bank and the
 * same sign of amount: whichever vendor or code they point at, a second one
 * either repeats the first or fights it.
 *
 * Also one vendor per name: a NEW vendor is not made when a vendor of that
 * name is already on file.
 */
class MatchRuleDuplicates
{
    private const CODES = [1 => 'Deposit', 2 => 'Check', 3 => 'Transfer', 4 => 'Cash'];

    /** The text as a person reads it, for comparing: "\*" is "*", case and spacing aside. */
    public static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\\\\(.)/u', '$1', $text))));
    }

    /**
     * Rules already on file for this text, bank and sign.
     *
     * @return Collection<int, VendorTransaction>
     */
    public static function existing(string $text, ?string $institutionId = null, ?int $amountSign = null): Collection
    {
        $wanted = self::normalize($text);

        if ($wanted === '') {
            return collect();
        }

        return VendorTransaction::query()
            ->get(['id', 'vendor_id', 'deposit_check', 'amount_sign', 'plaid_inst_id', 'desc'])
            ->filter(fn (VendorTransaction $rule): bool => self::normalize((string) $rule->desc) === $wanted
                && (string) $rule->plaid_inst_id === (string) $institutionId
                && (int) $rule->amount_sign === (int) $amountSign)
            ->values();
    }

    /**
     * Why a new rule for this text would be a duplicate, or null when it
     * would not. $vendorId or $depositCheck is what the new rule points at.
     */
    public static function refusal(string $text, ?int $vendorId, ?int $depositCheck = null, ?string $institutionId = null, ?int $amountSign = null): ?string
    {
        $rule = self::existing($text, $institutionId, $amountSign)->first();

        if ($rule === null) {
            return null;
        }

        $sameTarget = ($vendorId !== null && (int) $rule->vendor_id === $vendorId)
            || ($vendorId === null && $depositCheck !== null && (int) $rule->deposit_check === $depositCheck);

        return $sameTarget
            ? sprintf('There is already a rule for "%s" → %s (#%d). Saving it again would only add a duplicate.', trim($text), self::target($rule), $rule->id)
            : sprintf('"%s" is already matched to %s by rule #%d. Edit or delete that rule instead of adding a second one.', trim($text), self::target($rule), $rule->id);
    }

    /** Why a NEW vendor of this name would be a duplicate, or null when none has it. */
    public static function newVendorRefusal(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $vendor = Vendor::withoutGlobalScopes()
            ->whereRaw('LOWER(TRIM(business_name)) = ?', [mb_strtolower($name)])
            ->orderBy('id')
            ->first(['id', 'business_name']);

        return $vendor === null
            ? null
            : sprintf('A vendor named "%s" already exists (#%d). Pick it in Vendor instead of NEW Retail Vendor.', $vendor->business_name, $vendor->id);
    }

    private static function target(VendorTransaction $rule): string
    {
        if ($rule->vendor_id) {
            return (string) (Vendor::withoutGlobalScopes()->whereKey($rule->vendor_id)->value('business_name') ?? 'vendor #'.$rule->vendor_id);
        }

        return self::CODES[(int) $rule->deposit_check] ?? 'code '.$rule->deposit_check;
    }
}
