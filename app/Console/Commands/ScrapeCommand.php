<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScrapeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scraper start description';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return Command::SUCCESS;
    }
}
