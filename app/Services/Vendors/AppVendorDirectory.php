<?php

namespace App\Services\Vendors;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\EngineManager;

/**
 * The app's Vendors tab: the same directory as the web Vendors index
 * (App\Livewire\Vendors\VendorsIndex) — search, the business-type filter,
 * and the YTD-paid figure — reshaped for a phone list instead of a sortable
 * table. VendorScope (applied automatically on every Vendor query) already
 * limits results to the signed-in vendor's own company-tree, exactly like
 * the web; nothing here needs to re-derive that.
 */
class AppVendorDirectory
{
    /**
     * Business types the filter chips offer, in the web's own select order
     * (resources/views/livewire/vendors/partials/filter-fields.blade.php).
     *
     * @var list<string>
     */
    public const TYPES = ['All', 'Sub', 'Retail', '1099', 'DBA'];

    /** The web's own default filter (VendorsIndex::$vendor_type). */
    public const DEFAULT_TYPE = 'Sub';

    /**
     * Vendors visible to the signed-in user's company (via VendorScope),
     * filtered to $type (All = no filter) and $search (business name —
     * Meilisearch first, a plain LIKE if that index call fails), each
     * carrying its year-to-date paid total for the detail/list screens that
     * are allowed to show it.
     */
    public function query(string $type, string $search): Builder
    {
        $query = Vendor::query()
            ->withSum(['expenses as ytd_expense_sum' => function (Builder $expenses): void {
                $expenses->where('date', '>=', today()->subYear());
            }], 'amount');

        if ($type !== '' && $type !== 'All') {
            $query->where('business_type', $type);
        }

        $this->applySearch($query, trim($search));

        return $query->orderBy('business_name')->orderBy('id');
    }

    /**
     * Normalizes a `type` query param to one of self::TYPES, falling back to
     * the web's own default when missing or unrecognized.
     */
    public function normalizeType(mixed $value): string
    {
        $type = is_string($value) ? $value : self::DEFAULT_TYPE;

        return in_array($type, self::TYPES, true) ? $type : self::DEFAULT_TYPE;
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        try {
            $ids = Vendor::search($search)->take(200)->keys()->map(fn ($id) => (int) $id)->all();
            $query->whereIn('vendors.id', $ids);

            return;
        } catch (\Throwable $exception) {
            report($exception);
        }

        // Meilisearch unavailable: a plain LIKE on the same field the web's
        // search covers (business name).
        $query->where('business_name', 'like', '%'.$search.'%');
    }
}
