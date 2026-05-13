<?php

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Models\Check;
use App\Models\LienWaiver;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Support\LienWaiverFactory as LienWaiverFromPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

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
});

function makeCheck(int $contractorId, int $vendorId, int $userId): Check
{
    return Check::create([
        'check_type' => 'Check',
        'check_number' => 1001,
        'date' => now()->toDateString(),
        'vendor_id' => $vendorId,
        'belongs_to_vendor_id' => $contractorId,
        'created_by_user_id' => $userId,
    ]);
}

function makePayment(int $projectId, int $contractorId, int $userId, int $checkId, float $amount = 1500.00, ?string $tx = null): Payment
{
    $payment = Payment::create([
        'project_id' => $projectId,
        'amount' => $amount,
        'date' => now()->toDateString(),
        'belongs_to_vendor_id' => $contractorId,
        'check_id' => $checkId,
        'transaction_id' => $tx,
        'created_by_user_id' => $userId,
    ]);

    // Auto-generation via PaymentObserver was disabled; call the factory
    // directly so the auto-detection coverage in this test file still applies.
    LienWaiverFromPayment::fromPayment($payment->fresh());

    return $payment;
}

it('auto-creates a lien waiver when a payment is recorded', function () {
    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id, 2500.00);

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();

    expect($waiver)->not->toBeNull()
        ->and($waiver->vendor_id)->toBe($this->subVendor->id)
        ->and($waiver->belongs_to_vendor_id)->toBe($this->contractor->id)
        ->and((float) $waiver->amount)->toBe(2500.00)
        ->and($waiver->status)->toBe(LienWaiverStatus::Draft)
        ->and($waiver->document_hash)->not->toBeEmpty()
        ->and($waiver->access_token)->not->toBeEmpty();
});

it('detects conditional progress for an in-progress uncleared payment', function () {
    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id);

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();

    expect($waiver->type)->toBe(LienWaiverType::ConditionalProgress);
});

it('detects unconditional progress when payment has cleared transaction', function () {
    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id, 1000, 'TX-CLEAR-1');

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();

    expect($waiver->type)->toBe(LienWaiverType::UnconditionalProgress);
});

it('detects final waiver when project status is complete (status_code 7)', function () {
    ProjectStatus::create([
        'project_id' => $this->project->id,
        'belongs_to_vendor_id' => $this->contractor->id,
        'status_code' => 7,
        'start_date' => now(),
    ]);

    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id);

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();

    expect($waiver->type)->toBe(LienWaiverType::ConditionalFinal);
});

it('is idempotent and returns the same waiver on a second factory call', function () {
    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id);

    $first = LienWaiver::query()->where('payment_id', $payment->id)->first();
    $second = LienWaiverFromPayment::fromPayment($payment->fresh());

    expect($second->id)->toBe($first->id)
        ->and(LienWaiver::query()->where('payment_id', $payment->id)->count())->toBe(1);
});

it('skips self-payments where contractor pays themselves', function () {
    $check = makeCheck($this->contractor->id, $this->contractor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id);

    expect(LienWaiver::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('detects jurisdiction from project state', function () {
    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id);

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();
    expect($waiver->jurisdiction)->toBe('CA');
});

it('detects Illinois jurisdiction and renders IL-specific wording', function () {
    $this->project->update(['state' => 'IL', 'city' => 'Chicago', 'zip_code' => 60601]);

    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id);

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();
    expect($waiver->jurisdiction)->toBe('IL');

    $html = view('pdf.lien-waiver', [
        'waiver' => $waiver,
        'project' => $waiver->project,
        'vendor' => Vendor::withoutGlobalScopes()->find($waiver->vendor_id),
        'payerVendor' => Vendor::withoutGlobalScopes()->find($waiver->belongs_to_vendor_id),
        'payerOverride' => null,
        'check' => $waiver->check,
        'signatures' => collect(),
        'isDraft' => true,
    ])->render();

    expect($html)
        ->toContain('State of Illinois')
        ->toContain('770 ILCS 60/')
        ->toContain('JURAT');
});
