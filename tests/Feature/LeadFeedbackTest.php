<?php

use App\Livewire\Leads\LeadCreate;
use App\Livewire\Leads\PickTimes;
use App\Models\AppNotification;
use App\Models\LeadFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('stores feedback submitted from the guest scheduling page', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    Livewire::test(PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('feedbackRating', '2')
        ->set('feedbackMessage', 'It was a little clunky to work with.')
        ->call('sendFeedback', '1440x900')
        ->assertHasNoErrors()
        ->assertSet('feedbackSent', true);

    $feedback = LeadFeedback::where('lead_id', $fx['lead']->id)->first();

    expect($feedback)->not->toBeNull()
        ->and($feedback->context)->toBe('pick-times')
        ->and($feedback->rating)->toBe(2)
        ->and($feedback->message)->toBe('It was a little clunky to work with.')
        ->and($feedback->meta['viewport'] ?? null)->toBe('1440x900');
});

it('notifies vendor admins when a homeowner sends feedback', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    Livewire::test(PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('feedbackRating', '2')
        ->set('feedbackMessage', 'It was a little clunky to work with.')
        ->call('sendFeedback');

    $notification = AppNotification::where('type', 'lead_feedback_submitted')
        ->where('user_id', $fx['admin']->id)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->title)->toContain('left feedback')
        ->and($notification->body)->toContain('2/5')
        ->and($notification->body)->toContain('clunky')
        ->and($notification->data['lead_id'])->toBe($fx['lead']->id);

    Queue::assertPushed(\App\Jobs\SendBrowserNotificationsToUsers::class, fn ($job) => in_array($fx['admin']->id, $job->userIds, true)
        && str_contains($job->payload['title'], 'left feedback')
        && str_contains($job->payload['body'], '2/5'));
});

it('requires a rating or a message', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    Livewire::test(PickTimes::class, ['lead' => $fx['lead']->id])
        ->call('sendFeedback')
        ->assertHasErrors('feedback');

    expect(LeadFeedback::where('lead_id', $fx['lead']->id)->count())->toBe(0);
});

it('rejects a feedback message over 2000 characters', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    Livewire::test(PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('feedbackMessage', str_repeat('a', 2001))
        ->call('sendFeedback')
        ->assertHasErrors(['feedbackMessage' => 'max']);

    expect(LeadFeedback::where('lead_id', $fx['lead']->id)->count())->toBe(0);
});

it('caps feedback submissions at five per lead per day', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    foreach (range(1, 5) as $i) {
        Livewire::test(PickTimes::class, ['lead' => $fx['lead']->id])
            ->set('feedbackMessage', "Note {$i}")
            ->call('sendFeedback')
            ->assertHasNoErrors();
    }

    Livewire::test(PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('feedbackMessage', 'One too many')
        ->call('sendFeedback')
        ->assertHasErrors('feedback');

    expect(LeadFeedback::where('lead_id', $fx['lead']->id)->count())->toBe(5);
});

it('shows feedback on the lead in the CRM modal', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    LeadFeedback::create([
        'lead_id' => $fx['lead']->id,
        'context' => 'pick-times',
        'rating' => 2,
        'message' => 'It was a little clunky to work with.',
        'meta' => ['state' => 'picking'],
    ]);

    Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->assertSee('Feedback')
        ->assertSee('2/5')
        ->assertSee('It was a little clunky to work with.');
});
