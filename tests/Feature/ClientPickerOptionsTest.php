<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * /clients/picker-options feeds every <x-client-picker> with the whole client
 * list once, filtered in the browser — the /projects filter rendered all
 * 335 clients as Flux options in both its layouts, 866 KB of a 1 MB page,
 * on every status-card click (2026-11-06). Scoped like the Client model
 * (ClientScope), newest first like the select was, answered with an ETag.
 */
function clientPickerActor(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling, Inc']);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'client-picker-'.uniqid().'@example.test',
        'cell_phone' => '2245550007', 'password' => null, 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);

    $older = Client::factory()->create(['business_name' => null, 'created_at' => now()->subDays(2)]);
    $older->vendors()->attach($company->id);
    $mark = User::query()->create(['first_name' => 'Mark', 'last_name' => 'Brodson', 'email' => 'mark-'.uniqid().'@example.test', 'cell_phone' => '2245550008', 'password' => null]);
    $older->users()->attach($mark->id);

    $newer = Client::factory()->create(['business_name' => 'Abbey Kitchen LLC', 'created_at' => now()->subDay()]);
    $newer->vendors()->attach($company->id);

    $otherCompany = Vendor::factory()->create(['business_name' => 'Somebody Else Builders']);
    $hidden = Client::factory()->create(['business_name' => 'Not Our Client']);
    $hidden->vendors()->attach($otherCompany->id);

    return [$user, $company, $older, $newer, $hidden];
}

it('serves the company\'s clients only, newest first, with the names they are found by', function (): void {
    [$user, , $older, $newer, $hidden] = clientPickerActor();

    $response = $this->actingAs($user)->getJson(route('clients.picker-options'))->assertOk();
    $items = collect($response->json('items'));

    expect($items->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($items->pluck('id')->all())->not->toContain($hidden->id)
        ->and($items->firstWhere('id', $older->id)['name'])->toBe($older->fresh()->name)
        ->and($items->firstWhere('id', $older->id)['legal'])->toContain('Mark Brodson')
        ->and($items->firstWhere('id', $newer->id)['legal'])->toContain((string) $newer->fresh()->business_name)
        ->and($items->first())->toHaveKeys(['id', 'name', 'legal', 'city', 'state', 'type'])
        ->and($response->headers->get('ETag'))->not->toBeEmpty()
        ->and($response->json('version'))->not->toBeEmpty();
});

it('answers 304 to a matching ETag and changes it when a client changes', function (): void {
    [$user, $company] = clientPickerActor();

    $first = $this->actingAs($user)->getJson(route('clients.picker-options'))->assertOk();
    $etag = $first->headers->get('ETag');

    $this->actingAs($user)->getJson(route('clients.picker-options'), ['If-None-Match' => $etag])->assertStatus(304);

    $this->travel(2)->seconds();
    $added = Client::factory()->create(['business_name' => 'New Homeowner']);
    $added->vendors()->attach($company->id);

    $again = $this->actingAs($user)->getJson(route('clients.picker-options'), ['If-None-Match' => $etag])->assertOk();
    expect($again->headers->get('ETag'))->not->toBe($etag)
        ->and(collect($again->json('items'))->pluck('id')->all())->toContain($added->id);
});

it('needs a signed-in user', function (): void {
    $this->getJson(route('clients.picker-options'))->assertUnauthorized();
});
