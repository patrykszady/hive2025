<?php

use App\Models\Vendor;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

function chicagoVendor(): Vendor
{
    return new Vendor([
        'business_name' => 'GS Construction',
        'timezone' => 'America/Chicago',
        // defaults: 07:00–18:00, Mon–Fri
    ]);
}

it('sends immediately during business hours', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00', 'America/Chicago')); // Wednesday

    $opening = chicagoVendor()->nextBusinessHoursOpening();

    expect($opening->isSameSecond(Carbon::now('America/Chicago')))->toBeTrue();
});

it('holds an evening payment email until 7 AM next morning', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-12 21:30', 'America/Chicago')); // Wednesday night

    $opening = chicagoVendor()->nextBusinessHoursOpening();

    expect($opening->toDateTimeString())->toBe('2026-08-13 07:00:00')
        ->and($opening->timezoneName)->toBe('America/Chicago');
});

it('holds a pre-dawn payment until the same morning', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-13 05:15', 'America/Chicago')); // Thursday 5 AM

    expect(chicagoVendor()->nextBusinessHoursOpening()->toDateTimeString())
        ->toBe('2026-08-13 07:00:00');
});

it('rolls a Friday-night payment to Monday morning', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-14 20:00', 'America/Chicago')); // Friday night

    expect(chicagoVendor()->nextBusinessHoursOpening()->toDateTimeString())
        ->toBe('2026-08-17 07:00:00'); // Monday
});

it('respects custom hours and days from vendor options', function (): void {
    $vendor = chicagoVendor();
    $vendor->options = [
        'business_hours_start' => '09:30',
        'business_hours_end' => '15:00',
        'business_hours_days' => [2, 4], // Tue + Thu only
    ];

    Carbon::setTestNow(Carbon::parse('2026-08-12 12:00', 'America/Chicago')); // Wednesday noon

    expect($vendor->nextBusinessHoursOpening()->toDateTimeString())
        ->toBe('2026-08-13 09:30:00'); // Thursday
});

it('computes the opening in the vendor timezone regardless of app timezone', function (): void {
    // 8 PM Chicago == 1 AM UTC next day; the opening must still be 7 AM Chicago.
    Carbon::setTestNow(Carbon::parse('2026-08-13 01:00', 'UTC')); // Wed 8 PM Chicago

    $opening = chicagoVendor()->nextBusinessHoursOpening();

    expect($opening->toDateTimeString())->toBe('2026-08-13 07:00:00')
        ->and($opening->timezoneName)->toBe('America/Chicago');
});

it('queues the payment email with the morning delay attached', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-12 21:30', 'America/Chicago')); // Wednesday night
    Illuminate\Support\Facades\Queue::fake();

    $payer = new Vendor(['business_name' => 'GS', 'timezone' => 'America/Chicago']);
    $sub = new Vendor(['business_name' => 'Sub Co', 'business_email' => 'sub@example.com']);
    $user = new App\Models\User(['first_name' => 'Patryk']);
    $check = new App\Models\Check(['amount' => 100]);

    App\Jobs\SendVendorPaymentEmailJob::dispatch($user, $sub, $check)
        ->delay($payer->nextBusinessHoursOpening());

    Illuminate\Support\Facades\Queue::assertPushed(
        App\Jobs\SendVendorPaymentEmailJob::class,
        fn ($job) => Carbon::instance($job->delay)->toDateTimeString() === '2026-08-13 07:00:00'
    );
});
