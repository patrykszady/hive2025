<?php

use App\Livewire\Payments\PaymentCreate;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * PaymentCreate::update()/remove() now delegate their write logic to
 * App\Services\Payments\PaymentRecorder (extracted so the Hive Contractors
 * app's PaymentController::update()/destroy() share it) — these tests pin
 * the web's own behavior down so the refactor and the app's new endpoints
 * stay provably identical. tests/Feature/Api/App/AppPaymentsTest.php
 * exercises the same scenarios through the app API.
 */
beforeEach(function () {
    Mail::fake();
    Notification::fake();
    Queue::fake();
});

/** @return array{0: User, 1: Client, 2: Project, 3: Project} */
function payupdate_fixture(): array
{
    $company = Vendor::factory()->create(['business_type' => 'GC']);
    $admin = User::query()->create([
        'first_name' => 'Pay', 'last_name' => 'Admin',
        'email' => 'payupdate-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $company->id,
    ]);
    $company->users()->attach($admin->id, ['role_id' => 1]);

    test()->actingAs($admin);

    $client = Client::factory()->create();
    $client->vendors()->attach($company->id);
    $projects = collect(['Hall Bath', 'Primary Bath'])->map(function (string $name) use ($company, $client) {
        $project = Project::factory()->create([
            'project_name' => $name,
            'belongs_to_vendor_id' => $company->id,
            'client_id' => $client->id,
        ]);
        $project->vendors()->attach($company->id, ['client_id' => $client->id]);
        ProjectStatus::forceCreate([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $company->id,
            'status_code' => 6, // Active
            'start_date' => now()->addDay(),
        ]);

        return $project;
    });

    return [$admin, $client, $projects[0], $projects[1]];
}

it('updates a single-project payment amount and reference through the edit modal', function () {
    [$admin, $client, $hallBath] = payupdate_fixture();

    $payment = Payment::create([
        'project_id' => $hallBath->id,
        'amount' => 500,
        'date' => now()->format('Y-m-d'),
        'reference' => 'OLD',
        'belongs_to_vendor_id' => $admin->vendor->id,
        'created_by_user_id' => $admin->id,
    ]);

    $component = Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('editPayment', $payment);

    $index = collect($component->get('projects'))->search(fn (array $row) => $row['id'] === $hallBath->id);

    $component
        ->set('form.invoice', 'NEW')
        ->set("projects.{$index}.amount", '750')
        ->call('update')
        ->assertHasNoErrors();

    $payment->refresh();
    expect((float) $payment->amount)->toBe(750.0)
        ->and($payment->reference)->toBe('NEW');
});

it('splits a single-project payment across a second project, grouping the new row under it', function () {
    [$admin, $client, $hallBath, $primaryBath] = payupdate_fixture();

    $payment = Payment::create([
        'project_id' => $hallBath->id,
        'amount' => 400,
        'date' => now()->format('Y-m-d'),
        'reference' => 'GRP',
        'belongs_to_vendor_id' => $admin->vendor->id,
        'created_by_user_id' => $admin->id,
    ]);

    $component = Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('editPayment', $payment);

    $primaryIndex = collect($component->get('projects'))->search(fn (array $row) => $row['id'] === $primaryBath->id);

    $component
        ->set("projects.{$primaryIndex}.amount", '600')
        ->call('update')
        ->assertHasNoErrors();

    $children = Payment::query()->where('parent_client_payment_id', $payment->id)->get();
    expect($children)->toHaveCount(1)
        ->and((float) $children->first()->amount)->toBe(600.0)
        ->and($children->first()->project_id)->toBe($primaryBath->id);
});

// NOTE: pins down the web's actual behavior, not "correct" behavior — the
// single-project branch of update() never runs the sibling-cleanup loop
// (unlike the multi-project branch), so the other row survives. See the
// matching note in tests/Feature/Api/App/AppPaymentsTest.php.
it('reduces a grouped payment to one project in place, without deleting the other row', function () {
    [$admin, $client, $hallBath, $primaryBath] = payupdate_fixture();

    $parent = Payment::create([
        'project_id' => $hallBath->id,
        'amount' => 400,
        'date' => now()->format('Y-m-d'),
        'reference' => 'GRP',
        'belongs_to_vendor_id' => $admin->vendor->id,
        'created_by_user_id' => $admin->id,
    ]);
    $child = Payment::create([
        'project_id' => $primaryBath->id,
        'amount' => 600,
        'date' => now()->format('Y-m-d'),
        'reference' => 'GRP',
        'belongs_to_vendor_id' => $admin->vendor->id,
        'created_by_user_id' => $admin->id,
        'parent_client_payment_id' => $parent->id,
    ]);

    $component = Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('editPayment', $parent);

    $hallIndex = collect($component->get('projects'))->search(fn (array $row) => $row['id'] === $hallBath->id);
    $primaryIndex = collect($component->get('projects'))->search(fn (array $row) => $row['id'] === $primaryBath->id);

    $component
        ->set("projects.{$hallIndex}.amount", '1000')
        ->set("projects.{$primaryIndex}.amount", '')
        ->call('update')
        ->assertHasNoErrors();

    expect(Payment::query()->whereKey($child->id)->exists())->toBeTrue()
        ->and((float) $child->refresh()->amount)->toBe(600.0)
        ->and((float) $parent->refresh()->amount)->toBe(1000.0)
        ->and($parent->project_id)->toBe($hallBath->id)
        ->and($parent->parent_client_payment_id)->toBeNull();
});

it('deletes exactly the one payment row the edit modal was opened for', function () {
    [$admin, $client, $hallBath, $primaryBath] = payupdate_fixture();

    $parent = Payment::create([
        'project_id' => $hallBath->id,
        'amount' => 400,
        'date' => now()->format('Y-m-d'),
        'reference' => 'GRP',
        'belongs_to_vendor_id' => $admin->vendor->id,
        'created_by_user_id' => $admin->id,
    ]);
    $child = Payment::create([
        'project_id' => $primaryBath->id,
        'amount' => 600,
        'date' => now()->format('Y-m-d'),
        'reference' => 'GRP',
        'belongs_to_vendor_id' => $admin->vendor->id,
        'created_by_user_id' => $admin->id,
        'parent_client_payment_id' => $parent->id,
    ]);

    Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('editPayment', $parent)
        ->call('remove');

    expect(Payment::query()->whereKey($parent->id)->exists())->toBeFalse()
        ->and(Payment::query()->whereKey($child->id)->exists())->toBeTrue();
});
