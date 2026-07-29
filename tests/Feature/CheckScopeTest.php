<?php

use App\Models\Check;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A member of a vendor must only see checks payable to THEM. The scope used to
 * add `orWhereNull('user_id')`, and most vendor checks carry no user_id, so a
 * member could read nearly the employer's whole check book.
 */
function checkScopeFixture(): array
{
    $employer = Vendor::factory()->create(['business_name' => 'GC Co']);
    $ownCompany = Vendor::factory()->create(['business_name' => 'Member Sub LLC']);
    $otherSub = Vendor::factory()->create(['business_name' => 'Someone Else Inc']);

    $member = User::factory()->create(['primary_vendor_id' => $employer->id]);
    $member->vendors()->attach($employer->id, ['role_id' => 2, 'is_employed' => 1]);
    $member->vendors()->attach($ownCompany->id, ['role_id' => 1, 'is_employed' => 1]);

    $base = [
        'belongs_to_vendor_id' => $employer->id,
        'date' => now()->toDateString(),
        'amount' => 100,
        'check_type' => 'Check',
        'created_by_user_id' => $member->id,
    ];

    return [
        'member' => $member,
        'employer' => $employer,
        // payable to the member personally
        'personal' => Check::withoutGlobalScopes()->create($base + ['user_id' => $member->id, 'vendor_id' => null]),
        // payable to the company the member owns
        'ownCompany' => Check::withoutGlobalScopes()->create($base + ['user_id' => null, 'vendor_id' => $ownCompany->id]),
        // the employer paying somebody else — must stay hidden
        'otherSub' => Check::withoutGlobalScopes()->create($base + ['user_id' => null, 'vendor_id' => $otherSub->id]),
        // a company check with no payee at all — the old leak
        'unattributed' => Check::withoutGlobalScopes()->create($base + ['user_id' => null, 'vendor_id' => null]),
    ];
}

it('shows a member only the checks payable to them', function () {
    $fx = checkScopeFixture();

    $this->actingAs($fx['member']);

    $visible = Check::pluck('id');

    expect($visible)->toContain($fx['personal']->id)
        ->and($visible)->toContain($fx['ownCompany']->id)
        ->and($visible)->not->toContain($fx['otherSub']->id)
        ->and($visible)->not->toContain($fx['unattributed']->id);
});

it('still shows an admin the whole company book', function () {
    $fx = checkScopeFixture();

    $admin = User::factory()->create(['primary_vendor_id' => $fx['employer']->id]);
    $admin->vendors()->attach($fx['employer']->id, ['role_id' => 1, 'is_employed' => 1]);

    $this->actingAs($admin);

    $visible = Check::pluck('id');

    expect($visible)->toContain($fx['personal']->id)
        ->and($visible)->toContain($fx['ownCompany']->id)
        ->and($visible)->toContain($fx['otherSub']->id)
        ->and($visible)->toContain($fx['unattributed']->id);
});
