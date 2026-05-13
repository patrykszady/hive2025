<?php

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Mail\LienWaiverSigningRequest;
use App\Models\Client;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLienWaiverUser(string $email): User
{
    return User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $email,
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
    ]);
}

it('sends a lien waiver signing request to a single vendor email', function () {
    Mail::fake();

    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_email' => 'signatures@example.test',
        'business_type' => 'Sub',
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $owner = makeLienWaiverUser('owner-' . Str::random(6) . '@example.test');
    $owner->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();

    $this->actingAs($owner);

    $project = Project::query()->create([
        'project_name' => 'Bathroom',
        'client_id' => Client::query()->create([
            'business_name' => 'Homeowner',
            'address' => '456 Oak Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60601',
        ])->id,
        'address' => '456 Oak Ave',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $extraUser = makeLienWaiverUser('extra-' . Str::random(6) . '@example.test');
    $anotherUser = makeLienWaiverUser('another-' . Str::random(6) . '@example.test');

    $vendor->users()->attach($owner->id, ['is_employed' => true, 'role_id' => 1]);
    $vendor->users()->attach($extraUser->id, ['is_employed' => true, 'role_id' => 1]);
    $vendor->users()->attach($anotherUser->id, ['is_employed' => true, 'role_id' => 1]);

    $waiver = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'vendor_id' => $vendor->id,
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress,
        'status' => LienWaiverStatus::Draft,
        'amount' => 1250.00,
        'exceptions_amount' => 0,
        'through_date' => now()->addDay()->toDateString(),
        'jurisdiction' => 'US-IL',
        'created_by_user_id' => $owner->id,
    ]);

    (new SendLienWaiverSigningRequestJob($waiver->id))->handle();

    Mail::assertSent(LienWaiverSigningRequest::class, 1);
    Mail::assertSent(LienWaiverSigningRequest::class, function (LienWaiverSigningRequest $mail) use ($vendor): bool {
        return $mail->hasTo('signatures@example.test');
    });

    expect($waiver->fresh()->status)->toBe(LienWaiverStatus::Sent);
});
