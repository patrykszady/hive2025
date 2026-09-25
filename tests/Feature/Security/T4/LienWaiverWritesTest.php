<?php

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Livewire\LienWaivers\Index;
use App\Models\Client;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Vendor A (the GC) issues a lien waiver to vendor B (a sub). LienWaiverScope
 * shows the SAME waiver row to both parties (issuer and recipient), so a
 * naive `LienWaiver::find()` lets the recipient act on a row it only received.
 */
function sec4_lienWaiverFixture(): array
{
    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $vendorB = Vendor::factory()->create();
    $vendorB->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $makeAdmin = function (Vendor $vendor) {
        $user = User::factory()->create();
        $user->primary_vendor_id = $vendor->id;
        $user->registration = ['registered' => true];
        $user->save();
        $vendor->users()->attach($user->id, ['role_id' => 1]);

        return $user;
    };

    $adminA = $makeAdmin($vendorA);
    $adminB = $makeAdmin($vendorB);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendorA->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Sec4 Lien Waiver Project',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $project->vendors()->attach($vendorA->id, ['client_id' => $client->id]);

    $waiver = LienWaiver::create([
        'belongs_to_vendor_id' => $vendorA->id,
        'vendor_id' => $vendorB->id,
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress,
        'status' => LienWaiverStatus::Draft,
        'amount' => 500,
        'through_date' => now()->toDateString(),
    ]);

    return compact('vendorA', 'vendorB', 'adminA', 'adminB', 'client', 'project', 'waiver');
}

it('refuses the recipient sub cancelling a waiver it only received', function () {
    $fx = sec4_lienWaiverFixture();

    Livewire::actingAs($fx['adminB'])
        ->test(Index::class)
        ->call('cancel', $fx['waiver']->id);

    expect($fx['waiver']->fresh()->status)->toBe(LienWaiverStatus::Draft);
});

it('refuses the recipient sub deleting a waiver it only received', function () {
    $fx = sec4_lienWaiverFixture();

    Livewire::actingAs($fx['adminB'])
        ->test(Index::class)
        ->call('delete', $fx['waiver']->id);

    expect($fx['waiver']->fresh()->trashed())->toBeFalse();
});

it('refuses the recipient sub resending a waiver it only received', function () {
    $fx = sec4_lienWaiverFixture();
    Queue::fake();

    Livewire::actingAs($fx['adminB'])
        ->test(Index::class)
        ->call('sendForSignature', $fx['waiver']->id);

    Queue::assertNothingPushed();
    expect($fx['waiver']->fresh()->status)->toBe(LienWaiverStatus::Draft);
});

it('lets the issuing vendor cancel, delete and resend its own waiver', function () {
    $fx = sec4_lienWaiverFixture();
    Queue::fake();

    Livewire::actingAs($fx['adminA'])
        ->test(Index::class)
        ->call('sendForSignature', $fx['waiver']->id);
    Queue::assertPushed(SendLienWaiverSigningRequestJob::class);

    Livewire::actingAs($fx['adminA'])
        ->test(Index::class)
        ->call('cancel', $fx['waiver']->id);
    expect($fx['waiver']->fresh()->status)->toBe(LienWaiverStatus::Cancelled);

    $second = LienWaiver::create([
        'belongs_to_vendor_id' => $fx['vendorA']->id,
        'vendor_id' => $fx['vendorB']->id,
        'project_id' => $fx['project']->id,
        'type' => LienWaiverType::ConditionalProgress,
        'status' => LienWaiverStatus::Draft,
        'amount' => 250,
        'through_date' => now()->toDateString(),
    ]);

    Livewire::actingAs($fx['adminA'])
        ->test(Index::class)
        ->call('delete', $second->id);
    expect($second->fresh()->trashed())->toBeTrue();
});
