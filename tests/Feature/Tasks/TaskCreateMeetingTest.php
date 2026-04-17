<?php

use App\Livewire\Forms\TaskForm;
use App\Livewire\Tasks\TaskCreate;

it('defaults meeting location type to in_person', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');

    expect($form->meeting_location_type)->toBe('in_person');
});

it('can set meeting location type to virtual', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');
    $form->meeting_location_type = 'virtual';

    expect($form->meeting_location_type)->toBe('virtual');
});

it('starts with empty meeting participants', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');

    expect($form->meeting_participants)->toBe([]);
});

it('meeting_location_type only accepts valid values', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');

    $form->meeting_location_type = 'virtual';
    expect($form->meeting_location_type)->toBe('virtual');

    $form->meeting_location_type = 'in_person';
    expect($form->meeting_location_type)->toBe('in_person');
});

it('meeting_participants is an array', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');
    $form->meeting_participants = ['john@example.com', 'jane@example.com'];

    expect($form->meeting_participants)
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain('john@example.com')
        ->toContain('jane@example.com');
});

it('addMeetingParticipant validates email and adds to list', function (): void {
    $component = app(TaskCreate::class);

    // Use reflection to initialize the form property
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('john@example.com');
    expect($component->form->meeting_participants)->toBe(['john@example.com']);
});

it('addMeetingParticipant normalizes email to lowercase', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('John@Example.COM');
    expect($component->form->meeting_participants)->toBe(['john@example.com']);
});

it('addMeetingParticipant prevents duplicates', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('john@example.com');
    $component->addMeetingParticipant('john@example.com');

    expect($component->form->meeting_participants)->toBe(['john@example.com']);
});

it('addMeetingParticipant rejects invalid emails', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('not-an-email');
    expect($component->form->meeting_participants)->toBe([]);
});

it('addMeetingParticipant rejects empty string', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('');
    expect($component->form->meeting_participants)->toBe([]);
});

it('removeMeetingParticipant removes by index and reindexes', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('john@example.com');
    $component->addMeetingParticipant('jane@example.com');
    $component->addMeetingParticipant('bob@example.com');

    $component->removeMeetingParticipant(1);

    expect($component->form->meeting_participants)
        ->toBe(['john@example.com', 'bob@example.com']);
});
