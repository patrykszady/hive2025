<?php

namespace App\Console\Commands;

use App\Models\LineItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * An estimate drafted by the model before 2026-09-16 carried the model's
 * rewordings as each line's description and notes. The text on file — the
 * catalog's — is what the estimate should say. Restores it on every line
 * of the estimate that nobody has edited since it was created; an edited
 * line is someone's work and is left alone.
 */
class RestoreEstimateCatalogText extends Command
{
    protected $signature = 'estimates:restore-catalog-text {estimate : The estimate id} {--dry-run : List the lines that would change}';

    protected $description = 'Put the catalog description and notes back on an estimate\'s untouched lines';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $estimateId = (int) $this->argument('estimate');

        $lines = DB::table('estimate_line_item')
            ->where('estimate_id', $estimateId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            $this->warn("Estimate {$estimateId} has no lines.");

            return self::SUCCESS;
        }

        $catalog = LineItem::withoutGlobalScopes()->whereIn('id', $lines->pluck('line_item_id'))->get()->keyBy('id');
        $changed = 0;

        foreach ($lines as $line) {
            $item = $catalog->get($line->line_item_id);
            if (! $item) {
                continue;
            }

            if ($line->updated_at !== $line->created_at) {
                $this->line("  #{$line->id} {$line->name}: edited since drafting — kept");

                continue;
            }

            if ((string) $line->desc === (string) $item->desc && (string) $line->notes === (string) $item->notes) {
                continue;
            }

            $changed++;
            $this->line(sprintf('  #%d %s: desc %s -> %s | notes %s -> %s', $line->id, $line->name,
                json_encode(mb_substr((string) $line->desc, 0, 40)), json_encode(mb_substr((string) $item->desc, 0, 40)),
                json_encode(mb_substr((string) $line->notes, 0, 40)), json_encode(mb_substr((string) $item->notes, 0, 40))));

            if (! $dryRun) {
                DB::table('estimate_line_item')->where('id', $line->id)->update([
                    'desc' => $item->desc,
                    'notes' => $item->notes,
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info(($dryRun ? '[dry run] ' : '')."{$changed} line(s) ".($dryRun ? 'would be' : 'were').' restored to the catalog text.');

        return self::SUCCESS;
    }
}
