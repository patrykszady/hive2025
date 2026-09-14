<?php

use App\Models\EmailTracking;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InsuranceReplyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function agentReply(array $overrides = []): array
{
    return array_merge([
        'subject' => 'Re: COI Request | Mariusz Kot Construction',
        'from' => [['email' => 'rozainsurance@gmail.com', 'name' => 'Roza Insurance Agency']],
        'to' => [['email' => 'certificates@hive.contractors', 'name' => 'Hive Contractors']],
        'cc' => [['email' => 'mariuszkot40@att.net'], ['email' => 'crew@gs.construction', 'name' => 'GS Construction Crew']],
    ], $overrides);
}

it('takes both parties from the request the message replies to', function () {
    $fx = kotVendors();

    EmailTracking::create([
        'message_id' => 'req-1',
        'event_type' => 'sent',
        'recipient_emails' => ['rozainsurance@gmail.com'],
        'metadata' => [
            'email_template_name' => 'Insurance Request',
            'subject' => 'COI Request | Mariusz Kot Construction',
            'vendor_id' => $fx['new']->id,
            'belongs_to_vendor_id' => $fx['gs']->id,
        ],
        'event_at' => now()->subHours(9),
    ]);

    expect(app(InsuranceReplyContext::class)->resolve(agentReply()))->toBe([
        'vendor_id' => $fx['new']->id,
        'belongs_to_vendor_id' => $fx['gs']->id,
        'source' => 'request',
    ]);
});

it('reads the vendor out of the subject when no request is on record', function () {
    $fx = kotVendors();

    expect(app(InsuranceReplyContext::class)->resolve(agentReply(['subject' => 'RE: Fwd: COI Request | Mariusz Kot Construction'])))->toBe([
        'vendor_id' => $fx['new']->id,
        'belongs_to_vendor_id' => null,
        'source' => 'subject',
    ]);
});

it('falls back to the people on the message only when they belong to one vendor', function () {
    $fx = kotVendors();

    // Mariusz is on two vendor rows — the CC alone cannot decide.
    expect(app(InsuranceReplyContext::class)->resolve(agentReply(['subject' => 'Certificate attached'])))->toBeNull();

    $solo = Vendor::factory()->create(['business_name' => 'Vol Nat Heating Inc', 'business_type' => 'Sub']);
    $owner = User::query()->create(['first_name' => 'Vol', 'last_name' => 'Nat', 'email' => 'volnat@example.com', 'cell_phone' => '8475550101']);
    $solo->users()->attach($owner->id, ['role_id' => 1]);

    expect(app(InsuranceReplyContext::class)->resolve(agentReply([
        'subject' => 'Certificate attached',
        'cc' => [['email' => 'VolNat@example.com'], ['email' => 'crew@gs.construction']],
    ])))->toBe(['vendor_id' => $solo->id, 'belongs_to_vendor_id' => null, 'source' => 'recipient']);

    // Our own addresses never point at a vendor.
    expect(app(InsuranceReplyContext::class)->resolve(agentReply(['subject' => 'Certificate attached', 'cc' => [['email' => 'crew@gs.construction']]])))->toBeNull();
});

it('strips reply and forward prefixes from a subject', function () {
    expect(app(InsuranceReplyContext::class)->bareSubject('Re: RE: Fwd:  COI Request | Mariusz Kot Construction '))
        ->toBe('COI Request | Mariusz Kot Construction');
});
