<?php

namespace App\Services;

use App\Models\CompanyEmail;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use Exception;
use App\Support\ApiJsonProxy;
use App\Support\ApiErrorFormatter;

class NylasService
{
    private $httpClient;
    private $baseUrl;
    private $apiKey;


    public function __construct(Client $client)
    {
        $this->httpClient = $client;
        $this->baseUrl = 'https://api.us.nylas.com/v3';
        $this->apiKey = config('nylas.api_key');
    }

    /**
     * Get the authentication URL to redirect the user to Nylas' Connect API.
     *
     * @return array
     */
    public function getAuthUrl(): array
    {
        // Manually build the authentication URL
        $url = $this->baseUrl . '/connect/auth';
        $params = [
            'client_id' => config('nylas.client_id'),
            'redirect_uri' => config('nylas.redirect_uri'),
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
            $url = $this->baseUrl . '/connect/token';

            $response = $this->httpClient->post($url, [
                'form_params' => [
                    'client_id' => config('nylas.client_id'),
                    'client_secret' => $this->apiKey,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => config('nylas.redirect_uri'),
                    'code_verifier' => config('nylas.pkce_code_verifier', 'nylas'),
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            return $data;
        } catch (\Exception $e) {
            Log::channel('nylas')->error('Token Exchange Failed', ApiErrorFormatter::format($e, [
                'code' => $code,
                'data' => $data ?? null,
            ]));
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
            $url = $this->baseUrl . "/grants/{$grantId}/folders";

            // Make the HTTP request
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
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
            Log::channel('nylas')->error('Get Folder API Error', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
                'folder_name' => $folderName,
                'parent_id' => $parentId,
            ]));
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
            $url = $this->baseUrl . "/grants/{$grantId}/folders";

            // Make the HTTP request to create the folder
            $response = $this->httpClient->post($url, [
                'json' => [
                    'name' => $folderName,
                    'parent_id' => $parentId,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
            ]);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);

            // Return the created folder's data
            return $data ?? [];
        } catch (RequestException $e) {
            Log::channel('nylas')->error('Folder API Error', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
                'folder_name' => $folderName,
                'parent_id' => $parentId,
            ]));
            return ['error' => $e->getMessage()];
        }
    }

    public function moveEmailToFolder($messageId, $folderId, $grantId, int $companyEmailId): bool
    {
        $url = $this->baseUrl . "/grants/{$grantId}/messages/{$messageId}";

        // Build the request body dynamically
        $body = ['folders' => [$folderId]];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json'
        ])->patch($url, $body);

        if (!$response->successful()) {
            Log::channel('nylas')->error($companyEmailId . " Email move failed", [
                'message_id' => $messageId,
                'folder_id' => $folderId,
                'grant_id' => $grantId,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);
            return false;
        }
        
        return true;
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
            $url = $this->baseUrl . "/grants/{$grantId}/consolidated-order";

            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json, application/gzip',
                    'Content-Type' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody(), true); // Parse and return the response
        } catch (Exception $e) {
            Log::channel('nylas')->error('Error fetching consolidated order', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
            ]));
            return null;
        }
    }

    public function getMessages(array $queryParams = [], string $grantId, bool $withHeaders = false): array
    {
        // Base URL for Nylas API to fetch messages
        $url = $this->baseUrl . "/grants/{$grantId}/messages";

        // Append query parameters to the URL if provided
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        // Add the specific 'fields' parameter to include headers (using URL approach)
        if ($withHeaders) {
            $url .= (empty($queryParams) ? '?' : '&') . 'fields=include_headers';
        }

        // Perform the HTTP GET request using the HTTP client
        $response = $this->httpClient->get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        // Decode the response body into an associative array
        return json_decode($response->getBody(), true) ?? [];
    }

    /**
     * Fetch messages for a specific folder with date filtering.
     * Uses 'received_after' timestamp parameter directly.
     */
    public function getMessagesForFolder(array $queryParams, string $grantId, string $folderName, CompanyEmail $companyEmail): array
    {
        $params = $queryParams;
        $params['limit'] = config('nylas.message_limit');

        try {
            // Pass false for $withHeaders while listing; include_headers seems rejected (400) on list queries.
            $resp = $this->getMessages($params, $grantId, true);
            
            if (is_array($resp) && array_key_exists('data', $resp)) {
                $messages = $resp['data'];
                Log::channel('nylas')->info($companyEmail->id . ' messages fetched', [
                    'grant_id' => $grantId,
                    'folder' => $folderName,
                    'count' => count($messages),
                    'received_after_timestamp' => $params['received_after'] ?? null,
                ]);
                return $messages;
            }
            
            Log::channel('nylas')->warning($companyEmail->id . ' Invalid response format', [
                'grant_id' => $grantId
            ]);
            return [];
            
        } catch (RequestException $e) {            
            Log::channel('nylas')->error($companyEmail->id . ' API request failed', 
                ApiErrorFormatter::format($e, [
                    'grant_id' => $grantId,
                    'folder' => $folderName,
                ]));
            return [];
            
        } catch (Exception $e) {
            Log::channel('nylas')->error($companyEmail->id . ' request error', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
                'folder' => $folderName,
            ]));
            return [];
        }
    }

    /**
     * Multi-folder sync with automatic cursor management.
     * Handles cursor persistence and incremental fetching internally.
     */
    public function syncMessages(array $folders, CompanyEmail $companyEmail): array
    {
        $grantId = $companyEmail->grant_id;
        $allMessages = [];
        $existingCursors = $companyEmail->api_json['cursors'] ?? [];
        $newCursors = $existingCursors;
        
        // Create a mapping from folder IDs to friendly names
        $folderIdToName = array_flip($companyEmail->api_json['folders'] ?? []);
        
        foreach ($folders as $folder) {
            $folderKey = (string) $folder;
            $friendlyName = $folderIdToName[$folderKey] ?? $folderKey;
            
            $params = [
                'limit' => config('nylas.message_limit'),
                'in' => $folderKey,
            ];
            
            // Determine the earliest date to fetch from
            $isFirstSync = !isset($existingCursors[$friendlyName]);
            
            if ($isFirstSync) {
                // First sync - always fetch last 10 days
                $params['received_after'] = Carbon::now()->subDays(10)->timestamp;
                
                Log::channel('nylas')->info($companyEmail->id . " First sync for folder {$friendlyName}", [
                    'grant_id' => $grantId,
                    'folder' => $friendlyName,
                    'folder_id' => $folderKey,
                    'received_after_timestamp' => $params['received_after'],
                    'received_after_date' => Carbon::createFromTimestamp($params['received_after'])->toISOString(),
                ]);
            } else {
                // Subsequent sync - always fetch last 3 days
                $params['received_after'] = Carbon::now()->subDays(3)->timestamp;
                
                Log::channel('nylas')->info($companyEmail->id . " Subsequent sync for folder {$friendlyName}", [
                    'grant_id' => $grantId,
                    'folder' => $friendlyName,
                    'folder_id' => $folderKey,
                    'received_after_timestamp' => $params['received_after'],
                    'received_after_date' => Carbon::createFromTimestamp($params['received_after'])->toISOString(),
                ]);
            }
            
            $messages = $this->getMessagesForFolder($params, $grantId, $friendlyName, $companyEmail);
            
            // If folder has less than 25 items, fetch all messages regardless of received_after
            if (count($messages) < 25) {
                $paramsAll = $params;
                unset($paramsAll['received_after']); // Remove date filter to get all messages
                
                Log::channel('nylas')->info($companyEmail->id . " Fetching all messages for folder {$friendlyName}", [
                    'grant_id' => $grantId,
                    'folder' => $friendlyName,
                    'initial_count' => count($messages),
                ]);
                
                $messages = $this->getMessagesForFolder($paramsAll, $grantId, $friendlyName, $companyEmail);
            }
            
            if (!empty($messages)) {
                // Update cursor to latest message date to prevent re-fetching
                $latestDate = collect($messages)
                    ->pluck('date')
                    ->filter()
                    ->map(fn($d) => is_numeric($d) ? (int) $d : Carbon::parse($d)->timestamp)
                    ->filter()
                    ->max();
                    
                if ($latestDate) {
                    $newCursors[$friendlyName] = $latestDate;
                }
                
                $allMessages = array_merge($allMessages, $messages);
            } else {
                // No new messages, but keep the existing cursor
                if (isset($existingCursors[$friendlyName])) {
                    $newCursors[$friendlyName] = $existingCursors[$friendlyName];
                }
            }
        }
        
        // Update cursors in the CompanyEmail model
        if ($newCursors !== $existingCursors) {
            $apiJson = $companyEmail->api_json;
            $apiJson['cursors'] = $newCursors;
            $companyEmail->update(['api_json' => $apiJson]);
        }
        
        return [
            'messages' => $allMessages,
            'cursors' => $newCursors,
        ];
    }

    /**
     * Download an attachment from Nylas API.
     */
    public function downloadAttachment(string $attachmentId, string $grantId, string $messageId): string
    {
        $url = $this->baseUrl . "/grants/{$grantId}/attachments/{$attachmentId}/download?message_id={$messageId}";

        // Make the GET request
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/octet-stream',
        ])->get($url);

        if ($response->successful()) {
            return $response->body(); // Return the downloaded attachment content
        } else {
            throw new Exception('Failed to download attachment: ' . $response->status());
        }
    }
}
