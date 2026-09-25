<?php

/**
 * Shared fixtures for track T3's security tests (Estimates, Bids, Clients,
 * Line Items, Distributions, and the client-user scope root cause).
 * require_once this file rather than declaring these names twice.
 */

use App\Models\Bid;
use App\Models\Client;
use App\Models\Distribution;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;

if (! function_exists('sec3_makeVendor')) {
    function sec3_makeVendor(string $name = 'Sec3 Co'): Vendor
    {
        return Vendor::factory()->create(['business_name' => $name.' '.uniqid()]);
    }

    /** An Admin (role_id 1) employed by the vendor, with it as their primary. */
    function sec3_makeAdmin(Vendor $vendor, string $emailPrefix = 'admin'): User
    {
        $user = new User();
        $user->forceFill([
            'first_name' => 'Sec3',
            'last_name' => 'Admin',
            'email' => $emailPrefix.'.'.uniqid().'@example.test',
            'cell_phone' => fake()->unique()->numerify('224777####'),
            'primary_vendor_id' => $vendor->id,
            'registration' => ['registered' => true],
        ]);
        $user->save();
        $vendor->users()->attach($user->id, ['role_id' => 1, 'is_employed' => 1]);

        return $user->refresh();
    }

    /** A Member (role_id 2) employed by the vendor, with it as their primary. */
    function sec3_makeMember(Vendor $vendor, string $emailPrefix = 'member'): User
    {
        $user = new User();
        $user->forceFill([
            'first_name' => 'Sec3',
            'last_name' => 'Member',
            'email' => $emailPrefix.'.'.uniqid().'@example.test',
            'cell_phone' => fake()->unique()->numerify('224778####'),
            'primary_vendor_id' => $vendor->id,
            'registration' => ['registered' => true],
        ]);
        $user->save();
        $vendor->users()->attach($user->id, ['role_id' => 2, 'is_employed' => 1]);

        return $user->refresh();
    }

    /** A homeowner: no vendor at all, linked only to $client. */
    function sec3_makeClientUser(Client $client, string $emailPrefix = 'owner'): User
    {
        $user = new User();
        $user->forceFill([
            'first_name' => 'Home',
            'last_name' => 'Owner',
            'email' => $emailPrefix.'.'.uniqid().'@example.test',
            'cell_phone' => fake()->unique()->numerify('224779####'),
            'registration' => ['registered' => true],
        ]);
        $user->save();
        $user->clients()->attach($client->id);

        return $user->refresh();
    }

    /** A client record, optionally linked to a vendor via the client_vendor pivot. */
    function sec3_makeClient(?Vendor $vendor = null): Client
    {
        $client = Client::factory()->create();

        if ($vendor) {
            $client->vendors()->attach($vendor->id);
        }

        return $client;
    }

    /** A project owned by $vendor, for $client, linked both ways (column + pivot). */
    function sec3_makeProject(Vendor $vendor, Client $client, array $overrides = []): Project
    {
        $project = Project::withoutEvents(fn () => Project::create(array_merge([
            'project_name' => 'Sec3 Project '.uniqid(),
            'client_id' => $client->id,
            'belongs_to_vendor_id' => $vendor->id,
            'address' => '1 Test St',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60601',
        ], $overrides)));

        $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

        return $project;
    }

    function sec3_makeEstimate(Project $project, Vendor $vendor, array $overrides = []): Estimate
    {
        return Estimate::withoutGlobalScopes()->create(array_merge([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $vendor->id,
        ], $overrides));
    }

    function sec3_makeSection(Estimate $estimate, array $overrides = []): EstimateSection
    {
        return EstimateSection::create(array_merge([
            'estimate_id' => $estimate->id,
            'name' => 'Sec3 Section',
            'total' => 0,
        ], $overrides));
    }

    function sec3_makeBid(Project $project, Vendor $vendor, array $overrides = []): Bid
    {
        return Bid::withoutGlobalScopes()->create(array_merge([
            'project_id' => $project->id,
            'vendor_id' => $vendor->id,
            'amount' => 1000,
            'type' => 1,
        ], $overrides));
    }

    function sec3_makeDistribution(Vendor $vendor, array $overrides = []): Distribution
    {
        return Distribution::withoutGlobalScopes()->create(array_merge([
            'vendor_id' => $vendor->id,
            'name' => 'Sec3 Distribution '.uniqid(),
        ], $overrides));
    }

    /**
     * A catalog LineItem for $vendor. Bypasses LineItemObserver (which
     * stamps belongs_to_vendor_id from the CURRENT auth user, possibly not
     * set up yet at fixture time) — the vendor id is set explicitly instead.
     */
    function sec3_makeCatalogItem(Vendor $vendor, array $overrides = []): \App\Models\LineItem
    {
        return \App\Models\LineItem::withoutEvents(fn () => \App\Models\LineItem::create(array_merge([
            'name' => 'Sec3 Catalog '.uniqid(),
            'category' => 'Category',
            'unit_type' => 'each',
            'cost' => 50,
            'belongs_to_vendor_id' => $vendor->id,
        ], $overrides)));
    }

    /** An EstimateLineItem row in $section, backed by its own catalog item. */
    function sec3_makeEstimateLineItem(Estimate $estimate, EstimateSection $section, array $overrides = []): \App\Models\EstimateLineItem
    {
        $catalogItem = sec3_makeCatalogItem(
            \App\Models\Vendor::withoutGlobalScopes()->findOrFail($estimate->belongs_to_vendor_id)
        );

        return \App\Models\EstimateLineItem::create(array_merge([
            'estimate_id' => $estimate->id,
            'section_id' => $section->id,
            'line_item_id' => $catalogItem->id,
            'name' => 'Sec3 Line',
            'category' => 'Category',
            'sub_category' => 'Sub',
            'unit_type' => 'each',
            'quantity' => 1,
            'cost' => 100,
            'total' => 100,
        ], $overrides));
    }
}
