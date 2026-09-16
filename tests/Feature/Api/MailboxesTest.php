<?php

use App\Models\CompanyEmail;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * gs.construction asks which mailboxes to read for email enquiries. The
 * answer is the vendor's connected company emails plus the crew shared
 * inbox, and never another vendor's.
 */
function mailboxFixture(): array
{
    $vendor = Vendor::factory()->create();
    $other = Vendor::factory()->create();

    $api = new User();
    $api->forceFill([
        'first_name' => 'Site', 'last_name' => 'Bot',
        'email' => 'site-bot.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $api->save();
    $vendor->users()->attach($api->id, ['role_id' => 1]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'Patryk@gs.construction', 'grant_id' => 'grant-patryk']);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'greg@gs.construction', 'grant_id' => 'grant-greg']);
    CompanyEmail::create(['vendor_id' => $other->id, 'email' => 'someone@other.test', 'grant_id' => 'grant-other']);

    config([
        'nylas.crew_leads.mailbox' => 'crew@gs.construction',
        'nylas.crew_leads.grant_ids' => ['grant-patryk', 'grant-greg'],
        'nylas.crew_leads.vendor_id' => $vendor->id,
    ]);

    Sanctum::actingAs($api);

    return compact('vendor', 'other', 'api');
}

it('lists the shared crew inbox first, then the vendor\'s own connected mailboxes', function () {
    mailboxFixture();

    $this->getJson('/api/v1/mailboxes')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['email' => 'crew@gs.construction', 'grant_id' => 'grant-patryk', 'shared' => true],
            ['email' => 'patryk@gs.construction', 'grant_id' => 'grant-patryk', 'shared' => false],
            ['email' => 'greg@gs.construction', 'grant_id' => 'grant-greg', 'shared' => false],
        ]]);
});

it('leaves the crew inbox out for a vendor it does not belong to', function () {
    $fx = mailboxFixture();
    config(['nylas.crew_leads.vendor_id' => $fx['other']->id]);

    $emails = collect($this->getJson('/api/v1/mailboxes')->assertOk()->json('data'))->pluck('email')->all();

    expect($emails)->toBe(['patryk@gs.construction', 'greg@gs.construction']);
});

it('needs a token', function () {
    $this->getJson('/api/v1/mailboxes')->assertUnauthorized();
});
