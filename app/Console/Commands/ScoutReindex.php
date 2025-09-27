<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ScoutReindex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:reindex {--models=* : Specific models to reindex (default: all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Complete Scout reindexing: delete indexes, flush models, sync settings, and reimport';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Starting Scout reindexing process...');
        
        // Get models to process - use config if no models specified
        $models = $this->option('models') ?: $this->getSearchableModels();
        
        if (empty($models)) {
            $this->error('❌ No searchable models found in configuration!');
            return Command::FAILURE;
        }
        
        $this->info("📋 Processing models: " . implode(', ', array_map('class_basename', $models)));
        
        // Step 1: Delete all indexes
        $this->info('📥 Step 1: Deleting all indexes...');
        Artisan::call('scout:delete-all-indexes');
        $this->line(Artisan::output());
        
        // Step 2: Flush individual models
        $this->info('🗑️  Step 2: Flushing individual models...');
        foreach ($models as $model) {
            $this->line("   Flushing {$model}...");
            Artisan::call('scout:flush', ['model' => $model]);
        }
        
        // Step 3: Sync index settings
        $this->info('⚙️  Step 3: Syncing index settings...');
        Artisan::call('scout:sync-index-settings');
        $this->line(Artisan::output());
        
        // Step 4: Import models
        $this->info('📤 Step 4: Importing models...');
        
        foreach ($models as $model) {
            $this->line("   Importing {$model}...");
            
            $startTime = microtime(true);
            Artisan::call('scout:import', ['model' => $model]);
            $endTime = microtime(true);
            
            $duration = round($endTime - $startTime, 2);
            $this->info("   ✅ {$model} imported successfully ({$duration}s)");
        }
        
        $this->info('🎉 Scout reindexing completed successfully!');
        
        return Command::SUCCESS;
    }

    /**
     * Get searchable models from Scout configuration
     */
    protected function getSearchableModels(): array
    {
        $indexSettings = config('scout.meilisearch.index-settings', []);
        
        return array_keys($indexSettings);
    }
}
