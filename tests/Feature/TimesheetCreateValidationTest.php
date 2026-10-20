<?php

use App\Livewire\Timesheets\TimesheetCreate;
use App\Models\Client;
use App\Models\Hour;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * TimesheetForm declared #[Rule(...)] attributes on every property, but
 * never imported Livewire\Attributes\Rule — the bare `Rule` name resolved
 * to App\Livewire\Forms\Rule (which doesn't exist), so Livewire's
 * attribute scan never found it and the rules did nothing. store() also
 * never called validate() on its own. Fixed by importing the attribute and
 * calling $this->validate() in store(), mirroring the app API's
 * StoreTimesheetFromHourRequest (hourly: required|numeric|min:0).
 */
function timesheetCreateFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();

    $employee = User::query()->create([
        'first_name' => 'Timesheet',
        'last_name' => 'Employee',
        'email' => 'timesheet-employee-'.uniqid().'@example.test',
        'cell_phone' => '555'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $employee->vendors()->attach($vendor->id, [
        'role_id' => 1,
        'hourly_rate' => 20,
    ]);

    // ProjectObserver::creating() stamps belongs_to_vendor_id from the
    // authenticated user, so a project needs one acting while it's made.
    test()->actingAs($employee);

    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'client_id' => $client->id,
    ]);

    $hour = Hour::forceCreate([
        'date' => '2026-05-11',
        'user_id' => $employee->id,
        'vendor_id' => $vendor->id,
        'project_id' => $project->id,
        'hours' => 5,
        'created_by_user_id' => $employee->id,
    ]);

    return compact('vendor', 'employee', 'hour');
}

it('rejects a negative hourly rate instead of silently confirming the week', function () {
    ['employee' => $employee, 'hour' => $hour] = timesheetCreateFixture();

    Livewire::actingAs($employee)
        ->test(TimesheetCreate::class, ['hour' => $hour])
        ->set('form.hourly', -5)
        ->call('save')
        ->assertHasErrors(['form.hourly']);

    expect(Timesheet::count())->toBe(0)
        ->and($hour->fresh()->timesheet_id)->toBeNull();
});

it('rejects a blank hourly rate', function () {
    ['employee' => $employee, 'hour' => $hour] = timesheetCreateFixture();

    Livewire::actingAs($employee)
        ->test(TimesheetCreate::class, ['hour' => $hour])
        ->set('form.hourly', '')
        ->call('save')
        ->assertHasErrors(['form.hourly' => 'required']);

    expect(Timesheet::count())->toBe(0)
        ->and($hour->fresh()->timesheet_id)->toBeNull();
});

it('confirms the week into a timesheet when the hourly rate is valid', function () {
    ['employee' => $employee, 'hour' => $hour] = timesheetCreateFixture();

    Livewire::actingAs($employee)
        ->test(TimesheetCreate::class, ['hour' => $hour])
        ->set('form.hourly', 25)
        ->call('save')
        ->assertHasNoErrors();

    expect(Timesheet::count())->toBe(1)
        ->and((float) Timesheet::first()->hourly)->toBe(25.0)
        ->and($hour->fresh()->timesheet_id)->not->toBeNull();
});
