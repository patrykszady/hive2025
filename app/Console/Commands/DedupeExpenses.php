<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DedupeExpenses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --since: ISO date to start scanning from (default: 2024-01-01)
     * --limit: Max number of duplicate groups to process (default: 50)
     * --execute: Actually perform changes; otherwise dry-run
     */
    protected $signature = 'expenses:dedupe
        {--since=2024-01-01 : Only consider expenses on/after this date}
        {--limit=50 : Limit number of duplicate groups processed}
        {--execute : Perform writes; otherwise dry-run}';

    /**
     * The console command description.
     */
    protected $description = 'Merge exact duplicate expenses (same vendor, same date, same amount).';

    public function handle(): int
    {
        $since = Carbon::parse($this->option('since'))->startOfDay()->toDateString();
        $limit = (int) $this->option('limit');
        $execute = (bool) $this->option('execute');

        $this->info(sprintf('Scanning for duplicate groups since %s (limit %d groups). Mode: %s', $since, $limit, $execute ? 'EXECUTE' : 'DRY-RUN'));

        // Find duplicate groups by (belongs_to_vendor_id, vendor_id, amount, date)
        $groups = DB::table('expenses')
            ->select('belongs_to_vendor_id', 'vendor_id', 'amount', 'date', DB::raw('COUNT(*) as cnt'), DB::raw("GROUP_CONCAT(id ORDER BY id SEPARATOR ',') as expense_ids"))
            ->whereNull('deleted_at')
            ->where('date', '>=', $since)
            ->groupBy('belongs_to_vendor_id', 'vendor_id', 'amount', 'date')
            ->having('cnt', '>', 1)
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate groups found.');
            return self::SUCCESS;
        }

        $summary = [
            'groups' => 0,
            'merged_expenses' => 0,
            'transactions_moved' => 0,
            'receipts_moved' => 0,
        ];

        foreach ($groups as $g) {
            $ids = collect(explode(',', $g->expense_ids))
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values();

            if ($ids->count() < 2) {
                continue;
            }

            $summary['groups']++;

            // Choose the keeper as the smallest id for determinism.
            $keeperId = $ids->min();
            $dupeIds = $ids->reject(fn ($id) => $id === $keeperId)->values();

            /** @var Expense $keeper */
            $keeper = Expense::query()->find($keeperId);
            if (! $keeper) {
                $this->warn("Keeper expense {$keeperId} not found; skipping group.");
                continue;
            }

            $this->line(sprintf('Group: vendor=%d amount=%s date=%s -> keeper #%d; duplicates: %s',
                $g->vendor_id, $g->amount, $g->date, $keeperId, $dupeIds->implode(', ')
            ));

            // Compute actions in-memory for dry-run visibility
            $txToMove = Transaction::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereIn('expense_id', $dupeIds)
                ->get();

            $receiptsToMove = ExpenseReceipts::query()
                ->whereIn('expense_id', $dupeIds)
                ->get();

            $this->line(sprintf('- Would move %d transactions and %d receipts to keeper #%d', $txToMove->count(), $receiptsToMove->count(), $keeperId));

            if (! $execute) {
                // Dry-run: show brief merge field decisions
                $mergePreview = $this->buildMergePreview($keeper, $dupeIds);
                if ($mergePreview) {
                    $this->line('- Keeper field updates: ' . json_encode($mergePreview));
                }
                $summary['merged_expenses'] += $dupeIds->count();
                $summary['transactions_moved'] += $txToMove->count();
                $summary['receipts_moved'] += $receiptsToMove->count();
                continue;
            }

            DB::transaction(function () use ($keeper, $dupeIds, $txToMove, $receiptsToMove, &$summary) {
                // 1) Reassign transactions to keeper
                if ($txToMove->isNotEmpty()) {
                    Transaction::withoutGlobalScopes()
                        ->whereIn('id', $txToMove->pluck('id'))
                        ->update(['expense_id' => $keeper->id]);
                    $summary['transactions_moved'] += $txToMove->count();
                }

                // 2) Reassign receipts to keeper
                if ($receiptsToMove->isNotEmpty()) {
                    ExpenseReceipts::query()
                        ->whereIn('id', $receiptsToMove->pluck('id'))
                        ->update(['expense_id' => $keeper->id]);
                    $summary['receipts_moved'] += $receiptsToMove->count();
                }

                // 3) Merge simple fields from dupes into keeper if keeper lacks them
                $merge = $this->buildMergePreview($keeper, $dupeIds);
                $dirty = false;
                if (isset($merge['invoice']) && $merge['invoice'] !== $keeper->invoice) {
                    $keeper->invoice = $merge['invoice'];
                    $dirty = true;
                }
                if (isset($merge['note']) && $merge['note'] !== ($keeper->note ?? null)) {
                    $keeper->note = $merge['note'];
                    $dirty = true;
                }
                if ($dirty) {
                    $keeper->timestamps = false; // retain original timestamps
                    $keeper->save();
                }

                // 4) Soft-delete duplicates
                Expense::query()->whereIn('id', $dupeIds)->update(['deleted_at' => now()]);
                $summary['merged_expenses'] += $dupeIds->count();
            });
        }

        $this->newLine();
        $this->info(sprintf('Done. Groups processed: %d | Duplicates merged: %d | Transactions moved: %d | Receipts moved: %d',
            $summary['groups'], $summary['merged_expenses'], $summary['transactions_moved'], $summary['receipts_moved']
        ));

        return self::SUCCESS;
    }

    /**
     * Decide which fields to bring over to keeper if missing.
     * For safety, we only bring invoice and note when the keeper lacks them.
     * If multiple dupes have conflicting values, prefer the earliest created id.
     *
     * @return array<string, mixed>
     */
    protected function buildMergePreview(Expense $keeper, Collection $dupeIds): array
    {
        $result = [];
        // Pull dupes ordered by id
        $dupes = Expense::query()->whereIn('id', $dupeIds)->orderBy('id')->get(['id', 'invoice', 'note']);

        if (empty($keeper->invoice)) {
            $candidate = $dupes->first(fn ($e) => !empty($e->invoice));
            if ($candidate) {
                $result['invoice'] = $candidate->invoice;
            }
        }

        if (empty($keeper->note)) {
            $candidate = $dupes->first(fn ($e) => !empty($e->note));
            if ($candidate) {
                $result['note'] = $candidate->note;
            }
        }

        return $result;
    }
}
