<?php

namespace App\Services;

use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AmazonSpApiApplicationManagementService
{
    /**
     * @return array{status:int,request_id:?string,rate_limit:?string}
     */
    public function rotateApplicationClientSecret(): array
    {
        $accessToken = $this->requestGrantlessLwaAccessToken();

        $endpoint = rtrim((string) config('services.amazon.sp_api_endpoint', 'https://sellingpartnerapi-na.amazon.com'), '/');
        $url = $endpoint . '/applications/2023-11-30/clientSecret';
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        $headers = [
            'accept' => '*/*',
            'host' => $host,
            'user-agent' => 'Hive Amazon Rotator/1.0 (Language=PHP/' . PHP_VERSION . ';Platform=' . PHP_OS_FAMILY . ')',
            'x-amz-access-token' => $accessToken,
            'x-amz-date' => gmdate('Ymd\\THis\\Z'),
        ];

        $request = new Request('POST', $url, $headers);
        $signedRequest = $this->signRequest($request);

        $response = (new Client())->send($signedRequest);

        return [
            'status' => $response->getStatusCode(),
            'request_id' => $response->getHeaderLine('x-amzn-RequestId') ?: null,
            'rate_limit' => $response->getHeaderLine('x-amzn-RateLimit-Limit') ?: null,
        ];
    }

    /**
     * @return array<int, array{message_id:string,receipt_handle:string,body:string,secrets:array<int,string>}>
     */
    public function receiveRotationMessages(int $maxMessages = 5, int $waitSeconds = 10): array
    {
        $queueUrl = (string) config('services.amazon.rotation_queue_url');
        if ($queueUrl === '') {
            throw new RuntimeException('services.amazon.rotation_queue_url is not configured');
        }

        $sqs = new SqsClient([
            'version' => 'latest',
            'region' => (string) config('services.amazon.rotation_queue_region', config('services.amazon.aws_region', 'us-east-1')),
            'credentials' => [
                'key' => (string) config('services.amazon.aws_access_key_id'),
                'secret' => (string) config('services.amazon.aws_secret_access_key'),
            ],
        ]);

        $result = $sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => max(1, min(10, $maxMessages)),
            'WaitTimeSeconds' => max(0, min(20, $waitSeconds)),
            'MessageAttributeNames' => ['All'],
        ]);

        $messages = $result->get('Messages') ?? [];
        $parsed = [];

        foreach ($messages as $message) {
            $body = (string) ($message['Body'] ?? '');
            $parsed[] = [
                'message_id' => (string) ($message['MessageId'] ?? ''),
                'receipt_handle' => (string) ($message['ReceiptHandle'] ?? ''),
                'body' => $body,
                'secrets' => \App\Support\AmazonRotationMessageParser::extractClientSecretsFromSqsBody($body),
            ];
        }

        return $parsed;
    }

    public function deleteMessage(string $receiptHandle): void
    {
        $queueUrl = (string) config('services.amazon.rotation_queue_url');
        if ($queueUrl === '') {
            throw new RuntimeException('services.amazon.rotation_queue_url is not configured');
        }

        $sqs = new SqsClient([
            'version' => 'latest',
            'region' => (string) config('services.amazon.rotation_queue_region', config('services.amazon.aws_region', 'us-east-1')),
            'credentials' => [
                'key' => (string) config('services.amazon.aws_access_key_id'),
                'secret' => (string) config('services.amazon.aws_secret_access_key'),
            ],
        ]);

        $sqs->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    protected function requestGrantlessLwaAccessToken(): string
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post('https://api.amazon.com/auth/O2/token', [
                'grant_type' => 'client_credentials',
                'scope' => (string) config('services.amazon.rotation_scope', 'sellingpartnerapi::client_credential:rotation'),
                'client_id' => (string) config('services.amazon.client_id'),
                'client_secret' => (string) config('services.amazon.client_secret'),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to obtain grantless LWA token: HTTP ' . $response->status() . ' ' . $response->body());
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('Failed to obtain grantless LWA token: missing access_token');
        }

        return $token;
    }

    protected function signRequest(Request $request): Request
    {
        $credentials = new Credentials(
            (string) config('services.amazon.aws_access_key_id'),
            (string) config('services.amazon.aws_secret_access_key')
        );

        $signer = new SignatureV4('execute-api', (string) config('services.amazon.aws_region', 'us-east-1'));

        return $signer->signRequest($request, $credentials);
    }
}
