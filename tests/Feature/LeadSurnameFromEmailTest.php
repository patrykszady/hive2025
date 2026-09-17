<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\LeadContactProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function surnameLead(array $data, ?Vendor $vendor = null): Lead
{
    $vendor ??= Vendor::factory()->create();
    $creator = User::query()->create([
        'first_name' => 'Site', 'last_name' => 'Form',
        'email' => 'surname.creator.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224888####'),
    ]);
    $lead = Lead::create([
        'date' => now(), 'origin' => 'gs.construction', 'external_source' => 'gs.construction',
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $creator->id, 'lead_data' => $data,
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return $lead;
}

it('reads the surname out of an email address only when the address plainly gives it', function (string $first, string $email, ?string $expected) {
    expect(app(LeadContactProvisioner::class)->surnameFromEmail($first, $email))->toBe($expected);
})->with([
    'first.last' => ['Valina', 'valina.markhay@gmail.com', 'Markhay'],
    'last.first' => ['Valina', 'markhay.valina@gmail.com', 'Markhay'],
    'underscore and digits' => ['Valina', 'valina_markhay77@yahoo.com', 'Markhay'],
    'hyphen' => ['valina', 'Valina-Markhay@outlook.com', 'Markhay'],
    'initial' => ['Valina', 'v.markhay@gmail.com', 'Markhay'],
    'mailbox word ignored' => ['Valina', 'valina.markhay.home@gmail.com', 'Markhay'],
    'joined to a long first name' => ['Katherine', 'katherinebrown521@gmail.com', 'Brown'],
    'joined, surname first' => ['Katherine', 'brownkatherine@gmail.com', 'Brown'],
    'joined to a short first name says nothing' => ['Will', 'willjohn1089@example.com', null],
    'joined, rest too short' => ['Katherine', 'katherineb@gmail.com', null],
    'joined, rest is a mailbox word' => ['Katherine', 'katherinehome@gmail.com', null],
    'joined, first name not at either end' => ['Katherine', 'mrskatherinebrown@gmail.com', null],
    'first name alone' => ['Valina', 'valina1985@gmail.com', null],
    'first name not in the address' => ['Valina', 'the.markhays@gmail.com', null],
    'two candidates is a guess' => ['Valina', 'valina.markhay.smith@gmail.com', null],
    'only mailbox words' => ['Valina', 'valina.info@gmail.com', null],
    'no email' => ['Valina', '', null],
]);

it('provisions a first-name-only website lead as the person their email names, and files the lead under it', function () {
    $lead = surnameLead([
        'name' => 'Valina', 'email' => 'valina.markhay@gmail.com', 'phone' => '2242415537',
        'address' => '3119 Old Glenview Road', 'city' => 'Wilmette', 'state' => 'IL', 'zip' => '60091',
        'message' => 'Leak in the master bathroom.',
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    $fresh = $lead->fresh();
    $user = User::find($fresh->user_id);
    expect($user)->not->toBeNull()
        ->and($user->first_name)->toBe('Valina')
        ->and($user->last_name)->toBe('Markhay')
        ->and($user->email)->toBe('valina.markhay@gmail.com')
        ->and($fresh->lead_data['name'])->toBe('Valina Markhay')
        ->and($user->clients()->withoutGlobalScopes()->where('address', 'like', '3119 Old Glenview%')->exists())->toBeTrue();
});

it('never invents a surname from an address that does not spell one out, and never overrides a stated one', function () {
    $will = surnameLead(['name' => 'Will', 'email' => 'willjohn1089@example.com', 'phone' => '8322571204', 'address' => '7815 Kenton Ave', 'message' => 'Roof']);
    $rob = surnameLead(['name' => 'Rob Rothbaum', 'email' => 'rob.smith@example.com', 'phone' => '8322571205', 'address' => '960 Danielson Ct', 'message' => 'Basement']);

    $provisioner = app(LeadContactProvisioner::class);
    $provisioner->provision($will);
    $provisioner->provision($rob);

    expect($will->fresh()->user_id)->toBeNull()
        ->and($will->fresh()->lead_data['name'])->toBe('Will')
        ->and(User::find($rob->fresh()->user_id)?->last_name)->toBe('Rothbaum');
});

it('takes the surname the message signs with before the one the address hints at', function () {
    $lead = surnameLead([
        'name' => 'Katherine', 'email' => 'kb2020@gmail.com', 'phone' => '8474041401',
        'address' => '1801 Elm St', 'city' => 'Park Ridge', 'state' => 'IL', 'zip' => '60068',
        'message' => "Hi! Looking to remodel our master bath.\n\nThanks,\nKatherine Brown",
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    $fresh = $lead->fresh();
    expect(User::find($fresh->user_id)?->last_name)->toBe('Brown')
        ->and($fresh->lead_data['name'])->toBe('Katherine Brown');
});

it('adds a user from the lead modal with the surname the email gives', function () {
    $lead = surnameLead(['name' => 'Valina', 'email' => 'valina.markhay@gmail.com', 'phone' => null, 'message' => 'Leak']);

    $user = app(LeadContactProvisioner::class)->createContactFor($lead, 'Valina', 'valina.markhay@gmail.com', null);

    expect($user->last_name)->toBe('Markhay')
        ->and($lead->fresh()->user_id)->toBe($user->id);
});

it('catches up the leads already here, previewing first and writing only with --apply', function () {
    $vendor = Vendor::factory()->create();
    $valina = surnameLead(['name' => 'Valina', 'email' => 'valina.markhay@gmail.com', 'phone' => '2242415537', 'address' => '3119 Old Glenview Road', 'city' => 'Wilmette', 'state' => 'IL', 'zip' => '60091', 'message' => 'Leak'], $vendor);
    $will = surnameLead(['name' => 'Will', 'email' => 'willjohn1089@example.com', 'phone' => '8322571204', 'message' => 'Roof'], $vendor);
    $noPhone = surnameLead(['name' => 'Dana', 'email' => 'dana.kowalski@example.com', 'message' => 'Deck'], $vendor);

    $this->artisan('leads:complete-names')
        ->expectsOutputToContain("lead {$valina->id}: Valina → Valina Markhay")
        ->expectsOutputToContain('2 leads would be completed.')
        ->assertSuccessful();
    expect($valina->fresh()->user_id)->toBeNull();

    $this->artisan('leads:complete-names', ['--apply' => true])
        ->expectsOutputToContain('2 leads completed, 1 linked.')
        ->assertSuccessful();

    expect(User::find($valina->fresh()->user_id)?->last_name)->toBe('Markhay')
        ->and($will->fresh()->user_id)->toBeNull()
        // A surname without a phone is still not a whole contact, but the name is right now.
        ->and($noPhone->fresh()->user_id)->toBeNull()
        ->and($noPhone->fresh()->lead_data['name'])->toBe('Dana Kowalski')
        ->and(Client::withoutGlobalScopes()->where('address', 'like', '3119 Old Glenview%')->count())->toBe(1);

    // Running again finds Valina linked and Dana's name already complete, and touches nothing.
    $this->artisan('leads:complete-names', ['--apply' => true])
        ->expectsOutputToContain('0 leads completed, 0 linked.')
        ->assertSuccessful();
    expect(Client::withoutGlobalScopes()->where('address', 'like', '3119 Old Glenview%')->count())->toBe(1);
});
