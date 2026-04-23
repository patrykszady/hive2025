<?php

use App\Livewire\Sms\SmsNewThread;
use App\Models\SmsGroupThread;
use App\Models\User;

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
