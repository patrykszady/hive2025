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
 * "Add Payment" with no amount used to close the window as if it had saved
 * while storing nothing (2026-10-01, 215 Huron): the zero-total check
 * compared a float sum with `=== 0`, which never matches. It must stay open
 * and say why; a real amount still saves to the project it was typed on.
 */
beforeEach(function () {
    Mail::fake();
    Notification::fake();
    Queue::fake();
});

/** @return array{0: User, 1: Client, 2: Project, 3: Project} */
function paytotal_fixture(): array
{
    $company = Vendor::factory()->create(['business_type' => 'GC']);
    $admin = User::query()->create([
        'first_name' => 'Pay', 'last_name' => 'Admin',
        'email' => 'paytotal-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $company->id,
    ]);
    $company->users()->attach($admin->id, ['role_id' => 1]);

    test()->actingAs($admin);

    $client = Client::factory()->create();
    // ClientScope: a company only sees clients linked to it.
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

it('keeps the window open and stores nothing when no amount is entered', function () {
    [$admin, $client] = paytotal_fixture();

    Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('addProject', $client->id)
        ->set('form.date', now()->format('Y-m-d'))
        ->set('form.invoice', '2061')
        ->call('save')
        ->assertHasErrors('payment_total_min');

    expect(Payment::query()->where('reference', '2061')->exists())->toBeFalse();
});

it('saves the amount to the project it was typed against', function () {
    [$admin, $client, $hallBath, $primaryBath] = paytotal_fixture();

    $component = Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('addProject', $client->id);

    $index = collect($component->get('projects'))->search(fn (array $row) => $row['id'] === $primaryBath->id);

    $component
        ->set('form.date', now()->format('Y-m-d'))
        ->set('form.invoice', '2062')
        ->set("projects.{$index}.amount", '1500')
        ->call('save')
        ->assertHasNoErrors();

    $payment = Payment::query()->where('reference', '2062')->sole();
    expect($payment->project_id)->toBe($primaryBath->id)
        ->and((float) $payment->amount)->toBe(1500.0);
});

it('lists the project it was opened from first, marked This project', function () {
    [$admin, $client, $hallBath, $primaryBath] = paytotal_fixture();

    $component = Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('addProject', $client->id, $primaryBath->id);

    expect(collect($component->get('projects'))->pluck('id')->all())->toBe([$primaryBath->id, $hallBath->id]);
    $component->assertSeeHtml('This project');

    $fromIndex = Livewire::actingAs($admin)
        ->test(PaymentCreate::class)
        ->call('addProject', $client->id);

    $fromIndex->assertDontSeeHtml('This project');
});
