<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScoutReindex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:reindex
        {--models=* : Specific models to reindex (default: all)}
        {--full : Force full reindex (delete + flush + reimport all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindex Scout models. By default only syncs settings. Use --models to reimport specific models, or --full for a complete rebuild.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $allModels = $this->getAllModels();

        if (empty($allModels)) {
            $this->error('No searchable models found in Scout configuration.');
            return self::FAILURE;
        }

        // Full nuclear reindex: delete all indexes, flush, reimport everything
        if ($this->option('full')) {
            return $this->fullReindex($allModels);
        }

        // Selective reindex: only reimport specific models
        $specificModels = $this->option('models');
        if (! empty($specificModels)) {
            return $this->selectiveReindex($specificModels);
        }

        // Default: just sync index settings (fast, no data reimport)
        return $this->syncSettingsOnly();
    }

    /**
     * Sync index settings only — no data reimport.
     * This is safe and fast for every deploy.
     */
    private function syncSettingsOnly(): int
    {
        $this->info('Syncing index settings...');
        $this->call('scout:sync-index-settings');
        $this->info('Index settings synced. No data was reimported.');
        $this->line('Tip: Use --models="App\Models\Vendor" to reimport a specific model, or --full to rebuild everything.');

        return self::SUCCESS;
    }

    /**
     * Reimport only the specified models without deleting other indexes.
     */
    private function selectiveReindex(array $models): int
    {
        $this->info('Selective reindex for: ' . implode(', ', array_map(fn ($m) => class_basename($m), $models)));

        // Sync settings first
        $this->call('scout:sync-index-settings');

        foreach ($models as $model) {
            $start = microtime(true);
            $this->line("  Flushing {$model}...");
            $this->call('scout:flush', ['model' => $model]);

            $importOptions = ['model' => $model];
            if (app()->environment('production')) {
                $importOptions['--chunk'] = 100;
            }

            $this->line("  Importing {$model}...");
            $this->call('scout:import', $importOptions);

            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->line("  Done {$model} ({$duration}ms)");
        }

        $this->info('Selective reindex completed.');

        return self::SUCCESS;
    }

    /**
     * Full reindex: delete all indexes, flush, sync settings, reimport everything.
     */
    private function fullReindex(array $models): int
    {
        $this->info('Starting full reindex...');
        $this->info('Models: ' . implode(', ', array_map(fn ($m) => class_basename($m), $models)));

        // Step 1: Delete all indexes
        $this->info('Step 1: Deleting all indexes...');
        $this->call('scout:delete-all-indexes');

        // Step 2: Flush each model
        $this->info('Step 2: Flushing models...');
        foreach ($models as $model) {
            $start = microtime(true);
            $this->call('scout:flush', ['model' => $model]);
            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->line("  Flushed {$model} ({$duration}ms)");
        }

        // Step 3: Sync index settings
        $this->info('Step 3: Syncing index settings...');
        $this->call('scout:sync-index-settings');

        // Step 4: Import each model
        $this->info('Step 4: Importing models...');
        foreach ($models as $model) {
            $start = microtime(true);

            $importOptions = ['model' => $model];

            if (app()->environment('production')) {
                $importOptions['--chunk'] = 100;
                $this->line("  Importing {$model} (chunk size: 100)...");
            } else {
                $this->line("  Importing {$model}...");
            }

            $this->call('scout:import', $importOptions);

            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->line("  Imported {$model} ({$duration}ms)");
        }

        $this->info('Full reindex completed.');

        return self::SUCCESS;
    }

    /** @return string[] */
    private function getAllModels(): array
    {
        return array_keys(config('scout.meilisearch.index-settings', []));
    }
}
