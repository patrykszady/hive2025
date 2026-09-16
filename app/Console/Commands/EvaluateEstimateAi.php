<?php

namespace App\Console\Commands;

use App\Models\EstimateAiDraft;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Services\EstimateAIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Scores the AI estimate generator against the company's own signed work:
 * feed a past enquiry back in, with its own section hidden from the
 * examples, and compare the draft with what was actually billed. Run it
 * before and after any change to the prompt, the rules or the retrieval.
 *
 * Each case is a real Claude call.
 */
class EvaluateEstimateAi extends Command
{
    protected $signature = 'estimates:ai-eval
        {--vendor=1 : The company whose history is used}
        {--limit=20 : Cases to run}
        {--file= : JSON list of {"section_id": 12, "inquiry": "..."} cases instead of the recorded drafts}
        {--report= : Where to write the JSON report (default storage/app/estimate-ai/eval-<time>.json)}
        {--dry-run : List the cases and stop}';

    protected $description = 'Score AI estimate drafts against past sections (precision, recall, quantities, total)';

    public function handle(EstimateAIService $service): int
    {
        $vendorId = (int) $this->option('vendor');
        $limit = max(1, (int) $this->option('limit'));
        $cases = $this->cases($vendorId, $limit);

        if ($cases === []) {
            $this->warn('No cases: draft a few estimates with the generator first, or pass --file.');

            return self::FAILURE;
        }

        $this->info(count($cases).' case'.(count($cases) === 1 ? '' : 's').' for vendor '.$vendorId.($this->option('dry-run') ? ' (dry run)' : ', one Claude call each'));

        if ($this->option('dry-run')) {
            $this->table(['Section', 'Name', 'Lines', 'Inquiry'], array_map(fn ($c) => [$c['section_id'], $c['name'], $c['line_count'], mb_substr($c['inquiry'], 0, 70)], $cases));

            return self::SUCCESS;
        }

        $rows = [];
        $results = [];

        foreach ($cases as $case) {
            $actual = EstimateLineItem::query()->where('section_id', $case['section_id'])->get();
            $result = $service->generateEstimate($case['inquiry'], $case['floorplan'], $vendorId, null, [$case['section_id']]);
            $score = self::score($result['line_items'] ?? [], $actual);
            $score['section_id'] = $case['section_id'];
            $score['name'] = $case['name'];
            $score['success'] = (bool) ($result['success'] ?? false);
            $score['error'] = $result['error'] ?? null;
            $score['drafted'] = collect($result['line_items'] ?? [])->map(fn ($i) => ['line_item_id' => $i['line_item_id'], 'name' => $i['name'], 'quantity' => $i['quantity']])->all();
            $results[] = $score;

            $rows[] = [
                $case['section_id'],
                mb_substr($case['name'], 0, 24),
                $score['actual_count'],
                $score['drafted_count'],
                self::pct($score['precision']),
                self::pct($score['recall']),
                self::pct($score['f1']),
                $score['quantity_mape'] === null ? '-' : self::pct($score['quantity_mape']),
                self::pct($score['total_error']),
                $score['success'] ? '' : mb_substr((string) $score['error'], 0, 30),
            ];
        }

        $this->table(['Section', 'Name', 'Actual', 'Drafted', 'Precision', 'Recall', 'F1', 'Qty err', 'Total err', 'Error'], $rows);

        $summary = self::summarize($results);
        $this->info(sprintf(
            'Mean precision %s · recall %s · F1 %s · quantity error %s · total error %s over %d cases',
            self::pct($summary['precision']), self::pct($summary['recall']), self::pct($summary['f1']),
            $summary['quantity_mape'] === null ? '-' : self::pct($summary['quantity_mape']), self::pct($summary['total_error']), $summary['cases'],
        ));

        $path = $this->option('report') ?: 'estimate-ai/eval-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode(['vendor_id' => $vendorId, 'ran_at' => now()->toIso8601String(), 'summary' => $summary, 'cases' => $results], JSON_PRETTY_PRINT));
        $this->line('Report: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    /**
     * @return list<array{section_id: int, name: string, inquiry: string, floorplan: ?array, line_count: int}>
     */
    protected function cases(int $vendorId, int $limit): array
    {
        if ($file = $this->option('file')) {
            $raw = json_decode((string) file_get_contents($file), true);
            $cases = [];
            foreach (is_array($raw) ? $raw : [] as $row) {
                $section = EstimateSection::query()->withCount('estimate_line_items')->find($row['section_id'] ?? 0);
                if ($section && filled($row['inquiry'] ?? null)) {
                    $cases[] = ['section_id' => $section->id, 'name' => (string) $section->name, 'inquiry' => (string) $row['inquiry'], 'floorplan' => $row['floorplan'] ?? null, 'line_count' => $section->estimate_line_items_count];
                }
            }

            return array_slice($cases, 0, $limit);
        }

        // Kept drafts, newest first, one per section: their enquiry and the section as the estimator left it.
        return EstimateAiDraft::query()
            ->where('vendor_id', $vendorId)
            ->where('status', '!=', EstimateAiDraft::DISCARDED)
            ->with(['section' => fn ($q) => $q->withCount('estimate_line_items')])
            ->latest('id')
            ->get()
            ->unique('section_id')
            ->filter(fn (EstimateAiDraft $draft) => $draft->section !== null && $draft->section->estimate_line_items_count > 0)
            ->take($limit)
            ->map(fn (EstimateAiDraft $draft) => [
                'section_id' => (int) $draft->section_id,
                'name' => (string) $draft->section->name,
                'inquiry' => (string) $draft->inquiry,
                'floorplan' => $draft->floorplan,
                'line_count' => (int) $draft->section->estimate_line_items_count,
            ])
            ->values()
            ->all();
    }

    /**
     * How close a draft came to the lines that were actually billed.
     *
     * @param  list<array<string, mixed>>  $drafted
     * @param  \Illuminate\Support\Collection<int, EstimateLineItem>  $actual
     * @return array<string, mixed>
     */
    public static function score(array $drafted, $actual): array
    {
        $draftedById = collect($drafted)->filter(fn ($i) => ! empty($i['line_item_id']))->keyBy('line_item_id');
        $actualById = $actual->filter(fn ($l) => ! empty($l->line_item_id))->keyBy('line_item_id');

        $matched = $draftedById->intersectByKeys($actualById);
        $precision = $draftedById->isEmpty() ? 0.0 : $matched->count() / $draftedById->count();
        $recall = $actualById->isEmpty() ? 0.0 : $matched->count() / $actualById->count();
        $f1 = ($precision + $recall) == 0.0 ? 0.0 : 2 * $precision * $recall / ($precision + $recall);

        $quantityErrors = [];
        foreach ($matched as $id => $item) {
            $line = $actualById->get($id);
            if ($line->unit_type === 'no_unit' || (float) $line->quantity <= 0) {
                continue;
            }
            $quantityErrors[] = abs((float) $item['quantity'] - (float) $line->quantity) / (float) $line->quantity;
        }

        $draftedTotal = collect($drafted)->sum(fn ($i) => (float) ($i['quantity'] ?? 1) * (float) ($i['cost'] ?? 0));
        $actualTotal = (float) $actual->sum('total');

        return [
            'actual_count' => $actualById->count(),
            'drafted_count' => $draftedById->count(),
            'matched_count' => $matched->count(),
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1' => round($f1, 4),
            'quantity_mape' => $quantityErrors === [] ? null : round(array_sum($quantityErrors) / count($quantityErrors), 4),
            'total_error' => $actualTotal <= 0 ? 0.0 : round(abs($draftedTotal - $actualTotal) / $actualTotal, 4),
            'missed' => $actualById->diffKeys($draftedById)->pluck('name')->values()->all(),
            'extra' => $draftedById->diffKeys($actualById)->pluck('name')->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public static function summarize(array $results): array
    {
        $mean = fn (string $key) => $results === [] ? 0.0 : round(array_sum(array_column($results, $key)) / count($results), 4);
        $quantity = array_filter(array_column($results, 'quantity_mape'), fn ($v) => $v !== null);

        return [
            'cases' => count($results),
            'precision' => $mean('precision'),
            'recall' => $mean('recall'),
            'f1' => $mean('f1'),
            'quantity_mape' => $quantity === [] ? null : round(array_sum($quantity) / count($quantity), 4),
            'total_error' => $mean('total_error'),
        ];
    }

    private static function pct(float $value): string
    {
        return number_format($value * 100, 0).'%';
    }
}
