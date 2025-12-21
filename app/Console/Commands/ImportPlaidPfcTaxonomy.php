<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class ImportPlaidPfcTaxonomy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:import-pfc-taxonomy
                            {path : Path to pfc-taxonomy-all.csv}
                            {--only=both : Import only v1, v2, or both}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Plaid Personal Finance Category taxonomy (PFC v1/v2) into categories table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $only = strtolower((string) $this->option('only'));

        if (!in_array($only, ['v1', 'v2', 'both'], true)) {
            $this->error('Invalid --only value. Use v1, v2, or both.');
            return self::FAILURE;
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->error('CSV file not found or not readable: '.$path);
            return self::FAILURE;
        }

        $file = new \SplFileObject($path);
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);

        $rows = 0;
        $upserted = 0;
        $skipped = 0;

        foreach ($file as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }

            $rows++;

            $pfcV2Primary = trim((string) ($row[0] ?? ''));
            $pfcV2Detailed = trim((string) ($row[1] ?? ''));
            $pfcV1Primary = trim((string) ($row[3] ?? ''));
            $pfcV1Detailed = trim((string) ($row[4] ?? ''));

            if ($pfcV2Primary === 'PFCv2 Primary' || str_starts_with($pfcV2Primary, 'Note:')) {
                $skipped++;
                continue;
            }

            $candidates = [];

            if (($only === 'v2' || $only === 'both') && $pfcV2Primary !== '' && $pfcV2Detailed !== '') {
                $candidates[] = [$pfcV2Primary, $pfcV2Detailed];
            }

            if (($only === 'v1' || $only === 'both') && $pfcV1Primary !== '' && $pfcV1Detailed !== '') {
                $candidates[] = [$pfcV1Primary, $pfcV1Detailed];
            }

            foreach ($candidates as [$primary, $detailed]) {
                // Skip the special combined row like "TRANSFER_IN / TRANSFER_OUT"
                if (str_contains($primary, '/')) {
                    $skipped++;
                    continue;
                }

                $friendlyPrimary = $this->toFriendlyLabel($primary);
                $friendlyDetailed = $this->toFriendlyDetailedLabel($primary, $detailed);
                $iconUrl = 'https://plaid-category-icons.plaid.com/PFC_'.$primary.'.png';

                Category::query()->updateOrCreate(
                    ['detailed' => $detailed],
                    [
                        'primary' => $primary,
                        'friendly_primary' => $friendlyPrimary,
                        'friendly_detailed' => $friendlyDetailed,
                        'icon_url' => $iconUrl,
                    ]
                );

                $upserted++;
            }
        }

        $this->info('Processed CSV rows: '.$rows);
        $this->info('Upserted categories: '.$upserted);
        $this->info('Skipped rows: '.$skipped);

        return self::SUCCESS;
    }

    private function toFriendlyLabel(string $value): string
    {
        return (string) Str::of($value)
            ->replace('_', ' ')
            ->lower()
            ->title();
    }

    private function toFriendlyDetailedLabel(string $primary, string $detailed): string
    {
        $suffix = Str::startsWith($detailed, $primary.'_')
            ? Str::after($detailed, $primary.'_')
            : $detailed;

        return $this->toFriendlyLabel((string) $suffix);
    }
}
