<?php

use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * /vendors/picker-options feeds every <x-vendor-picker> on the site with the
 * whole vendor list once, filtered in the browser (Patryk, 2026-10-11: "load
 * the whole list"). Scoped like the Vendor model, cached until a vendor
 * changes, and answered with an ETag.
 */
function pickerActor(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling, Inc']);
    // The vendors routes sit behind the `registered` middleware.
    $company->forceFill(['registration' => ['registered' => true]])->save();
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'picker-'.uniqid().'@example.test',
        'cell_phone' => '2245550001', 'password' => null, 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);

    $visible = Vendor::factory()->create(['business_name' => 'Menards, Inc', 'city' => 'Mount Prospect', 'state' => 'IL', 'business_type' => 'Retail']);
    $company->vendors()->attach([$visible->id]);
    $hidden = Vendor::factory()->create(['business_name' => 'Somebody Elses Vendor LLC']);

    return [$user, $company, $visible, $hidden];
}

it('serves the vendors the company can see, with display and legal names, sorted', function (): void {
    [$user, $company, $visible, $hidden] = pickerActor();

    $response = $this->actingAs($user)->getJson(route('vendors.picker-options'))->assertOk();

    $vendors = collect($response->json('vendors'));
    expect($vendors->pluck('id')->all())->toContain($visible->id, $company->id)
        ->and($vendors->pluck('id')->all())->not->toContain($hidden->id)
        ->and($vendors->firstWhere('id', $visible->id))->toMatchArray(['name' => 'Menards', 'legal' => 'Menards, Inc', 'city' => 'Mount Prospect', 'state' => 'IL', 'type' => 'Retail'])
        ->and($vendors->pluck('legal')->all())->toBe($vendors->pluck('legal')->sort()->values()->all())
        ->and($response->headers->get('ETag'))->not->toBeEmpty()
        ->and($response->json('version'))->not->toBeEmpty();
});

it('lets an admin ask for every vendor, and ignores that for anyone else', function (): void {
    [$user, , , $hidden] = pickerActor();

    $all = $this->actingAs($user)->getJson(route('vendors.picker-options', ['all' => 1]))->assertOk();
    expect(collect($all->json('vendors'))->pluck('id')->all())->toContain($hidden->id);
});

it('answers 304 to a matching ETag and changes it when a vendor changes', function (): void {
    [$user, , $visible] = pickerActor();

    $first = $this->actingAs($user)->getJson(route('vendors.picker-options'))->assertOk();
    $etag = $first->headers->get('ETag');

    $this->actingAs($user)->getJson(route('vendors.picker-options'), ['If-None-Match' => $etag])->assertStatus(304);

    $this->travel(1)->minutes();
    $visible->update(['business_name' => 'Menards Renamed, Inc']);

    $second = $this->actingAs($user)->getJson(route('vendors.picker-options'), ['If-None-Match' => $etag])->assertOk();
    expect($second->headers->get('ETag'))->not->toBe($etag)
        ->and(collect($second->json('vendors'))->firstWhere('id', $visible->id)['name'])->toBe('Menards Renamed');
});

it('narrows to the vendors billed to a project when asked', function (): void {
    [$user, $company, $visible] = pickerActor();
    $billed = Vendor::factory()->create(['business_name' => 'Billed Here LLC']);
    $company->vendors()->attach([$billed->id]);
    Expense::create(['amount' => 120, 'date' => '2026-10-01', 'project_id' => 4321, 'distribution_id' => null, 'created_by_user_id' => $user->id, 'belongs_to_vendor_id' => $company->id, 'vendor_id' => $billed->id, 'note' => null, 'invoice' => null]);

    $ids = collect($this->actingAs($user)->getJson(route('vendors.picker-options', ['project' => 4321]))->assertOk()->json('vendors'))->pluck('id')->all();

    expect($ids)->toBe([$billed->id]);
});

it('requires a signed-in user', function (): void {
    $this->getJson(route('vendors.picker-options'))->assertUnauthorized();
});
