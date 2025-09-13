<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use Exception;
use App\Support\ApiJsonProxy;

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
        /**
         * Strategy improvements:
         *  - Adaptive limit taper: start at provided (capped 50) -> 15 -> 7 -> 3 (if extra attempts requested)
         *  - Detect 504 provider_timeout_error: immediately reduce limit & add received_after (last 2 days) constraint if not set.
         *  - Detect 429 rate_limit_error: respect Retry-After header (if any) or exponential backoff with jitter.
         *  - Optional incremental sync: if caller passed 'since' (Carbon|string) we map to received_after timestamp.
         *  - Never throw in final failure (controller can continue other folders) – return []. Log enriched context.
         */
        $params = $queryParams;
        if (isset($params['since'])) {
            try {
                $sinceTs = $params['since'] instanceof Carbon ? $params['since'] : Carbon::parse($params['since']);
                $params['received_after'] = $sinceTs->timestamp; // Nylas expects UNIX seconds
            } catch (\Exception $e) {
                // Ignore invalid since
            }
            unset($params['since']);
        }

        $configuredLimits = collect(explode(',', (string) config('nylas.sync.limits', '45,15,7,3')))
            ->map(fn($v) => (int) trim($v))
            ->filter(fn($v) => $v > 0)
            ->values()
            ->all();
        if (empty($configuredLimits)) {
            $configuredLimits = [45,15,7,3];
        }
        $initialLimit = isset($params['limit']) && is_numeric($params['limit'])
            ? (int) $params['limit']
            : $configuredLimits[0];
        $initialLimit = min(max($initialLimit, 5), 50);
        // Replace first element with caller-provided initial limit while keeping fallbacks
        $adaptiveLimits = $configuredLimits;
        $adaptiveLimits[0] = $initialLimit;
        $max = min($maxAttempts, count($adaptiveLimits));

        $lastError = null;
        $lastStatus = null;
        $lastRateLimit = null;
        $baseRateMs = (int) config('nylas.rate_limit.base_backoff_ms', 1500);
        $maxBackoff = (int) config('nylas.rate_limit.max_backoff_ms', 30000);

        for ($idx = 0; $idx < $max; $idx++) {
            $params['limit'] = $adaptiveLimits[$idx];
            try {
                $resp = $this->getMessages($params, $grantId);
                if (is_array($resp) && array_key_exists('data', $resp)) {
                    return [
                        'messages' => $resp['data'] ?? [],
                        'failed' => false,
                        'attempts' => $idx + 1,
                        'limit_used' => $params['limit'],
                        'received_after' => $params['received_after'] ?? null,
                        'status' => $lastStatus,
                    ];
                }
                throw new Exception('Nylas getMessages returned null or invalid payload.');
            } catch (RequestException $e) {
                $response = $e->getResponse();
                $lastStatus = $response?->getStatusCode();
                $retryAfterHeader = $response?->getHeaderLine('Retry-After');
                $remaining = $response?->getHeaderLine('X-RateLimit-Remaining');
                $reset = $response?->getHeaderLine('X-RateLimit-Reset');
                $bodySnippet = null;
                if ($response) {
                    try { $bodySnippet = substr($response->getBody()->getContents(), 0, 300); } catch (\Throwable $t) {}
                }
                $msg = $e->getMessage();
                $lastError = $msg;
                $is429 = $lastStatus === 429;
                $is504 = $lastStatus === 504;
                $is5xx = $lastStatus && $lastStatus >= 500 && $lastStatus < 600;
                $retryable = $is429 || $is504 || $is5xx;

                if ($is504 && !isset($params['received_after'])) {
                    $params['received_after'] = now()->subHours(48)->timestamp;
                }

                Log::channel('nylas')->warning('Nylas getMessages attempt failed', [
                    'grant_id' => $grantId,
                    'folder' => $folderName ?? ($params['in'] ?? null),
                    'attempt' => $idx,
                    'limit' => $params['limit'],
                    'status' => $lastStatus,
                    'received_after' => $params['received_after'] ?? null,
                    'retry_after_header' => $retryAfterHeader,
                    'rate_limit_remaining' => $remaining,
                    'rate_limit_reset' => $reset,
                    'timeout' => $is504,
                    'rate_limited' => $is429,
                    'retryable' => $retryable,
                    'body_snippet' => $bodySnippet,
                    'message' => $msg,
                ]);

                if (!$retryable) {
                    break; // Do not continue attempts for non-retryable errors
                }

                if ($is429) {
                    // Track last rate limit encounter
                    $lastRateLimit = [
                        'at' => now()->timestamp,
                        'retry_after' => $retryAfterHeader,
                        'remaining' => $remaining,
                        'reset' => $reset,
                    ];
                }

                if ($idx < $max - 1) {
                    $sleepMs = 0;
                    if ($is429 && $retryAfterHeader) {
                        // Retry-After can be seconds or HTTP date
                        if (is_numeric($retryAfterHeader)) {
                            $sleepMs = (int) $retryAfterHeader * 1000;
                        } else {
                            try {
                                $sleepMs = max(0, Carbon::parse($retryAfterHeader)->diffInMilliseconds(now()));
                            } catch (\Throwable $t) {
                                $sleepMs = $baseRateMs * ($idx + 1);
                            }
                        }
                    } elseif ($is429) {
                        $sleepMs = $baseRateMs * ($idx + 1);
                    } elseif ($is504) {
                        $sleepMs = 700 + ($idx * 350);
                    } elseif ($is5xx) {
                        $sleepMs = 500 + ($idx * 300);
                    } else {
                        $sleepMs = 400 + ($idx * 200);
                    }
                    $sleepMs = (int) min($sleepMs + random_int(100, 600), $maxBackoff);
                    usleep($sleepMs * 1000);
                }
            } catch (Exception $e) {
                // Non-HTTP exception (parse, runtime)
                $lastError = $e->getMessage();
                $lastStatus = null;
                Log::channel('nylas')->warning('Nylas getMessages internal error', [
                    'grant_id' => $grantId,
                    'folder' => $folderName ?? ($params['in'] ?? null),
                    'attempt' => $idx,
                    'limit' => $params['limit'],
                    'message' => $lastError,
                ]);
                if ($idx >= $max -1) { break; }
            }
        }

        Log::channel('nylas')->error('Nylas getMessages final failure', [
            'grant_id' => $grantId,
            'folder' => $folderName ?? ($params['in'] ?? null),
            'attempts' => $max,
            'final_limit' => $params['limit'] ?? null,
            'received_after' => $params['received_after'] ?? null,
            'last_error' => $lastError,
            'last_status' => $lastStatus,
            'last_rate_limit' => $lastRateLimit,
        ]);
        // Return meta indicating failure instead of throwing
        return [
            'messages' => [],
            'failed' => true,
            'attempts' => $max,
            'limit_used' => $params['limit'] ?? null,
            'received_after' => $params['received_after'] ?? null,
            'error' => $lastError,
            'status' => $lastStatus,
            'rate_limit' => $lastRateLimit,
        ];
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

    /**
     * Perform a multi-folder sync with circuit breaker & incremental cursors.
     *
     * @param string $grantId
     * @param array $apiJson Current api_json structure from CompanyEmail (normalized).
     * @param array $folders List of folder identifiers to sync (names or IDs depending on usage).
     * @param int $baseLimit Initial fetch limit to attempt.
     * @param int $failureThreshold Number of consecutive failures before pausing a folder.
     * @param int $pauseMinutes Minutes to pause a folder after threshold reached.
    * @return array{messages: array, api_json: array} Aggregated messages and ONLY the changed api_json fragments (not the full column) to patch.
     */
    public function syncFolders(
        string $grantId,
        array|ApiJsonProxy $apiJson,
        array $folders,
        int $baseLimit = 45,
        int $failureThreshold = 3,
        int $pauseMinutes = 15
    ): array {
        // Support being passed the ApiJsonProxy directly from the model cast.
        if ($apiJson instanceof ApiJsonProxy) {
            $apiJson = $apiJson->toArray();
        }
        $all = [];

        foreach ($folders as $folder) {
            $folderKey = (string) $folder;
            $apiJson['failures'] = $apiJson['failures'] ?? [];
            $apiJson['sync_cursors'] = $apiJson['sync_cursors'] ?? [];

            // Normalize failure metadata to ensure expected keys exist
            $failureMeta = $apiJson['failures'][$folderKey] ?? [];
            if (! isset($failureMeta['count']) || ! is_int($failureMeta['count'])) {
                $failureMeta['count'] = (int) ($failureMeta['count'] ?? 0);
            }
            if (! array_key_exists('paused_until', $failureMeta)) {
                $failureMeta['paused_until'] = null; // epoch timestamp when folder unpauses
            }
            // Persist normalization back if original structure was incomplete
            if (! isset($apiJson['failures'][$folderKey]) || array_diff_key(['count'=>0,'paused_until'=>null], $apiJson['failures'][$folderKey])) {
                $apiJson['failures'][$folderKey] = $failureMeta;
                $fragmentsToPersist['failures'][$folderKey] = $failureMeta;
            }
            if ($failureMeta['paused_until'] && now()->timestamp < $failureMeta['paused_until']) {
                Log::channel('nylas')->info('Skipping folder due to circuit breaker', [
                    'grant_id' => $grantId,
                    'folder' => $folderKey,
                    'resume_in_s' => $failureMeta['paused_until'] - now()->timestamp,
                ]);
                continue;
            }

            $sinceTs = $apiJson['sync_cursors'][$folderKey] ?? null;
            $params = [
                'limit' => $baseLimit,
                'in' => $folderKey,
            ];
            if ($sinceTs) {
                try {
                    $params['since'] = Carbon::createFromTimestamp($sinceTs)->subMinute();
                } catch (\Throwable $e) {
                    // ignore invalid timestamp
                }
            }

            $result = $this->getMessagesWithRetry($params, $grantId, 3, $folderKey);
            $messages = $result['messages'] ?? [];

            if ($result['failed'] ?? false) {
                $failureMeta['count'] = ($failureMeta['count'] ?? 0) + 1;
                if ($failureMeta['count'] >= $failureThreshold) {
                    $failureMeta['paused_until'] = now()->addMinutes($pauseMinutes)->timestamp;
                    $failureMeta['count'] = 0;
                }
                $apiJson['failures'][$folderKey] = $failureMeta;
                continue; // skip processing failed folder
            } else {
                $apiJson['failures'][$folderKey] = ['count' => 0, 'paused_until' => null];
            }

            if (!empty($messages)) {
                $maxDate = collect($messages)->max(fn($m) => isset($m['date']) ? Carbon::parse($m['date'])->timestamp : 0);
                if ($maxDate) {
                    $apiJson['sync_cursors'][$folderKey] = max(($apiJson['sync_cursors'][$folderKey] ?? 0), $maxDate);
                }
            }

            $all = array_merge($all, $messages);
        }

        // Only return keys that actually changed so callers can patch them without
        // resending the entire stored JSON column (protects unrelated metadata).
        $apiPatch = [
            'sync_cursors' => $apiJson['sync_cursors'] ?? [],
            'failures' => $apiJson['failures'] ?? [],
        ];

        return [
            'messages' => $all,
            'api_json' => $apiPatch,
        ];
    }
}
