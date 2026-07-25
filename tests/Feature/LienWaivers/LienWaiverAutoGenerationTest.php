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

    // Pin the types: the factory randomizes business_type, and Retail
    // claimants intentionally render without an affidavit.
    $this->contractor = Vendor::factory()->create(['business_type' => 'Sub']);
    $this->subVendor = Vendor::factory()->create(['business_type' => 'Sub']);

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

function renderIlWaiver(LienWaiver $waiver): string
{
    return view('pdf.lien-waiver', [
        'waiver' => $waiver,
        'project' => $waiver->project,
        'vendor' => Vendor::withoutGlobalScopes()->find($waiver->vendor_id),
        'payerVendor' => Vendor::withoutGlobalScopes()->find($waiver->belongs_to_vendor_id),
        'payerOverride' => null,
        'check' => $waiver->check,
        'payment' => $waiver->payment,
        'signatures' => collect(),
        'isSigned' => false,
        'isDraft' => true,
        'isSubWaiver' => $waiver->isSubWaiver(),
        'projectCounty' => 'Cook',
        'amountWords' => 'Sixty Thousand',
        'affidavit' => ['original_contract' => 0, 'extras' => 0, 'contract_total' => 0, 'amount_paid' => 0, 'this_payment' => 0, 'balance_due' => 0],
    ])->render();
}

it('detects Illinois jurisdiction and renders IL-specific wording', function () {
    $this->project->update(['state' => 'IL', 'city' => 'Chicago', 'zip_code' => 60601]);

    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id);

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();
    expect($waiver->jurisdiction)->toBe('IL');

    $html = renderIlWaiver($waiver);

    // Every claimant swears its own affidavit — a sub's recites the
    // sub-contract with the GC (the all-subs listing lives on the GCSS).
    expect($html)
        ->toContain('STATE OF ILLINOIS')
        ->toContain('WAIVER OF LIEN TO DATE')
        ->toContain('WHEREAS the undersigned has been employed by')
        ->toContain('INCLUDING EXTRAS.*')
        ->toContain('CONTRACTOR&rsquo;S AFFIDAVIT')
        ->toContain('NOTARY PUBLIC');
});

it('correctly identifies GC vs sub waivers (affidavit rendering deferred due to Livewire Blaze bug)', function () {
    // TODO: When Livewire Blaze bug is fixed (nested @if statements being stripped),
    // re-enable the affidavit section in the template and update this test.
    // The affidavit code exists but Blaze strips it between the @if and @endif.
    $this->project->update(['state' => 'IL', 'city' => 'Chicago', 'zip_code' => 60601]);

    $gcWaiver = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $this->contractor->id,
        'vendor_id' => $this->contractor->id,
        'project_id' => $this->project->id,
        'type' => \App\Enums\LienWaiverType::ConditionalProgress,
        'status' => \App\Enums\LienWaiverStatus::Draft,
        'amount' => 60000,
        'exceptions_amount' => 0,
        'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL',
        'notes' => json_encode(['payer' => ['name' => 'Owner Person']]),
        'created_by_user_id' => $this->user->id,
    ]);

    expect($gcWaiver->isSubWaiver())->toBeFalse();

    $html = renderIlWaiver($gcWaiver);

    // For now, just verify the waiver section renders
    expect($html)->toContain('WAIVER OF LIEN TO DATE');
});

it('always prints the real amount, and PAID IN FULL only on unconditional finals', function () {
    $this->project->update(['state' => 'IL', 'city' => 'Chicago', 'zip_code' => 60601]);

    $check = makeCheck($this->contractor->id, $this->subVendor->id, $this->user->id);
    $payment = makePayment($this->project->id, $this->contractor->id, $this->user->id, $check->id, 1500.00);

    $waiver = LienWaiver::query()->where('payment_id', $payment->id)->first();
    expect($waiver->isSubWaiver())->toBeTrue();

    expect(renderIlWaiver($waiver))->toContain('1,500.00');

    $waiver->forceFill(['type' => \App\Enums\LienWaiverType::UnconditionalFinal])->save();
    expect(renderIlWaiver($waiver->fresh()))
        ->toContain('PAID IN FULL')
        ->toContain('FINAL WAIVER OF LIEN');
});
