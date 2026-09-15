<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function intakeFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $api = new User();
    $api->forceFill([
        'first_name' => 'Site', 'last_name' => 'Bot',
        'email' => 'site-bot.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $api->save();
    $vendor->users()->attach($api->id, ['role_id' => 1]);

    // A returning client: contact + client on file.
    $jeanne = User::query()->create([
        'first_name' => 'Jeanne', 'last_name' => 'Bondi',
        'email' => 'jcbondi2@example.test', 'cell_phone' => '8478285566',
    ]);
    $client = Client::factory()->create(['address' => '962 W Peregrine Dr', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => 60067]);
    $client->users()->attach($jeanne->id);
    $client->vendors()->attach($vendor->id);

    Sanctum::actingAs($api);

    return compact('vendor', 'api', 'jeanne', 'client');
}

it('links a known contact\'s enquiry to their record without asking the classifier', function () {
    $fx = intakeFixture();

    // The model would have called this casual note a solicitation — it must not even be asked.
    $this->mock(CrewLeadEmailService::class, fn ($mock) => $mock->shouldNotReceive('classify'));

    $this->postJson('/api/v1/leads', [
        'external_id' => '167',
        'source' => 'gs.construction',
        'name' => 'Jeanne Bondi',
        'email' => 'jcbondi2@example.test',
        'phone' => '8478285566',
        'address' => '962 W Peregrine Dr',
        'city' => 'Palatine',
        'message' => 'Patryk / Greg can we set up time week of 9/30 for master bedroom project? Made selections with the designer and would love to get going, thanks so much for everything.',
    ])->assertCreated();

    $lead = Lead::withoutGlobalScopes()->where('external_id', '167')->firstOrFail();

    expect($lead->user_id)->toBe($fx['jeanne']->id)
        ->and($lead->resolveClient()?->id)->toBe($fx['client']->id);
});

it('still triages a stranger\'s long message', function () {
    intakeFixture();

    $this->mock(CrewLeadEmailService::class, fn ($mock) => $mock->shouldReceive('classify')->once()->andReturn([
        'is_lead' => false, 'confidence' => 0.95, 'reason' => 'SEO agency pitch', 'extraction_status' => 'ok', 'fields' => [],
    ]));

    $this->postJson('/api/v1/leads', [
        'external_id' => '168',
        'source' => 'gs.construction',
        'name' => 'Sam Seller',
        'email' => 'sam@agency.example.test',
        'message' => 'We help contractors like you rank on page one of Google with our proven SEO packages, book a free strategy call today.',
    ])->assertCreated();

    expect(Lead::withoutGlobalScopes()->where('external_id', '168')->firstOrFail()->user_id)->toBeNull();
});
