<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A contact (homeowner) who is a client of TWO different companies — the
 * realistic shape of the leak: Lead::resolveClient() used to grab
 * user->clients()->first() unscoped, which could hand back the OTHER
 * company's client row for a lead that belongs to this one.
 */
function sec4_leadClientFixture(): array
{
    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $vendorB = Vendor::factory()->create();
    $vendorB->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $contact = User::factory()->create();

    $clientA = Client::factory()->create(['address' => '100 Vendor A St']);
    $clientA->vendors()->attach($vendorA->id);
    $clientA->users()->attach($contact->id);

    $clientB = Client::factory()->create(['address' => '200 Vendor B Ave']);
    $clientB->vendors()->attach($vendorB->id);
    $clientB->users()->attach($contact->id);

    $leadForA = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Contact', 'address' => 'unrelated address, nowhere'],
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendorA->id,
        'created_by_user_id' => $contact->id,
    ]));

    return compact('vendorA', 'vendorB', 'contact', 'clientA', 'clientB', 'leadForA');
}

it('never resolves a lead to another tenant client sharing the same contact', function () {
    $fx = sec4_leadClientFixture();

    $resolved = $fx['leadForA']->resolveClient();

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($fx['clientA']->id)
        ->and($resolved->id)->not->toBe($fx['clientB']->id);
});

it('resolves to null rather than a foreign client when the contact has no client on this tenant', function () {
    $fx = sec4_leadClientFixture();

    // Detach the tenant-A client link — only the OTHER tenant's client
    // remains for this contact.
    $fx['clientA']->users()->detach($fx['contact']->id);
    $fx['clientA']->delete();

    $resolved = $fx['leadForA']->resolveClient();

    expect($resolved)->toBeNull();
});
