<?php

use App\Livewire\Sms\SmsConversation;
use App\Models\User;

it('filters client users down to actual thread participants', function (): void {
    $participant = new User([
        'first_name' => 'Mary',
        'last_name' => 'Participant',
        'cell_phone' => '2245550001',
    ]);

    $nonParticipant = new User([
        'first_name' => 'Jon',
        'last_name' => 'Nonparticipant',
        'cell_phone' => '2245550002',
    ]);

    $filteredUsers = (new SmsConversation())->filterClientUsersToThreadParticipants(
        [$participant, $nonParticipant],
        ['+12245550001'],
    );

    expect($filteredUsers->pluck('first_name')->all())->toBe(['Mary']);
});

it('opens the media lightbox when a video is requested', function (): void {
    $component = new SmsConversation();

    $component->openVideoLightbox('sms-media/2026/05/demo.mp4');

    expect($component->showImageLightbox)->toBeTrue();
    expect($component->lightboxImageUrl)->toBe('sms-media/2026/05/demo.mp4');
});