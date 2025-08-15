<?php
// filepath: /home/patryk/web/hive/app/Console/Commands/ScoutIndexInSmallChunks.php

namespace App\Console\Commands;

use App\Models\Expense;
use Illuminate\Console\Command;
use Laravel\Scout\ModelObserver;

class ScoutIndexInSmallChunks extends Command
{
    protected $signature = 'scout:custom-import {--chunk=10}';
    protected $description = 'Import models into the search index in small chunks';

    public function handle()
    {
        $chunkSize = (int) $this->option('chunk');
        $this->info("Indexing expenses in chunks of {$chunkSize}");

        // Get total count for progress bar
        $total = Expense::count();
        $bar = $this->output->createProgressBar($total);

        // Process each chunk
        $lastId = 0;
        
        while (true) {
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
            sleep(1);
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
        // Load necessary relationships first
        $models->load(['splits', 'transactions', 'check', 'project']);
        
        // Use the standard searchable method without queueing
        $models->searchable();
    }
}