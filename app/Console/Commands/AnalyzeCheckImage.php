<?php

namespace App\Console\Commands;

use App\Services\ContentUnderstandingService;
use Illuminate\Console\Command;

class AnalyzeCheckImage extends Command
{
    protected $signature = 'cu:analyze-check
                            {file        : Path to a single check image (png/jpg) or PDF}
                            {--analyzer= : Analyzer ID override (defaults to services.azure_cu.analyzer_id_check)}
                            {--json      : Output the extracted fields as JSON}';

    protected $description = 'Run the individual-check Content Understanding analyzer on one check image and print the extracted fields.';

    public function handle(ContentUnderstandingService $cu): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $analyzerId = $this->option('analyzer') ?: config('services.azure_cu.analyzer_id_check');

        $raw    = $cu->analyzeBinary(file_get_contents($file), $analyzerId);
        $fields = $raw['result']['contents'][0]['fields'] ?? [];

        $values = ContentUnderstandingService::normalizeFieldValues($fields);

        if ($this->option('json')) {
            $this->line(json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($values as $name => $value) {
            if (is_array($value) && array_is_list($value)) {
                $rows[] = [$name, implode(', ', $value)];
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $subName => $subValue) {
                    $rows[] = ["{$name}.{$subName}", $this->displayValue($subValue)];
                }
                continue;
            }

            $rows[] = [$name, $this->displayValue($value)];
        }

        $this->table(['Field', 'Value'], $rows);

        return self::SUCCESS;
    }

    private function displayValue(mixed $v): string
    {
        return match (true) {
            is_bool($v)  => $v ? 'yes' : 'no',
            is_float($v) => number_format($v, 2),
            default      => (string) $v,
        };
    }
}
