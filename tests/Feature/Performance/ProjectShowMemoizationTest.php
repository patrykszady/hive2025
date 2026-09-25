<?php

use App\Livewire\Projects\ProjectShow;
use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\EmailTracking;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * /projects/{id} inlined three `->exists()` checks (expenses, email
 * tracking, distributions) straight in the blade; /clients/{id} (via
 * ProjectsIndex, embedded) inlined an email-tracking `->exists()` too.
 * These pin: each is now a #[Computed] method that runs its query once per
 * request no matter how many times the value is read.
 */
function perf_projectShowUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Show', 'last_name' => 'Admin',
        'email' => 'show-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function perf_projectFor(User $user): Project
{
    $client = Client::factory()->create();

    return Project::factory()->create(['belongs_to_vendor_id' => $user->vendor->id, 'client_id' => $client->id]);
}

it('memoizes hasExpenses, hasEmailTracking and hasDistributions to one query each per request', function () {
    $user = perf_projectShowUser();
    $this->actingAs($user);

    $project = perf_projectFor($user);
    Expense::forceCreate([
        'amount' => 10, 'date' => now(), 'vendor_id' => Vendor::factory()->create()->id,
        'project_id' => $project->id, 'belongs_to_vendor_id' => $user->vendor->id, 'created_by_user_id' => $user->id,
    ]);

    $component = new ProjectShow();
    $component->project = $project;

    DB::enableQueryLog();
    $component->hasExpenses;
    $component->hasExpenses;
    $component->hasExpenses;
    $expenseQueryCount = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "expenses"'))->count();

    $component->hasEmailTracking;
    $component->hasEmailTracking;
    $emailQueryCountAfterFirst = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "email_tracking"'))->count();

    $component->hasDistributions;
    $component->hasDistributions;
    $distributionQueryCount = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "distributions"'))->count();
    DB::disableQueryLog();

    expect($component->hasExpenses)->toBeTrue()
        ->and($expenseQueryCount)->toBe(1)
        ->and($emailQueryCountAfterFirst)->toBe(1)
        ->and($distributionQueryCount)->toBe(1);
});

it('hasEmailTracking reflects real EmailTracking rows for the project and its leads', function () {
    $user = perf_projectShowUser();
    $this->actingAs($user);

    $project = perf_projectFor($user);

    $component = new ProjectShow();
    $component->project = $project;
    expect($component->hasEmailTracking)->toBeFalse();

    EmailTracking::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'event_type' => 'sent',
        'event_at' => now(),
        'recipient_emails' => ['test@example.test'],
    ]);

    $fresh = new ProjectShow();
    $fresh->project = $project->fresh();
    expect($fresh->hasEmailTracking)->toBeTrue();
});

it('ProjectsIndex memoizes the client email-tracking check to one query per request', function () {
    $user = perf_projectShowUser();
    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::factory()->create(['belongs_to_vendor_id' => $user->vendor->id, 'client_id' => $client->id]);
    EmailTracking::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'event_type' => 'sent',
        'event_at' => now(),
        'recipient_emails' => ['test@example.test'],
    ]);

    $component = new ProjectsIndex();
    $component->client_id = $client->id;

    DB::enableQueryLog();
    $component->hasEmailTrackingForClient;
    $component->hasEmailTrackingForClient;
    $component->hasEmailTrackingForClient;
    $queryCount = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "email_tracking"'))->count();
    DB::disableQueryLog();

    expect($component->hasEmailTrackingForClient)->toBeTrue()
        ->and($queryCount)->toBe(1);
});
