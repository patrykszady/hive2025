<?php

use App\Models\CallLog;
use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function recordedCall(array $attributes = []): CallLog
{
    Storage::fake('local');
    Storage::disk('local')->put('recordings/call.mp3', 'ID3 not really audio');

    return CallLog::factory()->create(array_merge([
        'recording_disk' => 'local',
        'recording_path' => 'recordings/call.mp3',
    ], $attributes));
}

function recordingVendorUser(): User
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Call',
        'last_name' => 'Listener',
        'email' => 'call-listener-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

function recordingClientUser(string $cell): User
{
    $client = Client::query()->create(['business_name' => 'Homeowner '.uniqid()]);
    $user = new User();
    $user->forceFill([
        'first_name' => 'Home',
        'last_name' => 'Owner',
        'email' => 'homeowner-'.uniqid().'@example.test',
        'cell_phone' => $cell,
        'primary_vendor_id' => null,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $user->clients()->attach($client->id);

    return $user->fresh();
}

it('sends guests to the login page', function () {
    $call = recordedCall();

    $this->get(route('calls.recording', $call))->assertRedirect(route('login'));
});

it('streams a recording to a user of the company that took the call', function () {
    $user = recordingVendorUser();
    $call = recordedCall(['vendor_id' => $user->primary_vendor_id]);

    $this->actingAs($user)
        ->get(route('calls.recording', $call))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');
});

it('hides another company\'s recording from a vendor user', function () {
    $owner = recordingVendorUser();
    $call = recordedCall(['vendor_id' => $owner->primary_vendor_id]);

    $this->actingAs(recordingVendorUser())
        ->get(route('calls.recording', $call))
        ->assertNotFound();
});

it('hides a call that a client user was not part of', function () {
    $call = recordedCall(['from_number' => '+12245550999', 'to_number' => '+12245550998']);
    $user = recordingClientUser('2245550111');

    expect($user->is_browsing_as_client)->toBeTrue();

    // The access middleware already turns client users away from this route;
    // the route's own check is what protects the file if that ever changes.
    $this->actingAs($user)->get(route('calls.recording', $call))->assertForbidden();

    $this->actingAs($user);
    $serve = Route::getRoutes()->getByName('calls.recording')->getAction('uses');

    expect(fn () => $serve($call))->toThrow(NotFoundHttpException::class);
});

it('plays a call back to the client user who was on it', function () {
    $user = recordingClientUser('2245550111');
    $call = recordedCall(['from_number' => '+12245550111', 'to_number' => '+12245550998']);

    $this->actingAs($user);
    $serve = Route::getRoutes()->getByName('calls.recording')->getAction('uses');

    expect($serve($call)->getStatusCode())->toBe(200);
});
