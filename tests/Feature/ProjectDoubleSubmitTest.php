<?php

use App\Livewire\Projects\ProjectCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('does not mint a twin project when Create is double-submitted', function () {
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Patryk', 'last_name' => 'Szady',
        'email' => 'dbl.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $fill = fn () => Livewire::actingAs($user)
        ->test(ProjectCreate::class)
        ->set('form.client_id', $client->id)
        ->set('form.project_name', 'Basement Stairs')
        ->set('form.project_existing_address', 'NEW')
        ->set('address_1', '511 Sherwood Dr')
        ->set('city', 'Addison')
        ->set('state', 'IL')
        ->set('zip_code', '60101');

    // The exact production shape: the same Create submitted twice, seconds
    // apart (slow redirect, second click). Two separate requests.
    $fill()->call('save');
    $fill()->call('save');

    expect(Project::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(1);

    // A deliberate same-name project much later is still allowed.
    Project::withoutGlobalScopes()->where('client_id', $client->id)->first()
        ->forceFill(['created_at' => now()->subHour()])->save();

    $fill()->call('save');

    expect(Project::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(2);
});
