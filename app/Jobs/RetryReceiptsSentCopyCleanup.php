<?php

namespace App\Jobs;

use App\Services\NylasService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Declutter retry: moving a just-forwarded receipt's sent copy to Deleted
 * Items sometimes fails with Exchange's transient ErrorMoveCopyFailed (the
 * item is still locked seconds after sending, and Nylas surfaces it as a
 * 504 the inline retry deliberately skips). Minutes later the move succeeds,
 * so retry here on a delay instead of leaving stray copies in Sent.
 */
class RetryReceiptsSentCopyCleanup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function __construct(
        public string $sentMessageId,
        public string $receiptsGrantId,
        public int $companyEmailId,
        public string $deletedFolderId,
    ) {}

    public function handle(NylasService $nylas): void
    {
        $moved = $nylas->moveOrDeleteMessage(
            $this->sentMessageId,
            $this->receiptsGrantId,
            $this->companyEmailId,
            $this->deletedFolderId,
        );

        if (! $moved) {
            throw new RuntimeException('Sent-copy cleanup move failed; queue will retry');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('nylas')->warning('Could not move forwarded sent copy to deleted folder (gave up after delayed retries)', [
            'sent_message_id' => $this->sentMessageId,
            'receipts_grant_id' => $this->receiptsGrantId,
            'deleted_folder_id' => $this->deletedFolderId,
        ]);
    }
}
