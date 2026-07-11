<?php

namespace App\Console\Commands;

use App\Models\CheckImage;
use App\Services\CheckImageMatcher;
use App\Services\CheckImagePayeeResolver;
use App\Services\ContentUnderstandingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessCheckImages extends Command
{
    protected $signature = 'cu:process-check-images
                            {--limit=200     : Max images to analyze in this run}
                            {--analyze-only  : Only run the CU analyzer stage, skip matching}
                            {--match-only    : Only run the matching stage, skip analysis}
                            {--dry-run       : Preview matches without writing links}';

    protected $description = 'Analyze ingested check images with the hive_Check_1 analyzer and link each one to its Check (or cleared Transaction).';

    /**
     * The printed payer/bank block is identical on every check of an account,
     * so a minority OCR reading (e.g. one "673-7217" among fifteen
     * "873-7217") is a misread — fix it by majority vote.
     */
    private const CONSENSUS_FIELDS = [
        ['CheckInfo', 'PayerName'],
        ['CheckInfo', 'PayerAddress'],
        ['CheckInfo', 'PayerPhone'],
        ['BankInfo', 'BankName'],
        ['BankInfo', 'RoutingNumber'],
        ['BankInfo', 'AccountNumber'],
    ];

    public function handle(ContentUnderstandingService $cu, CheckImageMatcher $matcher, CheckImagePayeeResolver $payeeResolver): int
    {
        if (! $this->option('match-only')) {
            $this->analyzePending($cu);
            $this->applyPayerBlockConsensus();
        }

        if (! $this->option('analyze-only')) {
            $this->matchPending($matcher);
        }

        // After matching so linked checks can supply their payee directly.
        $this->resolvePayees($payeeResolver);

        return self::SUCCESS;
    }

    // ── Stage 2: per-check analyzer ─────────────────────────────────────────

    protected function analyzePending(ContentUnderstandingService $cu): void
    {
        $analyzerId = config('services.azure_cu.analyzer_id_check');
        $pending    = CheckImage::whereNull('analyzed_at')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No check images pending analysis.');

            return;
        }

        $this->info("Analyzing {$pending->count()} check image(s) with '{$analyzerId}'...");

        foreach ($pending as $image) {
            if (! Storage::disk('files')->exists($image->file_path)) {
                $this->warn("  {$image->image_filename}: file missing on disk, skipped");
                continue;
            }

            try {
                $raw    = $cu->analyzeBinary(Storage::disk('files')->get($image->file_path), $analyzerId, 'check_images');
                $fields = ContentUnderstandingService::normalizeFieldValues($raw['result']['contents'][0]['fields'] ?? []);
            } catch (Throwable $e) {
                $this->error("  {$image->image_filename}: analysis failed — {$e->getMessage()}");
                Log::channel('check_images')->error('Check image analysis failed', [
                    'image' => $image->image_filename,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $micrAccount = preg_replace('/\D/', '', (string) ($fields['BankInfo']['AccountNumber'] ?? ''));

            // Statement caption is authoritative for number/amount; flag drift.
            $bankNumber = $fields['CheckNumber']['Bank'] ?? null;
            if ($bankNumber !== null && $image->check_number !== null && (int) $bankNumber !== (int) $image->check_number) {
                Log::channel('check_images')->warning('Analyzer check number disagrees with statement caption', [
                    'image'     => $image->image_filename,
                    'statement' => $image->check_number,
                    'analyzer'  => $bankNumber,
                ]);
            }

            $image->update([
                'check_fields'   => $fields,
                'payee'          => $fields['CheckInfo']['Payee'] ?? null,
                'account_number' => $micrAccount !== '' ? $micrAccount : $image->account_number,
                'analyzer_id'    => $analyzerId,
                'analyzed_at'    => now(),
            ]);

            $this->line("  {$image->image_filename}: payee=" . ($image->payee ?? '—'));
        }
    }

    // ── Stage 2b: payer-block consensus across same-account images ─────────

    protected function applyPayerBlockConsensus(): void
    {
        $accounts = CheckImage::whereNotNull('analyzed_at')
            ->whereNotNull('account_number')
            ->distinct()
            ->pluck('account_number');

        foreach ($accounts as $account) {
            $images = CheckImage::whereNotNull('analyzed_at')
                ->where('account_number', $account)
                ->get();

            if ($images->count() < 3) {
                continue;
            }

            foreach (self::CONSENSUS_FIELDS as [$group, $field]) {
                $values = $images
                    ->map(fn (CheckImage $image) => $image->check_fields[$group][$field] ?? null)
                    ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')
                    ->map(fn ($v) => (string) $v);

                if ($values->count() < 3) {
                    continue;
                }

                // PHP coerces numeric-string array keys to ints, so cast the
                // winning key back to string explicitly.
                $counts   = $values->countBy();
                $topCount = $counts->max();
                $majority = (string) $counts->sortDesc()->keys()->first();

                // Require a real majority (>60%) before overriding outliers.
                if ($topCount / $values->count() <= 0.6) {
                    continue;
                }

                foreach ($images as $image) {
                    $current = $image->check_fields[$group][$field] ?? null;
                    if ($current === null || trim((string) $current) === '' || (string) $current === $majority) {
                        continue;
                    }

                    $fields                   = $image->check_fields;
                    $fields[$group][$field]   = $majority;
                    $image->update(['check_fields' => $fields]);

                    $this->line("  consensus: {$image->image_filename} {$group}.{$field} \"{$current}\" → \"{$majority}\"");
                    Log::channel('check_images')->info('Payer-block consensus correction', [
                        'image' => $image->image_filename,
                        'field' => "{$group}.{$field}",
                        'from'  => $current,
                        'to'    => $majority,
                        'votes' => "{$topCount}/{$values->count()}",
                    ]);
                }
            }
        }
    }

    // ── Stage 4: payee resolution ───────────────────────────────────────────

    protected function resolvePayees(CheckImagePayeeResolver $resolver): void
    {
        $pending = CheckImage::whereNotNull('analyzed_at')
            ->whereNull('payee_user_id')
            ->whereNull('payee_vendor_id')
            ->get();

        $resolved = 0;
        foreach ($pending as $image) {
            $result = $resolver->resolve($image);

            if ($result) {
                $resolved++;
                $entity = $result['user_id'] ? "user {$result['user_id']}" : "vendor {$result['vendor_id']}";
                $score  = $result['score'] !== null ? " ({$result['score']}%)" : '';
                $this->line("  payee: {$image->image_filename} \"{$image->payee}\" → {$entity} via {$result['source']}{$score}");
            }
        }

        if ($pending->isNotEmpty()) {
            $this->info("Payees resolved: {$resolved}/{$pending->count()}");
        }
    }

    // ── Stage 3: matching ───────────────────────────────────────────────────

    protected function matchPending(CheckImageMatcher $matcher): void
    {
        $dryRun = (bool) $this->option('dry-run');

        // Retry unmatched/ambiguous on every run (new checks/transactions may
        // have appeared); never touch manual or already-linked rows.
        $matchable = CheckImage::whereIn('match_status', [
                CheckImage::STATUS_PENDING,
                CheckImage::STATUS_UNMATCHED,
                CheckImage::STATUS_AMBIGUOUS,
            ])
            ->whereNull('check_id')
            ->whereNull('transaction_id')
            ->orderBy('id')
            ->get();

        if ($matchable->isEmpty()) {
            $this->info('No check images pending matching.');

            return;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Matching {$matchable->count()} check image(s)...");

        $rows = [];
        foreach ($matchable as $image) {
            $result = $matcher->match($image, $dryRun);

            $rows[] = [
                $image->image_filename,
                $image->check_number,
                $image->amount !== null ? number_format((float) $image->amount, 2) : '',
                $result['status'],
                $result['details']['check_id'] ?? '',
                $result['details']['transaction_id'] ?? '',
                $result['details']['reason'] ?? '',
            ];
        }

        $this->table(['Image', 'Ck #', 'Amount', 'Status', 'Check', 'Txn', 'Reason'], $rows);

        $summary = collect($rows)->countBy(fn ($r) => $r[3])
            ->map(fn ($n, $status) => "{$status}: {$n}")->join(', ');
        $this->info("Result — {$summary}");
    }
}
