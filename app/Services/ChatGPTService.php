<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI\Client;
use OpenAI\Transporters\HttpTransporter;
use OpenAI\ValueObjects\ApiKey;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;
use OpenAI\ValueObjects\Transporter\QueryParams;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ChatGPTService
{
    protected $client;

    public function __construct()
    {
        $guzzleClient = new GuzzleClient;
        $baseUri = BaseUri::from('https://api.openai.com/v1');
        $apiKey = ApiKey::from(env('OPENAI_API_KEY'));
        $headers = Headers::withAuthorization($apiKey);
        $queryParams = QueryParams::create();
        $streamHandler = function (RequestInterface $request) use ($guzzleClient): ResponseInterface {
            return $guzzleClient->send($request, ['stream' => true]);
        };

        $transporter = new HttpTransporter($guzzleClient, $baseUri, $headers, $queryParams, $streamHandler);

        $this->client = new Client($transporter);
    }

    public function extractDetails($text)
    {
        $response = $this->client->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                // ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => "Extract the name, date, address, referral, phone number, and email from the following text and format it into JSON with lowecase keys but keep value as entered:\n\n$text"],
            ],
            'max_tokens' => 150,
        ]);

        $json = $response['choices'][0]['message']['content'];
        $data = json_decode($json, true);

        return $data;
        // Debugging: Check if json_decode returned null
        // if (json_last_error() !== JSON_ERROR_NONE) {
        //     echo "\nJSON Decode Error: " . json_last_error_msg();
        // } else {
        //     print_r($data);
        // }
    }
}
