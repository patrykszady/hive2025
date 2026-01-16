<?php

uses(Tests\TestCase::class);

use App\Channels\TwilioChannel;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use App\Notifications\ClientScheduleSmsNotification;
use App\Notifications\TeamTaskSmsNotification;
use App\Notifications\VendorAvailabilitySmsNotification;
use App\Notifications\VendorScheduleSmsNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

it('routes SMS notifications via Twilio', function () {
    $project = new Project();
    /** @var Collection<int, Task> $tasks */
    $tasks = collect();

    expect((new ClientScheduleSmsNotification($project, 'Pat', 'today', $tasks))->via(new stdClass()))
        ->toBe([TwilioChannel::class]);

    expect((new TeamTaskSmsNotification([], Carbon::now(), 'reminder'))->via(new stdClass()))
        ->toBe([TwilioChannel::class]);

    expect((new VendorScheduleSmsNotification(new Vendor(), $tasks, Carbon::now(), 'today'))->via(new stdClass()))
        ->toBe([TwilioChannel::class]);

    expect((new VendorAvailabilitySmsNotification(new Task(), []))->via(new stdClass()))
        ->toBe([TwilioChannel::class]);
});
