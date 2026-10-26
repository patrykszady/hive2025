<?php

namespace App\Services\Estimates;

use App\Models\Bid;
use App\Models\Vendor;
use Illuminate\Support\Str;

/**
 * Which vendor a document came from, by the company name printed on it: a
 * bidder on the project first, then the whole directory. Shared by the
 * sub-invoice comparison (AnalyzeEstimateSubDocument) and the supplier quote
 * import (ReadSupplierQuote).
 */
class SubVendorRecogniser
{
    public function recognise(?int $projectId, ?string $name): ?int
    {
        $needle = self::normalise((string) $name);

        if ($needle === '') {
            return null;
        }

        $bidders = $projectId
            ? Vendor::withoutGlobalScopes()->whereIn('id', Bid::withoutGlobalScopes()->where('project_id', $projectId)->pluck('vendor_id'))->get(['id', 'business_name'])
            : collect();

        foreach ([$bidders, Vendor::withoutGlobalScopes()->get(['id', 'business_name'])] as $vendors) {
            $match = $vendors->first(function (Vendor $vendor) use ($needle) {
                $candidate = self::normalise((string) $vendor->business_name);

                return $candidate !== '' && ($candidate === $needle || str_starts_with($needle, $candidate) || str_starts_with($candidate, $needle));
            });

            if ($match) {
                return $match->id;
            }
        }

        return null;
    }

    public static function normalise(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/\b(inc|incorporated|llc|l\.l\.c|ltd|limited|corp|corporation|co)\b\.?/', '', $name) ?? $name;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '');
    }
}
