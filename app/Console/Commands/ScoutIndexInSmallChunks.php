<?php

namespace App\Console\Commands;

use App\Models\Expense;
use Illuminate\Console\Command;

class ScoutIndexInSmallChunks extends Command
{
    protected $signature = 'scout:custom-import {--chunk=10} {--sleep=1} {--memory-limit=2G}';
    protected $description = 'Import models into the search index in small chunks';

    public function handle()
    {
        // Set memory limit
        ini_set('memory_limit', $this->option('memory-limit'));
        
        $chunkSize = (int) $this->option('chunk');
        $sleepTime = (int) $this->option('sleep');
        
        $this->info("Indexing expenses in chunks of {$chunkSize}");
        $this->info("Memory limit: " . ini_get('memory_limit'));

        // Get total count for progress bar
        $total = Expense::count();
        $bar = $this->output->createProgressBar($total);
        
        // Process each chunk
        $lastId = 0;
        $errorCount = 0;
        
        while (true) {
            try {
                // Get the next batch by ID for consistent chunking
                $expenses = Expense::where('id', '>', $lastId)
                    ->orderBy('id')
                    ->take($chunkSize)
                    ->get();
                    
                if ($expenses->isEmpty()) {
                    break;
                }
                
                // Track the last ID processed
                $lastId = $expenses->last()->id;

                // Index this chunk directly without using queue
                $this->indexChunk($expenses);
                
                // Update progress and pause
                $bar->advance($expenses->count());
                $this->output->write(" <info>Processed up to ID {$lastId}</info>");
                
                // Reset error count on success
                $errorCount = 0;
                
                // Free memory
                unset($expenses);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                
                sleep($sleepTime);
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("\nError processing batch ending with ID {$lastId}: " . $e->getMessage());
                
                // Log detailed exception for debugging
                \Log::error("Scout indexing error at ID {$lastId}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Break if we've had too many consecutive errors
                if ($errorCount > 3) {
                    $this->error("Too many consecutive errors, stopping import");
                    return Command::FAILURE;
                }
                
                // Reduce chunk size and try again
                $chunkSize = max(1, floor($chunkSize / 2));
                $this->warn("Reducing chunk size to {$chunkSize} and continuing...");
                
                sleep($sleepTime * 2);
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info('All expenses have been imported into the search index!');

        return Command::SUCCESS;
    }

    /**
     * Index a chunk of models directly
     */
    protected function indexChunk($models)
    {
        try {
            // Load necessary relationships first
            $models->load(['splits', 'transactions', 'check', 'project']);
            
            // Use the standard searchable method without queueing
            $models->searchable();
        } catch (\Exception $e) {
            // Get IDs of models that failed
            $ids = $models->pluck('id')->toArray();
            throw new \Exception("Failed to index expenses " . implode(',', $ids) . ": " . $e->getMessage(), 0, $e);
        }
    }
}