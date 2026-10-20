<?php

namespace App\Services\Vendors;

use App\Livewire\Vendors\VendorOptions;
use App\Models\User;
use App\Models\Vendor;
use App\Services\People\AppPersonResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Scout\EngineManager;

/**
 * The app's Vendors tab: the same directory as the web Vendors index
 * (App\Livewire\Vendors\VendorsIndex) — search, the business-type filter,
 * and the YTD-paid figure — reshaped for a phone list instead of a sortable
 * table; plus "New Vendor" / "Edit Vendor" / "Add Existing Vendor", which
 * reuse the exact rules App\Livewire\Vendors\VendorCreate and
 * App\Livewire\Forms\VendorForm apply on the web, so a vendor created or
 * edited from the app is identical to one made there. VendorScope (applied
 * automatically on every Vendor query) already limits results to the
 * signed-in vendor's own company-tree, exactly like the web; nothing here
 * needs to re-derive that.
 */
class AppVendorDirectory
{
    public function __construct(private AppPersonResolver $people)
    {
    }


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

    /**
     * "New Vendor"'s live business-name search — VendorCreate::updatedBusinessNameText()
     * exactly: vendors matching $search already connected to $company
     * ("Your Existing Vendor(s)", with a View action) versus ones that
     * exist but aren't yet connected ("Add Existing Vendors", with an Add
     * action — see linkVendor()).
     *
     * @return array{existing: Collection<int, Vendor>, linkable: Collection<int, Vendor>}
     */
    public function businessNameMatches(Vendor $company, string $search): array
    {
        $existingVendorIds = $company->vendors->pluck('id')->all();

        $existing = Vendor::withoutGlobalScopes()
            ->orderBy('business_name')
            ->where('business_name', 'like', '%'.$search.'%')
            ->whereIn('id', $existingVendorIds)
            ->distinct()
            ->get();

        $linkable = Vendor::withoutGlobalScopes()
            ->orderBy('business_name')
            ->where('business_name', 'like', '%'.$search.'%')
            ->whereNotIn('id', $existingVendorIds)
            ->distinct()
            ->get();

        return ['existing' => $existing, 'linkable' => $linkable];
    }

    /**
     * "Create New Vendor" — App\Livewire\Forms\VendorForm::store() plus
     * VendorCreate::store()'s own attach steps: the new vendor is linked
     * into $actingUser's company directory, and (for every business type
     * but Retail) an owner is attached with role_id 1 (Admin) and
     * hourly_rate 0 — VendorCreate::userVendor()'s hardcoded defaults for a
     * brand-new vendor's owner; the web never lets this be chosen at
     * creation time (only later, adding a DIFFERENT team member through
     * the "Add Member" flow, offers a role/rate choice).
     *
     * @param  array{business_type: string, business_name: string, address?: ?string, address_2?: ?string, city?: ?string, state?: ?string, zip_code?: ?string, business_phone?: ?string, business_email?: ?string, business_website?: ?string, owner?: array{cell_phone: string, first_name?: ?string, last_name?: ?string, email?: ?string}}  $data
     */
    public function createVendor(User $actingUser, array $data): Vendor
    {
        $vendor = Vendor::create([
            'business_type' => $data['business_type'],
            'business_name' => $data['business_name'],
            'address' => $data['address'] ?? null,
            'address_2' => $data['address_2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
            'business_phone' => $data['business_phone'] ?? null,
            'business_email' => $data['business_email'] ?? null,
            'business_website' => $data['business_website'] ?? null,
            'timezone' => VendorOptions::timezoneFromState($data['state'] ?? null),
        ]);

        $actingUser->vendor->vendors()->syncWithoutDetaching([$vendor->id]);

        if ($vendor->business_type !== 'Retail' && ! empty($data['owner'])) {
            $owner = $this->people->resolveOrCreate($data['owner']);

            if (! $owner->vendors()->where('vendor_id', $vendor->id)->exists()) {
                $owner->vendors()->attach($vendor->id, [
                    'role_id' => 1,
                    'hourly_rate' => 0,
                    'start_date' => today()->format('Y-m-d'),
                ]);
            }
        }

        return $vendor;
    }

    /**
     * "Edit Vendor" — App\Livewire\Forms\VendorForm::update() exactly.
     * business_type is deliberately never written here: the web's edit
     * form keeps that radio group disabled once a vendor exists
     * (form.blade.php's `:disabled="$view_text['form_submit'] === 'edit'"`),
     * so this mirrors that by reusing $vendor's own value.
     *
     * @param  array{business_name: string, address?: ?string, address_2?: ?string, city?: ?string, state?: ?string, zip_code?: ?string, business_phone?: ?string, business_email?: ?string, business_website?: ?string}  $data
     */
    public function updateVendor(Vendor $vendor, array $data): Vendor
    {
        $vendor->update([
            'business_name' => $data['business_name'],
            'business_type' => $vendor->business_type,
            'address' => $data['address'] ?? null,
            'address_2' => $data['address_2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
            'business_phone' => $data['business_phone'] ?? null,
            'business_email' => $data['business_email'] ?? null,
            'business_website' => $data['business_website'] ?? null,
            'timezone' => VendorOptions::timezoneFromState($data['state'] ?? null),
        ]);

        return $vendor;
    }

    /**
     * "Add" on an existing, not-yet-connected vendor —
     * VendorCreate::addVendorToCompany() exactly, including its own
     * narrower-than-view gate: a company may only link a shared
     * directory/retail vendor or an unregistered sub, never another
     * tenant's own registered business.
     */
    public function linkVendor(Vendor $company, Vendor $vendor): void
    {
        abort_unless($vendor->isLinkableBy($company), 403);

        $company->vendors()->syncWithoutDetaching([$vendor->id]);
    }
}
