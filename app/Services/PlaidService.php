<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;

class PlaidService
{
    protected $client;
    protected $baseUrl;
    protected $clientId;
    protected $secret;

    public function __construct()
    {
        $this->client = new GuzzleClient();
        $this->baseUrl = 'https://' . env('PLAID_ENV') . '.plaid.com';
        $this->clientId = env('PLAID_CLIENT_ID');
        $this->secret = env('PLAID_SECRET');
    }

    public function getTransactions($accessToken, $startDate, $endDate)
    {
        $url = $this->baseUrl . '/transactions/get';
        $data = [
            'client_id' => $this->clientId,
            'secret' => $this->secret,
            'access_token' => $accessToken,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        return $this->makeRequest($url, $data);
    }

    public function syncTransactions($accessToken, $cursor = null, $count = 200)
    {
        $url = $this->baseUrl . '/transactions/sync';
        $data = [
            'client_id' => $this->clientId,
            'secret' => $this->secret,
            'access_token' => $accessToken,
            'cursor' => $cursor,
            'count' => $count,
        ];

        return $this->makeRequest($url, $data);
    }

    public function getItem($accessToken)
    {
        $url = $this->baseUrl . '/item/get';
        $data = [
            'client_id' => $this->clientId,
            'secret' => $this->secret,
            'access_token' => $accessToken,
        ];

        return $this->makeRequest($url, $data);
    }

    private function makeRequest($url, $data)
    {
        try {
            $response = $this->client->post($url, [
                'json' => $data,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            // Handle error and retries
            return ['error' => $e->getMessage()];
        }
    }


}
