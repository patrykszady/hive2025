<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MigrateSmsMediaToFilesDisk extends Command
{
    protected $signature = 'sms:migrate-media-disk
        {--copy : Copy files instead of moving them}
        {--dry-run : Show what would happen without changing anything}';

    protected $description = 'Migrate SMS media from storage/app/public to storage/files (sms-media + sms-attachments).';

    public function handle(): int
    {
        $dirs = ['sms-media', 'sms-attachments'];
        $sourceBase = storage_path('app/public');
        $targetBase = storage_path('files');
        $dryRun = (bool) $this->option('dry-run');
        $copy = (bool) $this->option('copy');

        if (! is_dir($targetBase) && ! $dryRun) {
            mkdir($targetBase, 0775, true);
        }

        $totals = ['moved' => 0, 'skipped' => 0, 'missing_src' => 0, 'errors' => 0];

        foreach ($dirs as $dir) {
            $src = $sourceBase.DIRECTORY_SEPARATOR.$dir;
            $dst = $targetBase.DIRECTORY_SEPARATOR.$dir;

            if (! is_dir($src)) {
                $this->warn("Source missing: {$src}");
                $totals['missing_src']++;
                continue;
            }

            $this->info("Scanning {$src} -> {$dst}");

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                $relative = substr($item->getPathname(), strlen($src) + 1);
                $target = $dst.DIRECTORY_SEPARATOR.$relative;

                if ($item->isDir()) {
                    if (! is_dir($target) && ! $dryRun) {
                        mkdir($target, 0775, true);
                    }
                    continue;
                }

                if (file_exists($target)) {
                    $totals['skipped']++;
                    continue;
                }

                $parent = dirname($target);
                if (! is_dir($parent) && ! $dryRun) {
                    mkdir($parent, 0775, true);
                }

                if ($dryRun) {
                    $this->line(($copy ? 'COPY ' : 'MOVE ').$item->getPathname().' -> '.$target);
                    $totals['moved']++;
                    continue;
                }

                $ok = $copy
                    ? @copy($item->getPathname(), $target)
                    : @rename($item->getPathname(), $target);

                if (! $ok) {
                    $this->error('Failed: '.$item->getPathname());
                    $totals['errors']++;
                    continue;
                }

                $totals['moved']++;
            }
        }

        $this->newLine();
        $this->table(['metric', 'count'], collect($totals)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }
}
