<?php

use App\Livewire\Payments\PaymentsIndex;
use App\Models\Check;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters payments by vendor when vendor_filter is set', function () {
    $gc = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'gc@example.test',
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $pmg = Vendor::query()->create([
        'business_name' => 'PMG Carpentry Inc',
        'business_type' => 'Sub',
        'business_email' => 'pmg@example.test',
        'address' => '456 Oak Ave',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $electrical = Vendor::query()->create([
        'business_name' => 'Electrical Experts',
        'business_type' => 'Sub',
        'business_email' => 'elec@example.test',
        'address' => '789 Pine St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $user = User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'payments-test-' . uniqid() . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'primary_vendor_id' => $gc->id,
    ]);

    $this->actingAs($user);

    $client = Client::create([
        'business_name' => 'Project Owner',
        'address' => '3154 Violet',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
    ]);

    $project = Project::create([
        'project_name' => 'Multi-Vendor Job',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $gc->id,
        'address' => '3154 Violet',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
    ]);

    // Create checks and payments for PMG
    $check1 = Check::create([
        'check_type' => 'Check',
        'check_number' => 1001,
        'date' => now()->toDateString(),
        'vendor_id' => $pmg->id,
        'belongs_to_vendor_id' => $gc->id,
        'created_by_user_id' => $user->id,
    ]);

    $pmg_payment1 = Payment::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'amount' => 60000,
        'date' => '2026-07-09',
        'reference' => 'PMG Payment 1',
        'check_id' => $check1->id,
        'created_by_user_id' => $user->id,
    ]);

    $check2 = Check::create([
        'check_type' => 'Check',
        'check_number' => 1002,
        'date' => now()->toDateString(),
        'vendor_id' => $pmg->id,
        'belongs_to_vendor_id' => $gc->id,
        'created_by_user_id' => $user->id,
    ]);

    $pmg_payment2 = Payment::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'amount' => 30000,
        'date' => '2026-06-15',
        'reference' => 'PMG Payment 2',
        'check_id' => $check2->id,
        'created_by_user_id' => $user->id,
    ]);

    // Create checks and payments for Electrical Experts
    $check3 = Check::create([
        'check_type' => 'Check',
        'check_number' => 2001,
        'date' => now()->toDateString(),
        'vendor_id' => $electrical->id,
        'belongs_to_vendor_id' => $gc->id,
        'created_by_user_id' => $user->id,
    ]);

    $elec_payment = Payment::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'amount' => 40000,
        'date' => '2026-06-01',
        'reference' => 'Electrical Payment',
        'check_id' => $check3->id,
        'created_by_user_id' => $user->id,
    ]);

    // A homeowner payment to the GC: no check, recorded under the GC. This is
    // exactly what must NOT show up when a sub vendor is selected.
    $homeowner_payment = Payment::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'amount' => 60000,
        'date' => '2026-06-01',
        'reference' => 'TRU060126ST',
        'created_by_user_id' => $user->id,
    ]);

    // A payment recorded under the sub's own vendor (PaymentScope would
    // normally hide it from the GC user).
    $sub_recorded_payment = Payment::withoutGlobalScopes()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $pmg->id,
        'amount' => 5000,
        'date' => '2026-05-01',
        'reference' => 'PMG Own Record',
        'created_by_user_id' => $user->id,
    ]);

    // Test 1: Without vendor filter, the GC sees their own recorded payments
    $component = new PaymentsIndex();
    $component->project = $project;
    $component->view = 'projects.show';
    $component->vendor_filter = null;

    $allPayments = $component->payments();
    expect($allPayments->pluck('id')->toArray())
        ->toContain($pmg_payment1->id, $pmg_payment2->id, $elec_payment->id, $homeowner_payment->id);

    // Test 2: With vendor filter for PMG, only PMG's payments are shown —
    // check-paid AND sub-recorded, but never the homeowner's payment to the GC
    $component = new PaymentsIndex();
    $component->project = $project;
    $component->view = 'projects.show';
    $component->vendor_filter = $pmg->id;

    $pmgPayments = $component->payments();
    expect($pmgPayments->pluck('id')->toArray())
        ->toContain($pmg_payment1->id, $pmg_payment2->id, $sub_recorded_payment->id)
        ->not->toContain($elec_payment->id, $homeowner_payment->id);

    // Test 3: With vendor filter for Electrical, only Electrical's payment is shown
    $component = new PaymentsIndex();
    $component->project = $project;
    $component->view = 'projects.show';
    $component->vendor_filter = $electrical->id;

    $elecPayments = $component->payments();
    expect($elecPayments->count())->toBe(1)
        ->and($elecPayments->first()->id)->toBe($elec_payment->id);
});
