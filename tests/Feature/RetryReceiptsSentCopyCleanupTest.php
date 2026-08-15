<?php

use App\Jobs\RetryReceiptsSentCopyCleanup;
use App\Services\NylasService;

it('succeeds silently when the delayed move works', function (): void {
    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('moveOrDeleteMessage')
        ->once()
        ->with('msg-1', 'grant-1', 5, 'folder-1')
        ->andReturnTrue();

    (new RetryReceiptsSentCopyCleanup('msg-1', 'grant-1', 5, 'folder-1'))->handle($nylas);

    expect(true)->toBeTrue();
});

it('throws so the queue retries when the move still fails', function (): void {
    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('moveOrDeleteMessage')->once()->andReturnFalse();

    (new RetryReceiptsSentCopyCleanup('msg-1', 'grant-1', 5, 'folder-1'))->handle($nylas);
})->throws(RuntimeException::class);
