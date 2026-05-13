<?php

use App\Enums\LienWaiverStatus;
use App\Livewire\LienWaivers\Show as LienWaiverShow;
use App\Models\Check;
use App\Models\LienWaiver;
use App\Models\LienWaiverSignature;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Support\LienWaiverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('files');

    $this->contractor = Vendor::factory()->create();
    $this->subVendor = Vendor::factory()->create();

    $this->user = User::create([
        'first_name' => 'Test',
        'last_name' => 'Owner',
        'email' => 'owner-' . uniqid() . '@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $this->contractor->id,
    ]);

    $this->actingAs($this->user);

    $this->project = Project::create([
        'project_name' => 'Test Project',
        'client_id' => 1,
        'address' => '123 Main St',
        'city' => 'Sacramento',
        'state' => 'CA',
        'zip_code' => 95814,
    ]);

    $check = Check::create([
        'check_type' => 'Check',
        'date' => now()->toDateString(),
        'vendor_id' => $this->subVendor->id,
        'belongs_to_vendor_id' => $this->contractor->id,
        'created_by_user_id' => $this->user->id,
    ]);

    $this->payment = Payment::create([
        'project_id' => $this->project->id,
        'amount' => 1234.56,
        'date' => now()->toDateString(),
        'belongs_to_vendor_id' => $this->contractor->id,
        'check_id' => $check->id,
        'created_by_user_id' => $this->user->id,
    ]);

    LienWaiverFactory::fromPayment($this->payment->fresh());

    $this->waiver = LienWaiver::query()->where('payment_id', $this->payment->id)->firstOrFail();

    // Sub-vendor user (the signer/recipient)
    $this->subVendorUser = User::create([
        'first_name' => 'Signer',
        'last_name' => 'Sub',
        'email' => 'signer-' . uniqid() . '@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $this->subVendor->id,
    ]);
});

it('signs a waiver via the Livewire show component', function () {
    $this->actingAs($this->subVendorUser);

    Livewire::test(LienWaiverShow::class, ['lienWaiver' => $this->waiver])
        ->set('signerName', 'John Q. Public')
        ->set('signerTitle', 'Owner')
        ->set('typeSignature', true)
        ->set('signatureData', 'John Q. Public')
        ->call('sign')
        ->assertHasNoErrors();

    $fresh = $this->waiver->fresh();

    expect($fresh->status)->toBe(LienWaiverStatus::Signed)
        ->and($fresh->signed_at)->not->toBeNull()
        ->and(LienWaiverSignature::where('lien_waiver_id', $fresh->id)->count())->toBe(1);
});

it('records signer audit data including ip and document hash', function () {
    $this->actingAs($this->subVendorUser);

    Livewire::test(LienWaiverShow::class, ['lienWaiver' => $this->waiver])
        ->set('signerName', 'Jane Doe')
        ->set('signerTitle', 'Manager')
        ->set('typeSignature', true)
        ->set('signatureData', 'Jane Doe')
        ->call('sign');

    $sig = LienWaiverSignature::where('lien_waiver_id', $this->waiver->id)->first();

    expect($sig->signer_name)->toBe('Jane Doe')
        ->and($sig->document_hash)->not->toBeEmpty()
        ->and($sig->ip_address)->not->toBeNull()
        ->and($sig->signed_at)->not->toBeNull();
});

it('rejects sign call with invalid data', function () {
    $this->actingAs($this->subVendorUser);

    Livewire::test(LienWaiverShow::class, ['lienWaiver' => $this->waiver])
        ->set('signerName', 'X') // too short
    ->set('signerTitle', '')
        ->set('signatureData', '')
        ->call('sign')
    ->assertHasErrors(['signerName', 'signerTitle', 'signatureData']);
});

it('allows public access via signing token', function () {
    auth()->logout();

    $component = Livewire::test(LienWaiverShow::class, ['token' => $this->waiver->access_token]);

    expect($component->get('publicAccess'))->toBeTrue()
        ->and($component->instance()->canSign)->toBeTrue();
});
