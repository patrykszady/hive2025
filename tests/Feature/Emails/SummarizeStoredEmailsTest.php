<?php

/**
 * `emails:summarize` — queues SummarizeEmailMessage for stored stakeholder
 * email, used once after a mailbox's first sync (2026-10-19).
 */

use App\Jobs\SummarizeEmailMessage;
use App\Models\EmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues every stored email by default', function () {
    Queue::fake();
    $a = EmailMessage::factory()->create(['sent_at' => '2026-10-01 09:00:00', 'summarized_at' => now()]);
    $b = EmailMessage::factory()->create(['sent_at' => '2026-10-05 09:00:00', 'summarized_at' => null]);

    $this->artisan('emails:summarize')->assertExitCode(0);

    Queue::assertPushed(SummarizeEmailMessage::class, fn (SummarizeEmailMessage $job) => $job->emailMessageId === $a->id);
    Queue::assertPushed(SummarizeEmailMessage::class, fn (SummarizeEmailMessage $job) => $job->emailMessageId === $b->id);
    Queue::assertPushed(SummarizeEmailMessage::class, 2);
});

it('with --missing, only queues email that has never been summarized', function () {
    Queue::fake();
    $done = EmailMessage::factory()->create(['sent_at' => '2026-10-01 09:00:00', 'summarized_at' => now()]);
    $missing = EmailMessage::factory()->create(['sent_at' => '2026-10-05 09:00:00', 'summarized_at' => null]);

    $this->artisan('emails:summarize', ['--missing' => true])->assertExitCode(0);

    Queue::assertPushed(SummarizeEmailMessage::class, 1);
    Queue::assertPushed(SummarizeEmailMessage::class, fn (SummarizeEmailMessage $job) => $job->emailMessageId === $missing->id);
    Queue::assertNotPushed(SummarizeEmailMessage::class, fn (SummarizeEmailMessage $job) => $job->emailMessageId === $done->id);
});

it('with --since, only queues email sent on or after that date', function () {
    Queue::fake();
    $before = EmailMessage::factory()->create(['sent_at' => '2026-09-01 09:00:00']);
    $after = EmailMessage::factory()->create(['sent_at' => '2026-10-05 09:00:00']);

    $this->artisan('emails:summarize', ['--since' => '2026-10-01'])->assertExitCode(0);

    Queue::assertPushed(SummarizeEmailMessage::class, 1);
    Queue::assertPushed(SummarizeEmailMessage::class, fn (SummarizeEmailMessage $job) => $job->emailMessageId === $after->id);
    Queue::assertNotPushed(SummarizeEmailMessage::class, fn (SummarizeEmailMessage $job) => $job->emailMessageId === $before->id);
});

it('with --limit, queues at most that many, oldest first', function () {
    Queue::fake();
    $first = EmailMessage::factory()->create(['sent_at' => '2026-10-01 09:00:00']);
    EmailMessage::factory()->create(['sent_at' => '2026-10-02 09:00:00']);
    EmailMessage::factory()->create(['sent_at' => '2026-10-03 09:00:00']);

    $this->artisan('emails:summarize', ['--limit' => 1])->assertExitCode(0);

    Queue::assertPushed(SummarizeEmailMessage::class, 1);
    Queue::assertPushed(SummarizeEmailMessage::class, fn (SummarizeEmailMessage $job) => $job->emailMessageId === $first->id);
});
