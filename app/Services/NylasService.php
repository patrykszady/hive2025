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
     *
     * @param string $grantId
     * @param array $subFolders
     * @param string|null $parentId
     * @return array
     */
    public function ensureFoldersExist(string $grantId, ?string $parentId = null): array
    {
        $createdFolders = [];
        $subFolders = ['Saved', 'Duplicate', 'Error', 'Add', 'Retry', 'Test', 'LEADS'];

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
     *
     * @param string $grantId
     * @param string $folderName
     * @param string|null $parentId
     * @return array|null
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
     *
     * @param string $grantId
     * @param string $folderName
     * @param string|null $parentId
     * @return array
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
                    'Authorization' => 'Bearer ' . config('services.nylas.secret'),
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

    public function moveEmailToFolder($emailId, $folderId, $grantId)
    {
        $apiKey = env('NYLAS_API_KEY');
        $url = "https://api.us.nylas.com/v3/grants/{$grantId}/messages/{$emailId}";
        // Build the request body dynamically
        $body = ['folders' => [$folderId]];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->put($url, $body);

        if (!$response->successful()) {
            throw new Exception('Failed to move email: ' . $response->status());
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
