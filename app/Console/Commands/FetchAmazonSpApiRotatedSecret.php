<?php

namespace App\Console\Commands;

use App\Services\AmazonSpApiApplicationManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FetchAmazonSpApiRotatedSecret extends Command
{
    protected $signature = 'amazon:spapi-fetch-client-secret
        {--max=5 : Maximum number of SQS messages to read (1-10)}
        {--wait=10 : Long poll wait time in seconds (0-20)}
        {--delete : Delete each processed message from SQS}
        {--apply-env : Update AMAZON_CLIENT_SECRET in .env when exactly one secret is found}';

    protected $description = 'Fetch rotated Amazon SP-API client secret notifications from SQS';

    public function handle(AmazonSpApiApplicationManagementService $service): int
    {
        try {
            $messages = $service->receiveRotationMessages(
                (int) $this->option('max'),
                (int) $this->option('wait')
            );

            if ($messages === []) {
                $this->info('No SQS messages found.');

                return self::SUCCESS;
            }

            $allSecrets = [];

            foreach ($messages as $message) {
                $this->line('Message: ' . $message['message_id']);

                if ($message['secrets'] === []) {
                    $this->warn('  No client secret found in message payload.');
                } else {
                    foreach ($message['secrets'] as $secret) {
                        $allSecrets[] = $secret;
                        $this->info('  Found secret: ' . $this->maskSecret($secret));
                    }
                }

                if ($this->option('delete') && $message['receipt_handle'] !== '') {
                    $service->deleteMessage($message['receipt_handle']);
                    $this->line('  Deleted from queue.');
                }
            }

            $uniqueSecrets = array_values(array_unique($allSecrets));
            if ($this->option('apply-env')) {
                if (count($uniqueSecrets) !== 1) {
                    throw new RuntimeException('Refusing to update .env because exactly one unique secret was not found.');
                }

                $this->updateEnvValue(base_path('.env'), 'AMAZON_CLIENT_SECRET', $uniqueSecrets[0]);
                $this->warn('Updated AMAZON_CLIENT_SECRET in .env. Run "php artisan config:clear" if needed.');
            }

            Log::channel('receipt')->info('Processed Amazon SP-API rotation messages', [
                'messages_count' => count($messages),
                'unique_secrets_count' => count($uniqueSecrets),
                'deleted' => (bool) $this->option('delete'),
                'applied_env' => (bool) $this->option('apply-env'),
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::channel('receipt')->error('Failed to fetch Amazon SP-API rotation messages', [
                'message' => $exception->getMessage(),
            ]);

            $this->error('Failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    protected function maskSecret(string $secret): string
    {
        if (strlen($secret) <= 10) {
            return '********';
        }

        return substr($secret, 0, 6) . str_repeat('*', max(0, strlen($secret) - 10)) . substr($secret, -4);
    }

    protected function updateEnvValue(string $envPath, string $key, string $value): void
    {
        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read .env file');
        }

        $escapedValue = preg_replace('/(["\\\\])/', '\\\\$1', $value);
        $newLine = $key . '="' . $escapedValue . '"';

        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            $updated = preg_replace($pattern, $newLine, $contents, 1);
        } else {
            $updated = rtrim($contents) . PHP_EOL . $newLine . PHP_EOL;
        }

        if (! is_string($updated) || file_put_contents($envPath, $updated) === false) {
            throw new RuntimeException('Unable to write .env file');
        }
    }
}
