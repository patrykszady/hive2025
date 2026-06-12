<?php

namespace App\Console\Commands;

use App\Services\AmazonSpApiApplicationManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RotateAmazonSpApiClientSecret extends Command
{
    protected $signature = 'amazon:spapi-rotate-client-secret {--dry-run : Validate configuration without calling the rotate endpoint}';

    protected $description = 'Rotate Amazon SP-API application client secret via Application Management API';

    public function handle(AmazonSpApiApplicationManagementService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->info('Dry run enabled. Skipping SP-API rotation call.');
            $this->line('Configured endpoint: ' . (string) config('services.amazon.sp_api_endpoint'));
            $this->line('Configured scope: ' . (string) config('services.amazon.rotation_scope'));

            return self::SUCCESS;
        }

        try {
            $result = $service->rotateApplicationClientSecret();

            $this->info('SP-API client secret rotation request accepted.');
            $this->line('HTTP status: ' . $result['status']);
            $this->line('x-amzn-RequestId: ' . ($result['request_id'] ?? 'n/a'));
            $this->line('x-amzn-RateLimit-Limit: ' . ($result['rate_limit'] ?? 'n/a'));
            $this->warn('New secret is delivered asynchronously via your configured SQS queue.');

            Log::channel('receipt')->info('Amazon SP-API client secret rotated', $result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::channel('receipt')->error('Amazon SP-API client secret rotation failed', [
                'message' => $exception->getMessage(),
            ]);

            $this->error('Rotation failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }
}
