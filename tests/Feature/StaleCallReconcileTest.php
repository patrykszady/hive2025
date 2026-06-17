<?php

use App\Livewire\Sms\CallStatusBadge;
use App\Models\CallLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    if (! Schema::hasTable('call_logs')) {
        Schema::create('call_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('call_control_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('direction')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('caller_name')->nullable();
            $table->string('status')->nullable();
            $table->boolean('has_voicemail')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('cell_phone')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('vendors')) {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('short_name')->nullable();
            $table->string('business_phone')->nullable();
            $table->json('options')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
});

/**
 * Create a call log and force its created_at, since created_at is not mass
 * assignable and Eloquent would otherwise stamp it with the current time.
 */
function makeCall(array $attributes, ?\Carbon\CarbonInterface $createdAt = null): CallLog
{
    $call = CallLog::create($attributes);

    if ($createdAt !== null) {
        DB::table('call_logs')->where('id', $call->id)->update(['created_at' => $createdAt]);
        $call->refresh();
    }

    return $call;
}

it('reconciles a stale transferred call to completed', function () {
    $call = makeCall([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subDays(2),
    ], now()->subDays(2));

    $this->artisan('calls:reconcile-stale', ['--execute' => true])->assertSuccessful();

    $call->refresh();
    expect($call->status)->toBe(CallLog::STATUS_COMPLETED);
    expect($call->ended_at)->not->toBeNull();
});

it('reconciles a stale unanswered inbound call to missed', function () {
    $call = makeCall([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_INITIATED,
    ], now()->subDay());

    $this->artisan('calls:reconcile-stale', ['--execute' => true])->assertSuccessful();

    expect($call->fresh()->status)->toBe(CallLog::STATUS_MISSED);
});

it('reconciles a stale unanswered outbound call to failed', function () {
    $call = makeCall([
        'direction' => 'outgoing',
        'status' => CallLog::STATUS_INITIATED,
    ], now()->subDay());

    $this->artisan('calls:reconcile-stale', ['--execute' => true])->assertSuccessful();

    expect($call->fresh()->status)->toBe(CallLog::STATUS_FAILED);
});

it('reconciles an active call that already has an ended_at timestamp', function () {
    $call = makeCall([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(5),
    ], now()->subMinutes(11));

    $this->artisan('calls:reconcile-stale', ['--execute' => true])->assertSuccessful();

    expect($call->fresh()->status)->toBe(CallLog::STATUS_COMPLETED);
});

it('leaves a genuinely live call untouched', function () {
    $call = makeCall([
        'direction' => 'outgoing',
        'status' => CallLog::STATUS_INITIATED,
    ], now()->subMinutes(2));

    $this->artisan('calls:reconcile-stale', ['--execute' => true])->assertSuccessful();

    expect($call->fresh()->status)->toBe(CallLog::STATUS_INITIATED);
});

it('makes no changes in dry-run mode', function () {
    $call = makeCall([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_INITIATED,
    ], now()->subDay());

    $this->artisan('calls:reconcile-stale')->assertSuccessful();

    expect($call->fresh()->status)->toBe(CallLog::STATUS_INITIATED);
});

it('does not light the badge for a stale call that never ended', function () {
    DB::table('users')->insert(['id' => 1, 'name' => 'Pat', 'first_name' => 'Pat']);
    $user = User::find(1);

    makeCall([
        'user_id' => $user->id,
        'direction' => 'incoming',
        'status' => CallLog::STATUS_INITIATED,
    ], now()->subDays(3));

    $this->actingAs($user);

    Livewire::test(CallStatusBadge::class)->assertSet('activeCallId', null);
});

it('lights the badge for a recent live call', function () {
    DB::table('users')->insert(['id' => 1, 'name' => 'Pat', 'first_name' => 'Pat']);
    $user = User::find(1);

    $call = makeCall([
        'user_id' => $user->id,
        'direction' => 'outgoing',
        'status' => CallLog::STATUS_INITIATED,
    ], now()->subMinutes(1));

    $this->actingAs($user);

    Livewire::test(CallStatusBadge::class)->assertSet('activeCallId', $call->id);
});
