<?php

namespace App\Services;

use App\Models\Bank;

use Illuminate\Support\Facades\Log;

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

    private function makeRequest($url, $data)
    {
        try {
            $response = $this->client->post($url, [
                'json' => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            // Return the error details
            if ($e->hasResponse()) {
                return [
                    'error' => true,
                    'error_code' => $e->getResponse()->getStatusCode(),
                    'error_message' => $e->getResponse()->getReasonPhrase(),
                    'error_body' => json_decode($e->getResponse()->getBody()->getContents(), true),
                ];
            }

            return [
                'error' => true,
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
            ];
        }
    }

    public function createLinkToken(array $data)
    {
        $url = $this->baseUrl . '/link/token/create';

        try {
            $response = $this->client->post($url, [
                'json' => $data,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (!isset($result['link_token'])) {
                Log::error('Plaid link token generation failed.', ['response' => $result]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Plaid API error during link token creation.', ['error' => $e->getMessage()]);
            return [
                'error' => true,
                'error_message' => $e->getMessage(),
            ];
        }
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

    public function processPlaidItem($itemData)
    {
        $data = [
            'client_id' => $this->clientId,
            'secret' => $this->secret,
            'public_token' => $itemData['public_token'],
        ];

        $url = $this->baseUrl . '/item/public_token/exchange';
        $result = $this->makeRequest($url, $data);

        if (isset($result['error']) && $result['error'] === true) {
            Log::error('PlaidService@processPlaidItem', $result);
            return $result; // Return the error details
        }

        $bank = Bank::where('plaid_access_token', $result['access_token'])->first();

        if (! $bank) {
            $bank = new Bank;
            $bank->name = $itemData['institution']['name'];
            $bank->plaid_access_token = $result['access_token'];
            $bank->plaid_item_id = $result['item_id'];
            $bank->vendor_id = auth()->user()->vendor->id;
            $bank->plaid_ins_id = $itemData['institution']['institution_id'];
            $bank->plaid_options = '{"error": false, "balances": false}';
            $bank->save();
        }

        foreach ($itemData['accounts'] as $account) {
            $bankAccount = BankAccount::where('plaid_account_id', $account['id'])->first();

            if (! $bankAccount) {
                $bankAccount = new BankAccount;
                $bankAccount->bank_id = $bank->id;
                $bankAccount->account_number = $account['mask'];
                $bankAccount->vendor_id = $bank->vendor_id;
                $bankAccount->type = ucwords($account['subtype']);
                $bankAccount->plaid_account_id = $account['id'];
                $bankAccount->save();
            }
        }

        return $bank;
    }
}
