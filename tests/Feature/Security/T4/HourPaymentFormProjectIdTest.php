<?php

use App\Livewire\Forms\HourForm;
use App\Livewire\Forms\PaymentForm;
use App\Models\Client;
use App\Models\Hour;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Minimal hosts for HourForm/PaymentForm — the real HourCreate/PaymentCreate
 * components aren't owned by this track and carry unrelated setup; these
 * expose just the properties each Form reads off `$this->component`.
 */
class Sec4HourFormHarness extends Component
{
    public HourForm $form;

    public $selected_date;

    public function render()
    {
        return '<div></div>';
    }
}

class Sec4PaymentFormHarness extends Component
{
    public PaymentForm $form;

    public $projects = [];

    public function render()
    {
        return '<div></div>';
    }
}

function sec4_hourPaymentFixture(): array
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

    $clientB = Client::factory()->create();
    $clientB->vendors()->attach($vendorB->id);

    $foreignProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Vendor B Only',
        'client_id' => $clientB->id,
        'address' => '5 Foreign Way',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorB->id,
    ]));
    $foreignProject->vendors()->attach($vendorB->id, ['client_id' => $clientB->id]);

    $clientA = Client::factory()->create();
    $clientA->vendors()->attach($vendorA->id);
    $ownProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Vendor A Own',
        'client_id' => $clientA->id,
        'address' => '6 Own Way',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $ownProject->vendors()->attach($vendorA->id, ['client_id' => $clientA->id]);

    return compact('vendorA', 'vendorB', 'adminA', 'foreignProject', 'ownProject');
}

it('refuses logging hours against a project outside the tenant', function () {
    $fx = sec4_hourPaymentFixture();

    $component = Livewire::actingAs($fx['adminA'])->test(Sec4HourFormHarness::class)->instance();
    $component->selected_date = now()->format('Y-m-d');
    $component->form->projects = [
        ['id' => $fx['foreignProject']->id, 'hours' => 4],
    ];

    $component->form->store();

    expect(Hour::where('project_id', $fx['foreignProject']->id)->count())->toBe(0);
});

it('logs hours against the tenant own project normally', function () {
    $fx = sec4_hourPaymentFixture();

    $component = Livewire::actingAs($fx['adminA'])->test(Sec4HourFormHarness::class)->instance();
    $component->selected_date = now()->format('Y-m-d');
    $component->form->projects = [
        ['id' => $fx['ownProject']->id, 'hours' => 4],
    ];

    $component->form->store();

    expect(Hour::where('project_id', $fx['ownProject']->id)->count())->toBe(1);
});

it('refuses recording a payment against a project outside the tenant', function () {
    $fx = sec4_hourPaymentFixture();

    $component = Livewire::actingAs($fx['adminA'])->test(Sec4PaymentFormHarness::class)->instance();
    $component->projects = [
        ['id' => $fx['foreignProject']->id, 'amount' => '100.00'],
    ];
    $component->form->date = now()->format('Y-m-d');
    $component->form->invoice = 'INV-1';

    $component->form->store();

    expect(Payment::where('project_id', $fx['foreignProject']->id)->count())->toBe(0);
});

it('records a payment against the tenant own project normally', function () {
    $fx = sec4_hourPaymentFixture();

    $component = Livewire::actingAs($fx['adminA'])->test(Sec4PaymentFormHarness::class)->instance();
    $component->projects = [
        ['id' => $fx['ownProject']->id, 'amount' => '150.00'],
    ];
    $component->form->date = now()->format('Y-m-d');
    $component->form->invoice = 'INV-2';

    $component->form->store();

    expect(Payment::where('project_id', $fx['ownProject']->id)->count())->toBe(1);
});
