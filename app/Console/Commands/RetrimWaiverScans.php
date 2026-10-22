<?php

namespace App\Console\Commands;

use App\Models\LienWaiver;
use App\Models\SwornStatement;
use App\Services\WaiverScanIngest;
use App\Support\PdfPageExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off clean-up for signed scans stored before the ingest learned to drop
 * foreign pages (2026-08-19): a vendor's invoice scanned in behind their
 * waiver, or a GC waiver and GCSS returned as one PDF and stored whole for
 * both. Every multi-page scan is analyzed again and cut down to the pages
 * that carry its own barcode; draw packages pick the trimmed file up as is.
 */
class RetrimWaiverScans extends Command
{
    protected $signature = 'waivers:retrim-scans {--dry-run : Report which pages would be dropped without changing any file}';

    protected $description = 'Drop pages that belong to another document (vendor invoices, a bundled GCSS) from signed waiver and sworn statement scans stored before page trimming existed.';

    public function handle(WaiverScanIngest $ingest): int
    {
        $disk = Storage::disk('files');
        $dryRun = (bool) $this->option('dry-run');
        $trimmed = 0;

        foreach ($this->storedScans() as $path => $code) {
            if (! $disk->exists($path) || (PdfPageExtractor::pageCount((string) $disk->get($path)) ?? 1) <= 1) {
                continue;
            }

            try {
                $result = $ingest->retrimStoredScan($code, $path, $dryRun);
            } catch (\Throwable $e) {
                $this->error("{$code}: analysis failed — {$e->getMessage()}");

                continue;
            }

            if ($result === null) {
                $this->line("{$code}: every page is its own, left as is.");

                continue;
            }

            $trimmed++;
            $this->info(sprintf(
                '%s: %s %d → %d page(s), keeping page(s) %s.',
                $code,
                $dryRun ? 'would trim' : 'trimmed',
                $result['before'],
                $result['after'],
                implode(', ', $result['kept']),
            ));
        }

        $this->info(($dryRun ? 'Would trim ' : 'Trimmed ') . $trimmed . ' scan(s).');

        return self::SUCCESS;
    }

    /**
     * Every stored signed scan, once per file, with the code of the document
     * the file belongs to. A reused waiver points at the original waiver's
     * scan, whose folder carries the original's id.
     *
     * @return array<string, string> path => HLW-/HSS- code
     */
    protected function storedScans(): array
    {
        $scans = [];

        LienWaiver::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('signed_path', 'like', '%/scan-%')
            ->orderBy('id')
            ->get(['id', 'signed_path'])
            ->each(function (LienWaiver $waiver) use (&$scans) {
                $ownerId = preg_match('#^lien-waivers/\d+/(\d+)/#', $waiver->signed_path, $m) ? (int) $m[1] : $waiver->id;
                $scans[$waiver->signed_path] ??= 'HLW-' . $ownerId;
            });

        SwornStatement::withoutGlobalScopes()
            ->whereNotNull('signed_path')
            ->orderBy('id')
            ->get(['id', 'signed_path'])
            ->each(function (SwornStatement $statement) use (&$scans) {
                $scans[$statement->signed_path] ??= 'HSS-' . $statement->id;
            });

        return $scans;
    }
}
