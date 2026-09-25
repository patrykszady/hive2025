<?php

use App\Livewire\Payments\PaymentsIndex;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec4_paymentsFixture(): array
{
    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $vendorB = Vendor::factory()->create();
    $vendorB->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $adminA = User::factory()->create();
    $adminA->primary_vendor_id = $vendorA->id;
    $adminA->registration = ['registered' => true];
    $adminA->save();
    $vendorA->users()->attach($adminA->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendorB->id);

    $foreignProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Vendor B Only Project',
        'client_id' => $client->id,
        'address' => '9 Foreign Rd',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorB->id,
    ]));
    $foreignProject->vendors()->attach($vendorB->id, ['client_id' => $client->id]);

    // Vendor B's own payment ledger, on a project vendor A has nothing to do
    // with — vendor A must never see this via the filter.
    $foreignPayment = Payment::create([
        'amount' => 9999,
        'date' => now()->toDateString(),
        'project_id' => $foreignProject->id,
        'reference' => 'Vendor B private payment',
        'belongs_to_vendor_id' => $vendorB->id,
        'created_by_user_id' => $adminA->id,
    ]);

    // Vendor A's OWN project, with a real sub payment on it — the
    // legitimate use vendor_filter exists for (see PaymentsIndexTest).
    $ownClient = Client::factory()->create();
    $ownClient->vendors()->attach($vendorA->id);
    $ownProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Vendor A Own Project',
        'client_id' => $ownClient->id,
        'address' => '1 Own Rd',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $ownProject->vendors()->attach($vendorA->id, ['client_id' => $ownClient->id]);

    $subPayment = Payment::withoutGlobalScopes()->create([
        'amount' => 500,
        'date' => now()->toDateString(),
        'project_id' => $ownProject->id,
        'reference' => "Vendor B's own record on A's project",
        'belongs_to_vendor_id' => $vendorB->id,
        'created_by_user_id' => $adminA->id,
    ]);

    return compact('vendorA', 'vendorB', 'adminA', 'foreignProject', 'foreignPayment', 'ownProject', 'subPayment');
}

it('refuses setting vendor_filter from the browser', function () {
    $fx = sec4_paymentsFixture();

    expect(fn () => Livewire::actingAs($fx['adminA'])
        ->test(PaymentsIndex::class)
        ->set('vendor_filter', $fx['vendorB']->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('confines vendor_filter to the current project even when asking for an unrelated vendor', function () {
    $fx = sec4_paymentsFixture();

    // vendor_filter is #[Locked] so the browser can't set it, but this
    // confirms the query itself stays confined to $this->project even if
    // something server-side asks for a vendor's ledger — vendor B has real
    // money (foreignPayment) on a DIFFERENT project, which must never surface
    // here just because vendor B's id was requested.
    $component = Livewire::actingAs($fx['adminA'])->test(PaymentsIndex::class)->instance();
    $component->project = $fx['ownProject'];
    $component->view = 'projects.show';

    $reflection = new ReflectionProperty($component, 'vendor_filter');
    $reflection->setAccessible(true);
    $reflection->setValue($component, $fx['vendorB']->id);

    $payments = $component->payments();

    expect($payments->pluck('id'))
        ->not->toContain($fx['foreignPayment']->id)
        ->toContain($fx['subPayment']->id);
});

it('refuses an unlisted sort column and keeps the whitelist working', function () {
    $fx = sec4_paymentsFixture();

    // Called directly on the mounted instance — payments/index.blade.php has
    // an unrelated pre-existing nested-component tag bug (reproduced with
    // none of this track's changes) that throws on ANY second render via
    // Livewire::test()->call(), the same class of issue worked around
    // elsewhere in this suite (see ExpenseOwnershipTest).
    $component = Livewire::actingAs($fx['adminA'])->test(PaymentsIndex::class)->instance();

    $component->sort('belongs_to_vendor_id'); // not on the whitelist
    expect($component->sortBy)->toBe('date');

    $component->sort('amount');
    expect($component->sortBy)->toBe('amount');
});
