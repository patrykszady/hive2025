<?php

namespace App\Support\Transactions;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;

/**
 * The vendor a person transfer paid, read from the payee name the bank
 * prints: Citi's Zelle debits end "… NAME PRO TAXE" (the name cut to eight
 * characters), Chase writes "Zelle payment to MORENOS DRYWALL". Without a
 * vendor the Expenses screen's vendor filter could never find them
 * (2026-10-13: a $400 Zelle to PRO TAXES AND ACCOUNTING).
 *
 * Only an unambiguous match is taken: exactly one of the company's vendors
 * whose name starts with the payee name, and no other person (a Hive user)
 * whose name does too — "GRZEGORZ" fits three people and stays unassigned.
 */
class TransferPayee
{
    /** @var array<int, Collection<int, array{id: int, key: string}>> */
    protected static array $vendorKeys = [];

    protected static ?Collection $userKeys = null;

    /** The payee name a transfer's description carries, or null. */
    public static function nameFrom(?string $description): ?string
    {
        $description = trim((string) $description);
        if ($description === '') {
            return null;
        }
        if (preg_match('/\bZELLE\b.*\bNAME\s+(.+)$/i', $description, $m)
            || preg_match('/\bZELLE\s+(?:PAYMENT|TRANSFER)\s+TO\s+(.+?)(?:\s+(?:CONF|JPM|ID)\b.*)?$/i', $description, $m)) {
            $name = trim(preg_replace('/\s+/', ' ', $m[1]));

            return $name === '' ? null : $name;
        }

        return null;
    }

    public static function key(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /** The one vendor of the company the payee name points to, or null. */
    public static function vendorFor(string $payeeName, int $companyVendorId): ?Vendor
    {
        $key = self::key($payeeName);
        if (strlen($key) < 4) {
            return null;
        }

        $vendors = self::vendorKeys($companyVendorId)->filter(fn (array $v) => str_starts_with($v['key'], $key))->values();
        if ($vendors->count() !== 1) {
            return null;
        }
        $vendorKey = $vendors[0]['key'];

        // Another person answering to the same name makes it ambiguous; the
        // vendor's own owner (a user whose name begins the vendor's) does not.
        $otherPeople = self::userKeys()->filter(fn (string $u) => str_starts_with($u, $key) && ! str_starts_with($vendorKey, $u));
        if ($otherPeople->isNotEmpty()) {
            return null;
        }

        return Vendor::withoutGlobalScopes()->find($vendors[0]['id']);
    }

    /** Assign the payee's vendor to a person transfer without one; true when assigned. */
    public static function assign(Transaction $transaction, int $companyVendorId): bool
    {
        if ($transaction->vendor_id || $transaction->transferKind() !== 'person') {
            return false;
        }
        $name = self::nameFrom($transaction->plaid_merchant_description);
        $vendor = $name ? self::vendorFor($name, $companyVendorId) : null;
        if (! $vendor) {
            return false;
        }
        $transaction->vendor_id = $vendor->id;
        $transaction->save();

        return true;
    }

    public static function flush(): void
    {
        self::$vendorKeys = [];
        self::$userKeys = null;
    }

    /** @return Collection<int, array{id: int, key: string}> */
    protected static function vendorKeys(int $companyVendorId): Collection
    {
        if (! isset(self::$vendorKeys[$companyVendorId])) {
            $company = Vendor::withoutGlobalScopes()->find($companyVendorId);
            self::$vendorKeys[$companyVendorId] = $company
                ? $company->vendors()->withoutGlobalScopes()->get(['vendors.id', 'vendors.business_name'])
                    ->map(fn (Vendor $v) => ['id' => (int) $v->id, 'key' => self::key((string) $v->business_name)])
                    ->filter(fn (array $v) => $v['key'] !== '')
                    ->values()
                : collect();
        }

        return self::$vendorKeys[$companyVendorId];
    }

    /** @return Collection<int, string> */
    protected static function userKeys(): Collection
    {
        return self::$userKeys ??= User::query()->get(['first_name', 'last_name'])
            ->map(fn (User $u) => self::key(trim($u->first_name.' '.$u->last_name)))
            ->filter()
            ->values();
    }
}
