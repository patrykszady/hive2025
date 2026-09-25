<?php

use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Livewire\LienWaivers\Index;
use App\Models\Client;
use App\Models\Expense;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Vendor A (GC) has a real sub, vendor B, with an actual expense on the
 * project — buildRows() legitimately offers them on the sworn statement.
 * Vendor C is a total stranger: no expense, no bid, no relationship to this
 * project or vendor A at all.
 */
function sec4_swornStatementFixture(): array
{
    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $vendorB = Vendor::factory()->create(['business_type' => 'Sub']);
    $vendorC = Vendor::factory()->create(['business_type' => 'Sub']);
    $vendorC->forceFill(['registration' => ['registered' => true]])->save();

    $adminA = User::factory()->create();
    $adminA->primary_vendor_id = $vendorA->id;
    $adminA->registration = ['registered' => true];
    $adminA->save();
    $vendorA->users()->attach($adminA->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendorA->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Sec4 Sworn Statement Project',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $project->vendors()->attach($vendorA->id, ['client_id' => $client->id]);

    // Vendor B has real money on the project — buildRows() offers them.
    Expense::withoutGlobalScopes()->create([
        'amount' => 400,
        'date' => now()->toDateString(),
        'vendor_id' => $vendorB->id,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendorA->id,
        'created_by_user_id' => $adminA->id,
    ]);

    return compact('vendorA', 'vendorB', 'vendorC', 'adminA', 'client', 'project');
}

it('drops a tampered sworn statement row for a vendor with no money on the project', function () {
    $fx = sec4_swornStatementFixture();
    Queue::fake();

    $testable = Livewire::actingAs($fx['adminA'])->test(Index::class);
    $testable->set('project', $fx['project']);
    $testable->call('openSwornStatement');

    $rows = $testable->get('ssRows');
    expect(collect($rows)->pluck('vendor_id'))->toContain($fx['vendorB']->id)
        ->not->toContain($fx['vendorC']->id);

    // Tamper: inject a row for the stranger vendor, as the public ssRows
    // array would let a crafted Livewire payload do.
    $rows[] = [
        'vendor_id' => $fx['vendorC']->id,
        'name' => $fx['vendorC']->business_name,
        'include' => true,
        'kind' => 'Carpentry',
        'is_retail' => false,
        'is_material' => false,
        'contract' => '1000',
        'paid' => 0,
        'this_payment' => '500',
    ];
    // Also mark the legitimate row included with a kind of work, or overall
    // validation for it fails first and masks the vendor_id check.
    foreach ($rows as $i => $row) {
        if ($row['vendor_id'] === $fx['vendorB']->id) {
            $rows[$i]['include'] = true;
            $rows[$i]['kind'] = 'Electrical';
            $rows[$i]['this_payment'] = '100';
        }
    }

    $testable->set('ssRows', $rows);
    $testable->set('ssThisPayment', '600');
    $testable->call('generateSwornStatement');

    expect(\App\Models\Bid::withoutGlobalScopes()->where('vendor_id', $fx['vendorC']->id)->count())->toBe(0)
        ->and(LienWaiver::withoutGlobalScopes()->where('vendor_id', $fx['vendorC']->id)->count())->toBe(0)
        ->and($fx['vendorC']->fresh()->work_type_id)->toBeNull();

    Queue::assertNotPushed(SendLienWaiverSigningRequestJob::class, function ($job) use ($fx) {
        $waiver = LienWaiver::withoutGlobalScopes()->find($job->lienWaiverId ?? null);

        return $waiver && (int) $waiver->vendor_id === $fx['vendorC']->id;
    });

    // The legitimate sub's waiver DID get created — the fix doesn't collateral-damage real rows.
    expect(LienWaiver::withoutGlobalScopes()->where('vendor_id', $fx['vendorB']->id)->count())->toBe(1);
});
