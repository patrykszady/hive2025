<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use Exception;

class NylasService
{
    private $httpClient;

    public function __construct(Client $client)
    {
        $this->httpClient = $client;
    }

    /**
     * Get the authentication URL to redirect the user to Nylas' Connect API.
     *
     * @return array
     */
    public function getAuthUrl(): array
    {
        // Manually build the authentication URL
        $url = 'https://api.us.nylas.com/v3/connect/auth';
        $params = [
            'client_id' => env('NYLAS_CLIENT_ID'),
            'redirect_uri' => env('NYLAS_REDIRECT_URI'),
            'response_type' => 'code',
            'access_type' => 'online',
        ];

        // Return the URL as part of the response
        return ['authentication_url' => $url . '?' . http_build_query($params)];
    }

    /**
     * Exchange the authorization code for an access token.
     *
     * @param string $code
     * @return array
     */
    public function exchangeAuthCodeForToken($code): array
    {
        try {
            $url = "https://api.us.nylas.com/v3/connect/token";

            $response = $this->httpClient->post($url, [
                'form_params' => [
                    'client_id' => env('NYLAS_CLIENT_ID'),
                    'client_secret' => env('NYLAS_API_KEY'),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => env('NYLAS_REDIRECT_URI'),
                    'code_verifier' => 'nylas',
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            return $data;
        } catch (\Exception $e) {
            Log::error("Token Exchange Failed: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Ensure all subfolders exist for the given grant ID.
     */
    public function ensureFoldersExist(string $grantId, ?string $parentId = null): array
    {
        $parentFolder = $this->getFolder($grantId, "HIVE_CONTRACTORS_RECEIPTS", $parentId);
        $parentId = $parentFolder['id'];
        $subFolders = ['Saved', 'Duplicate', 'Error', 'Add', 'Retry', 'Test', 'LEADS', 'SCANS'];

        $createdFolders = [];
        foreach ($subFolders as $folderName) {
            // Check if the folder exists
            $existingFolder = $this->getFolder($grantId, $folderName, $parentId);

            if ($existingFolder) {
                // Folder exists, store its ID
                $createdFolders[$folderName] = $existingFolder['id'];
            } else {
                // Folder does not exist, create it
                $newFolder = $this->createFolder($grantId, $folderName, $parentId);
                $createdFolders[$folderName] = $newFolder['id'] ?? null;
            }
        }

        return $createdFolders;
    }

    /**
     * Check if a folder already exists within a mailbox.
     */
    public function getFolder(string $grantId, ?string $folderName = null, ?string $parentId = null): ?array
    {
        try {
            // Endpoint for fetching existing folders
            $url = "https://api.us.nylas.com/v3/grants/{$grantId}/folders";

            // Make the HTTP request
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('NYLAS_API_KEY'),
                ],
            ]);

            // Decode the JSON response
            $folders = json_decode($response->getBody(), true);
            // dd($folders);

            // Filter folders by name and parent_id if specified
            $matchedFolder = collect($folders['data'] ?? [])
                ->first(function ($folder) use ($folderName, $parentId) {
                    return $folder['name'] === $folderName &&
                        ($parentId ? $folder['parent_id'] === $parentId : true);
                });

            return $matchedFolder ?: null;
        } catch (RequestException $e) {
            // Log the error and return null
            Log::error("Nylas Get Folder API Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a folder within a mailbox using Nylas' API.
     */
    private function createFolder(string $grantId, string $folderName, ?string $parentId = null): array
    {
        try {
            // Endpoint for Nylas Folder Creation API
            $url = "https://api.us.nylas.com/v3/grants/{$grantId}/folders";

            // Make the HTTP request to create the folder
            $response = $this->httpClient->post($url, [
                'json' => [
                    'name' => $folderName,
                    'parent_id' => $parentId,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . env('NYLAS_API_KEY'),
                ],
            ]);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);

            // Return the created folder's data
            return $data ?? [];
        } catch (RequestException $e) {
            // Log the error and return an empty array
            Log::error("Nylas Folder API Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function moveEmailToFolder($messageId, $folderId, $grantId)
    {
        $apiKey = env('NYLAS_API_KEY');
        $url = "https://api.us.nylas.com/v3/grants/{$grantId}/messages/{$messageId}";

        // Build the request body dynamically
        $body = ['folders' => [$folderId]];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json'
        ])->patch($url, $body);

        if (!$response->successful()) {
            Log::error("Failed to move email: " . $response->body());
            // throw new Exception('Failed to move email: ' . $response->status());
        }
    }

    /**
     * Fetch consolidated order details for a given grant ID.
     *
     * @param string $grantId
     * @return array|null
     */
    public function getConsolidatedOrder(string $grantId): ?array
    {
        try {
            $url = "https://api.us.nylas.com/v3/grants/{$grantId}/consolidated-order";

            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('NYLAS_API_KEY'),
                    'Accept' => 'application/json, application/gzip',
                    'Content-Type' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody(), true); // Parse and return the response
        } catch (Exception $e) {
            // Log error for debugging
            Log::error("Error fetching consolidated order for Grant ID {$grantId}: " . $e->getMessage());
            return null; // Return null in case of an error
        }
    }

    public function getMessages(array $queryParams = [], string $grantId): ?array
    {
        try {
            // Base URL for Nylas API to fetch messages with included headers
            $url = "https://api.us.nylas.com/v3/grants/{$grantId}/messages";

            // Append query parameters to the URL if provided
            if (!empty($queryParams)) {
                $url .= '?' . http_build_query($queryParams);
            }

            // Add the specific 'fields' parameter to include headers
            $url .= (empty($queryParams) ? '?' : '&') . 'fields=include_headers';

            // Perform the HTTP GET request using the HTTP client
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('NYLAS_API_KEY'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Decode the response body into an associative array
            return json_decode($response->getBody(), true);
        } catch (Exception $e) {
            // Log the error with Grant ID and the exception message
            Log::error("Error fetching messages for Grant ID {$grantId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retry policy per Nylas v3 guidance:
     * - Initial attempt with provided limit (e.g., 45)
     * - First retry with limit = 10
     * - Second retry with limit = 5
     * - No third retry
     * Returns the `data` array or [] when all attempts fail.
     */
    public function getMessagesWithRetry(array $queryParams, string $grantId, int $maxAttempts = 3, ?string $folderName = null): array
    {
        // 0-based attempt index per request: 0,1,2; then stop
        $params = $queryParams;
        $max = min($maxAttempts, 3);

        for ($idx = 0; $idx < $max; $idx++) {
            // Enforce limits: 0->original (or 45 default), 1->10, 2->5
            if ($idx === 0) {
                // Default to 45 if caller didn't specify
                if (!isset($params['limit']) || !is_numeric($params['limit'])) {
                    $params['limit'] = 20;
                }
            } elseif ($idx === 1) {
                $params['limit'] = 10;
            } else /* $idx === 2 */ {
                $params['limit'] = 5;
            }

            try {
                $resp = $this->getMessages($params, $grantId);
                if (is_array($resp) && array_key_exists('data', $resp)) {
                    return $resp['data'] ?? [];
                }

                throw new Exception('Nylas getMessages returned null or invalid payload.');
            } catch (Exception $e) {
                Log::warning('Nylas getMessages attempt failed', [
                    'grant_id' => $grantId,
                    'folder' => $folderName ?? ($params['in'] ?? null),
                    'attempt' => $idx, // 0-based attempt index in logs
                    'limit' => $params['limit'] ?? null,
                    'message' => $e->getMessage(),
                ]);

                // Small backoff with jitter before next retry, except after final attempt
                if ($idx < $max - 1) {
                    $delayMs = $idx === 0 ? 400 : 800; // ~0.4s then ~0.8s
                    $jitterMs = random_int(50, 150);
                    usleep(($delayMs + $jitterMs) * 1000);
                }
            }
        }

        // Final failure: log to dedicated channel and throw
        Log::channel('nylas')->error('Nylas getMessages final failure', [
            'grant_id' => $grantId,
            'folder' => $folderName ?? ($params['in'] ?? null),
            'attempts' => $max,
            'final_limit' => $params['limit'] ?? null,
        ]);
        throw new Exception('Nylas getMessages failed after retries');
    }

    public function downloadAttachment($attachmentId, $grantId, $messageId)
    {
        $apiKey = env('NYLAS_API_KEY');
        $url = "https://api.us.nylas.com/v3/grants/{$grantId}/attachments/{$attachmentId}/download?message_id={$messageId}";

        // Make the GET request
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Accept' => 'application/octet-stream',
        ])->get($url);

        if ($response->successful()) {
            return $response->body(); // Return the downloaded attachment content
        } else {
            throw new Exception('Failed to download attachment: ' . $response->status());
        }
    }
}
