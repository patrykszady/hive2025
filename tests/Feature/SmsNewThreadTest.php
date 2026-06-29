<?php

use App\Livewire\Sms\SmsNewThread;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('builds per-user and combined recipient presets and maps existing thread ids', function (): void {
    $jill = (new User())->forceFill([
        'first_name' => 'Jill',
        'last_name' => 'Meier',
        'cell_phone' => '2245550001',
    ]);

    $jon = (new User())->forceFill([
        'first_name' => 'Jon',
        'last_name' => 'Meier',
        'cell_phone' => '2245550002',
    ]);

    $existingThread = new SmsGroupThread([
        'participants' => ['+12245550001'],
    ]);
    $existingThread->id = 12;

    $options = (new SmsNewThread())->buildRecipientPresetOptions(
        [$jill, $jon],
        [$existingThread],
    );

    expect($options)->toHaveCount(3);

    $labels = collect($options)->pluck('label')->all();

    expect($labels)->toContain('Jill Meier')
        ->and($labels)->toContain('Jon Meier')
        ->and($labels)->toContain('Jill Meier & Jon Meier');

    $jillOption = collect($options)->firstWhere('label', 'Jill Meier');
    $groupOption = collect($options)->firstWhere('label', 'Jill Meier & Jon Meier');

    expect($jillOption['existingThreadId'])->toBe(12)
        ->and($groupOption['existingThreadId'])->toBeNull();
});

it('builds vendor recipient presets from business phone and vendor users', function (): void {
    $vendor = (new Vendor())->forceFill([
        'business_name' => 'Acme Subs Inc',
        'business_phone' => '2245559999',
    ]);
    $vendor->setRelation('users', collect([
        (new User())->forceFill([
            'first_name' => 'Sam',
            'last_name' => 'Sub',
            'cell_phone' => '2245558888',
        ]),
    ]));

    $existingThread = new SmsGroupThread([
        'participants' => ['+12245559999'],
    ]);
    $existingThread->id = 77;

    $options = (new SmsNewThread())->buildVendorRecipientPresetOptions($vendor, [$existingThread]);

    expect($options)->toHaveCount(3);

    $businessOption = collect($options)->first(
        fn (array $option): bool => collect($option['recipients'])->pluck('number')->all() === ['+12245559999']
    );
    $samOption = collect($options)->first(
        fn (array $option): bool => collect($option['recipients'])->pluck('number')->all() === ['+12245558888']
    );
    $groupOption = collect($options)->first(
        fn (array $option): bool => count($option['recipients']) === 2
    );

    expect($businessOption)->not->toBeNull()
        ->and($businessOption['existingThreadId'])->toBe(77)
        ->and($samOption['label'])->toBe('Sam Sub')
        ->and($samOption['existingThreadId'])->toBeNull()
        ->and($groupOption['existingThreadId'])->toBeNull();
});

it('sends a vendor thread without validating stale client state', function (): void {
    $ownerVendor = Vendor::factory()->create();
    $subjectVendor = Vendor::factory()->create();
    $user = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Tester',
        'email' => 'patryk.sms-test@example.com',
        'cell_phone' => '2245551000',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $thread = new SmsGroupThread();
    $thread->id = 123;

    $service = mock(GroupSmsService::class);
    $service->shouldReceive('sendNewGroup')
        ->once()
        ->with(
            ['+12245550001'],
            'Vendor consent message',
            null,
            null,
            $user->id,
            $ownerVendor->id,
            $subjectVendor->id,
        )
        ->andReturn($thread);

    app()->instance(GroupSmsService::class, $service);

    $this->actingAs($user);

    Livewire::test(SmsNewThread::class)
        ->set('showModal', true)
        ->set('recipientType', 'vendor')
        ->set('clientId', 999999)
        ->set('vendorId', $subjectVendor->id)
        ->set('recipients', [[
            'number' => '+12245550001',
            'display' => '(224) 555-0001',
            'label' => 'Pawel Bach',
        ]])
        ->set('message', 'Vendor consent message')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('showModal', false)
        ->assertDispatched('threadCreated', threadId: 123);
});

it('sent vendor consent message uses first name for recipient greeting', function (): void {
    $ownerVendor = Vendor::factory()->create();
    $subjectVendor = Vendor::factory()->create();
    $user = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Tester',
        'email' => 'patryk.sms-test@example.com',
        'cell_phone' => '2245551000',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    // Mock the service to capture the message being sent
    $thread = new SmsGroupThread();
    $thread->id = 456;

    $service = mock(GroupSmsService::class);
    $service->shouldReceive('sendNewGroup')
        ->once()
        ->andReturnUsing(function ($phones, $message) use ($thread) {
            expect($message)->toContain('Hi Jaroslaw,');
            return $thread;
        });

    app()->instance(GroupSmsService::class, $service);

    $this->actingAs($user);

    Livewire::test(SmsNewThread::class)
        ->set('recipientType', 'vendor')
        ->set('vendorId', $subjectVendor->id)
        ->set('recipients', [[
            'number' => '+12245550001',
            'display' => '(224) 555-0001',
            'label' => 'Jaroslaw Potapa',
        ]])
        ->set('message', "Hi Jaroslaw,\n" . GroupSmsService::START_CONSENT_TEXT)
        ->call('send')
        ->assertHasNoErrors();
});

it('uses vendor participant nickname for consent greeting when available', function (): void {
    Queue::fake();

    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Stan Palupski Construction',
        'business_phone' => '2245559900',
    ]);

    $ownerUser = User::query()->create([
        'first_name' => 'Stanislaw',
        'last_name' => 'Palupski',
        'nickname' => 'Stan',
        'email' => 'stan.owner@example.com',
        'cell_phone' => '2245558800',
        'primary_vendor_id' => $subjectVendor->id,
    ]);

    $subjectVendor->users()->attach($ownerUser->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $thread = app(GroupSmsService::class)->sendNewGroup(
        ['2245559900', '2245558800'],
        'Ignored manual text',
        null,
        null,
        null,
        $ownerVendor->id,
        $subjectVendor->id,
    );

    $consentMessage = SmsMessage::query()
        ->where('thread_id', $thread->id)
        ->firstOrFail();

    expect($consentMessage->text)
        ->toStartWith("Hi Stan,\n" . GroupSmsService::START_CONSENT_TEXT)
        ->and($consentMessage->text)->not->toContain('Stanislaw')
        ->and($consentMessage->text)->not->toContain('Stan Palupski Construction');
});

it('uses nickname-first labels in vendor recipient presets', function (): void {
    $vendor = Vendor::factory()->create([
        'business_name' => 'Stan Palupski Construction',
        'business_phone' => '2245559900',
    ]);

    $user = User::query()->create([
        'first_name' => 'Stanislaw',
        'last_name' => 'Palupski',
        'nickname' => 'Stan',
        'email' => 'stan.preset@example.com',
        'cell_phone' => '2245558800',
        'primary_vendor_id' => $vendor->id,
    ]);

    $vendor->users()->attach($user->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $options = (new SmsNewThread())->buildVendorRecipientPresetOptions($vendor, []);

    $labels = collect($options)->pluck('label')->all();

    expect($labels)->toContain('Stan Palupski')
        ->and(collect($labels)->join(' | '))->not->toContain('Stanislaw Palupski');
});
