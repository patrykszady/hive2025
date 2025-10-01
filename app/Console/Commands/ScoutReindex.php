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
    protected $signature = 'scout:reindex {--models=* : Specific models to reindex (default: all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindex all Scout models with proper cleanup and settings sync';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Starting Scout reindexing process...');
        
        // Get models from configuration or command option
        $models = $this->getModelsToProcess();
        
        if (empty($models)) {
            $this->error('❌ No searchable models found in Scout configuration.');
            return self::FAILURE;
        }
        
        $this->info('📋 Processing models: ' . implode(', ', array_map(fn($model) => class_basename($model), $models)));
        
        // Step 1: Delete all indexes
        $this->info('📥 Step 1: Deleting all indexes...');
        $this->call('scout:delete-all-indexes');
        
        // Step 2: Flush each model
        $this->info('🧹 Step 2: Flushing models...');
        foreach ($models as $model) {
            $start = microtime(true);
            $this->call('scout:flush', ['model' => $model]);
            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->line("   ✅ Flushed {$model} ({$duration}ms)");
        }
        
        // Step 3: Sync index settings
        $this->info('⚙️  Step 3: Syncing index settings...');
        $this->call('scout:sync-index-settings');
        
        // Step 4: Import each model
        $this->info('📤 Step 4: Importing models...');
        foreach ($models as $model) {
            $start = microtime(true);
            
            $importOptions = ['model' => $model];
            
            // Use smaller chunks for better performance and stability
            if (app()->environment('production')) {
                $importOptions['--chunk'] = 100;
                $this->line("   🔄 Importing {$model} (chunk size: 100)...");
            } else {
                $this->line("   🔄 Importing {$model}...");
            }
            
            $this->call('scout:import', $importOptions);
            
            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->line("   ✅ Imported {$model} ({$duration}ms)");
        }
        
        $this->info('🎉 Scout reindexing completed successfully!');
        
        return self::SUCCESS;
    }
    
    private function getModelsToProcess(): array
    {
        // If specific models provided via command option
        if (!empty($this->option('models'))) {
            return $this->option('models');
        }
        
        // Get models from Scout configuration
        $scoutConfig = config('scout.meilisearch.index-settings', []);
        
        return array_keys($scoutConfig);
    }
}
