<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Imports one batch of Menards receipts that the browser extension posted.
 *
 * Queued rather than run inline, and not as an optimisation. The importer OCRs
 * every receipt through Azure Content Understanding, a couple of seconds each: a
 * 277-receipt backfill took about twenty minutes. Run inside the ingest request
 * that work outlived the HTTP layer, which gave up and left the batch half
 * imported with nothing recording how far it had got — the extension saw a dead
 * connection, the log never reached "finished", and 43 expenses ended up with a
 * receipt attached twice once the batch was re-run by hand.
 *
 * Small batches finish in seconds and would survive inline, but the size of a
 * batch is set by how long the scraper has been down, not by us. So it is always
 * queued.
 *
 * On the auto-receipts queue, alongside ProcessAutoReceiptMailboxJob: same kind
 * of work (import a receipt, OCR it, attach it to an expense) and the same need
 * for a long timeout. The default queue would be wrong — its supervisor times
 * out at 60 seconds, which would reintroduce the very failure this job exists to
 * avoid. maxProcesses is 1 there, so batches import one at a time.
 */
class ImportMenardsReceiptBatch implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Safe to retry: the importer skips receipts already attached to an expense. */
    public int $tries = 2;

    /**
     * Thirty minutes, matching the auto-receipts supervisor and deliberately
     * under the redis connection's 2400s retry_after. A job that outlives
     * retry_after is handed to a second worker while the first is still running,
     * and two workers importing one batch is precisely how a receipt gets
     * attached to the same expense twice.
     */
    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public string $directory)
    {
        $this->onQueue('auto-receipts');
    }

    public function handle(): void
    {
        if (! is_dir($this->directory)) {
            Log::channel('menards')->error('Menards import: batch directory is gone', [
                'dir' => $this->directory,
            ]);

            return;
        }

        // No --force: receipts already attached to an expense hit the exists
        // branch and cost nothing, which is what makes an overlapping window safe.
        $exit = Artisan::call('menards:scrape-receipts', [
            '--skip-scrape' => true,
            '--match-expenses' => true,
            '--output-dir' => $this->directory,
        ]);

        Log::channel('menards')->info('Menards import: finished', [
            'dir' => basename($this->directory),
            'exit_code' => $exit,
            'tail' => substr(Artisan::output(), -800),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('menards')->error('Menards import: job failed', [
            'dir' => basename($this->directory),
            'error' => $e->getMessage(),
        ]);
    }

    /** One import per batch directory, however many times the extension posts it. */
    public function uniqueId(): string
    {
        return 'menards-import-' . basename($this->directory);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['auto-receipts', 'menards', 'batch:' . basename($this->directory)];
    }
}
