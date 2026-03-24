<?php

namespace App\Console\Commands;

use App\Services\ContentUnderstandingService;
use Illuminate\Console\Command;

class TestCuAnalyzer extends Command
{
    protected $signature = 'cu:test-analyzer {analyzer? : Analyzer ID to test}';

    protected $description = 'Test if a Content Understanding analyzer is reachable.';

    public function handle(ContentUnderstandingService $cu): int
    {
        $analyzerId = $this->argument('analyzer') ?? config('services.azure_cu.analyzer_id_coi');

        $this->info("Testing analyzer: {$analyzerId}");
        $this->info("Endpoint: " . config('services.azure_cu.endpoint'));
        $this->info("API Version: " . config('services.azure_cu.api_version'));

        try {
            $definition = $cu->getAnalyzerDefinition($analyzerId);
            $this->info("Status: " . ($definition['status'] ?? 'unknown'));
            $this->info("Description: " . ($definition['description'] ?? 'none'));
            $this->line(json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed: " . $e->getMessage());

            return self::FAILURE;
        }
    }
}
