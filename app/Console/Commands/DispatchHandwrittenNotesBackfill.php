<?php

namespace App\Console\Commands;

use App\Jobs\BackfillReceiptHandwrittenNoteJob;
use App\Models\ExpenseReceipts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DispatchHandwrittenNotesBackfill extends Command
{
    protected $signature = 'receipts:dispatch-handwritten-notes-backfill
                            {--days=60 : Look-back window in days}
                            {--limit=0 : Maximum receipts to dispatch (0 = no cap)}
                            {--only-new : Only dispatch receipts with empty handwritten_notes}
                            {--queue=background : Queue name used by Horizon workers}';

    protected $description = 'Dispatch per-receipt jobs to backfill handwritten_notes in parallel.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $onlyNew = (bool) $this->option('only-new');
        $queue = (string) $this->option('queue');

        $cutoff = Carbon::now()->subDays($days);

        $query = ExpenseReceipts::query()
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('receipt_filename')
            ->where('receipt_filename', '!=', '')
            ->orderBy('id');

        $total = $this->countWithLimit(clone $query, $limit);
        if ($total === 0) {
            $this->info('No matching receipts found for dispatch.');
            return self::SUCCESS;
        }

        $this->info("Dispatching handwritten_notes backfill jobs for last {$days} days.");
        $this->line("Matching receipts: {$total}" . ($onlyNew ? '  [ONLY_NEW]' : ''));

        $dispatched = 0;
        $skippedOnlyNew = 0;

        $query->chunkById(500, function ($receipts) use (&$dispatched, &$skippedOnlyNew, $onlyNew, $queue, $limit) {
            foreach ($receipts as $receipt) {
                if ($limit > 0 && $dispatched >= $limit) {
                    return false;
                }

                if ($onlyNew) {
                    $items = $receipt->receipt_items;
                    if (! is_array($items)) {
                        $items = (array) ($items ?? []);
                    }

                    $currentNotes = isset($items['handwritten_notes']) ? (array) $items['handwritten_notes'] : [];
                    if (! empty($currentNotes)) {
                        $skippedOnlyNew++;
                        continue;
                    }
                }

                BackfillReceiptHandwrittenNoteJob::dispatch($receipt->id, $onlyNew)->onQueue($queue);
                $dispatched++;
            }

            return null;
        });

        $this->newLine();
        $this->info('Done dispatching.');
        $this->line("  dispatched jobs : {$dispatched}");
        $this->line("  skipped (ONLY_NEW had notes): {$skippedOnlyNew}");
        $this->line("  queue           : {$queue}");

        return self::SUCCESS;
    }

    private function countWithLimit(Builder $query, int $limit): int
    {
        if ($limit <= 0) {
            return $query->count();
        }

        return (int) min($limit, $query->count());
    }
}
