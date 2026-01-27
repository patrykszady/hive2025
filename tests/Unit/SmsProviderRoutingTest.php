<?php

uses(Tests\TestCase::class);

use App\Channels\TelnyxChannel;
use App\Channels\TwilioChannel;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use App\Notifications\ClientScheduleSmsNotification;
use App\Notifications\TeamTaskSmsNotification;
use App\Notifications\VendorAvailabilitySmsNotification;
use App\Notifications\VendorScheduleSmsNotification;
use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Support\Collection;

it('routes SMS notifications via configured provider', function () {
    $project = new Project();
    /** @var Collection<int, Task> $tasks */
    $tasks = collect();

    $expectedChannel = SmsChannel::get();

    expect((new ClientScheduleSmsNotification($project, 'Pat', 'today', $tasks))->via(new stdClass()))
        ->toBe([$expectedChannel]);

    expect((new TeamTaskSmsNotification([], Carbon::now(), 'reminder'))->via(new stdClass()))
        ->toBe([$expectedChannel]);

    expect((new VendorScheduleSmsNotification(new Vendor(), $tasks, Carbon::now(), 'today'))->via(new stdClass()))
        ->toBe([$expectedChannel]);

    expect((new VendorAvailabilitySmsNotification(new Task(), []))->via(new stdClass()))
        ->toBe([$expectedChannel]);
});

it('uses Telnyx channel by default', function () {
    config(['services.sms.provider' => 'telnyx']);
    
    expect(SmsChannel::get())->toBe(TelnyxChannel::class);
});

it('can switch to Twilio channel', function () {
    config(['services.sms.provider' => 'twilio']);
    
    expect(SmsChannel::get())->toBe(TwilioChannel::class);
});
