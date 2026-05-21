<?php

use App\Livewire\Forms\TaskForm;
use App\Livewire\Tasks\TaskCreate;
use Livewire\Livewire;

it('does not propagate start_time changes to other dates when both have arrival times enabled', function (): void {
    $may19 = '2026-05-19';
    $may20 = '2026-05-20';

    $component = Livewire::test(TaskCreate::class)
        ->set('form.dates', [$may19, $may20])
        ->set('form.time_settings', [
            $may19 => ['use_time' => true, 'start_time' => '07:00', 'end_time' => '07:00'],
            $may20 => ['use_time' => true, 'start_time' => '07:00', 'end_time' => '07:00'],
        ])
        ->set("form.time_settings.{$may20}.start_time", '09:00');

    expect($component->get("form.time_settings.{$may19}.start_time"))->toBe('07:00')
        ->and($component->get("form.time_settings.{$may19}.end_time"))->toBe('07:00')
        ->and($component->get("form.time_settings.{$may20}.start_time"))->toBe('09:00');
});

it('does not propagate start_time changes when only one date has arrival times enabled', function (): void {
    $may19 = '2026-05-19';
    $may20 = '2026-05-20';

    $component = Livewire::test(TaskCreate::class)
        ->set('form.dates', [$may19, $may20])
        ->set('form.time_settings', [
            $may19 => ['use_time' => false, 'start_time' => '07:00', 'end_time' => '07:00'],
            $may20 => ['use_time' => true, 'start_time' => '07:00', 'end_time' => '07:00'],
        ])
        ->set("form.time_settings.{$may20}.start_time", '09:00');

    expect($component->get("form.time_settings.{$may19}.start_time"))->toBe('07:00')
        ->and($component->get("form.time_settings.{$may19}.end_time"))->toBe('07:00');
});

it('mirrors end_time to start_time on the same date when start_time changes', function (): void {
    $may20 = '2026-05-20';

    $component = Livewire::test(TaskCreate::class)
        ->set('form.dates', [$may20])
        ->set('form.time_settings', [
            $may20 => ['use_time' => true, 'start_time' => '07:00', 'end_time' => '07:00'],
        ])
        ->set("form.time_settings.{$may20}.start_time", '09:30');

    expect($component->get("form.time_settings.{$may20}.start_time"))->toBe('09:30')
        ->and($component->get("form.time_settings.{$may20}.end_time"))->toBe('09:30');
});
