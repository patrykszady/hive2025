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
        
        // Encode popup flag and CSRF token in state parameter
        $state = json_encode([
            'csrf' => csrf_token(),
            'popup' => true,
        ]);
        
        $params = [
            'client_id' => config('nylas.client_id'),
            'redirect_uri' => config('nylas.redirect_uri'),
            'response_type' => 'code',
            'access_type' => 'online',
            // 'scope' => config('nylas.scopes'),
            'state' => base64_encode($state),
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
     * Make authenticated HTTP request to Nylas API
     */
    /**
     * Retry a callable with exponential backoff
     * 
     * @param callable $callback The function to retry
     * @param int $maxAttempts Maximum number of attempts (default: 3)
     * @param int $delayMs Initial delay in milliseconds (default: 1000)
     * @return mixed
     */
    protected function retryWithBackoff(callable $callback, int $maxAttempts = 3, int $delayMs = 1000)
    {
        $attempt = 1;
        
        while ($attempt <= $maxAttempts) {
            try {
                $result = $callback();
                
                // Check if result indicates a retryable error
                if (is_array($result) && isset($result['status'])) {
                    // 504 Gateway Timeout is not worth retrying - provider can't complete the request
                    // 503 Service Unavailable, 429 Too Many Requests, 502 Bad Gateway are worth retrying
                    $retryableStatuses = [503, 429, 502];
                    
                    if (in_array($result['status'], $retryableStatuses) && $attempt < $maxAttempts) {
                        Log::channel('nylas')->warning('Retryable error encountered, retrying...', [
                            'attempt' => $attempt,
                            'max_attempts' => $maxAttempts,
                            'status' => $result['status'],
                            'delay_ms' => $delayMs,
                        ]);
                        
                        usleep($delayMs * 1000); // Convert to microseconds
                        $delayMs *= 2; // Exponential backoff
                        $attempt++;
                        continue;
                    }
                }
                
                return $result;
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                
                Log::channel('nylas')->warning('Exception during API call, retrying...', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                    'delay_ms' => $delayMs,
                ]);
                
                usleep($delayMs * 1000);
                $delayMs *= 2;
                $attempt++;
            }
        }
        
        return $callback(); // Final attempt
    }

    protected function makeNylasRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        return $this->retryWithBackoff(function () use ($method, $endpoint, $data, $headers) {
            $url = $this->baseUrl . $endpoint;
            
            $defaultHeaders = [
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept' => 'application/json',
            ];
            
            if (in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'])) {
                $defaultHeaders['Content-Type'] = 'application/json';
            }
            
            $headers = array_merge($defaultHeaders, $headers);
            
            try {
                $response = Http::withHeaders($headers)->$method($url, $data);
                
                return [
                    'status' => $response->status(),
                    'success' => $response->successful(),
                    'data' => $response->json(),
                    'body' => $response->body(),
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 500,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Send an email through Nylas for the specified grant.
     */
    public function sendEmail(string $grantId, array $payload): array
    {
        $response = $this->makeNylasRequest('POST', "/grants/{$grantId}/messages/send", $payload);

        if (!($response['success'] ?? false)) {
            Log::channel('nylas')->error('Nylas email send failed', [
                'grant_id' => $grantId,
                'to' => collect($payload['to'] ?? [])->pluck('email')->filter()->values()->all(),
                'subject' => $payload['subject'] ?? null,
                'status' => $response['status'] ?? null,
                'error' => $response['error'] ?? $response['body'] ?? null,
            ]);
        }

        return $response;
    }

    /**
     * Move or delete a message
     */
    public function moveOrDeleteMessage(string $messageId, string $grantId, ?int $companyEmailId = null, ?string $folderId = null): bool
    {
        if ($folderId) {
            // Move to folder
            $endpoint = "/grants/{$grantId}/messages/{$messageId}";
            $response = $this->makeNylasRequest('PATCH', $endpoint, ['folders' => [$folderId]]);
            $action = 'move';
        } else {
            // Delete (move to trash)
            $endpoint = "/grants/{$grantId}/messages/{$messageId}";
            $response = $this->makeNylasRequest('DELETE', $endpoint);
            $action = 'delete';
        }

        if (!$response['success']) {
            // 404 means message doesn't exist - it's already gone, which is fine
            if ($response['status'] === 404) {
                Log::channel('nylas')->info("Email {$action} skipped - message not found (already moved/deleted)", [
                    'message_id' => $messageId,
                    'folder_id' => $folderId,
                    'grant_id' => $grantId,
                ]);
                return true; // Consider this success since the message is gone
            }
            
            Log::channel('nylas')->error("Email {$action} failed", [
                'message_id' => $messageId,
                'folder_id' => $folderId,
                'grant_id' => $grantId,
                'status_code' => $response['status'],
                'error' => $response['error'] ?? $response['body'],
            ]);
            return false;
        }
        
        return true;
    }

    /**
     * Legacy method for backward compatibility
     */
    public function moveEmailToFolder($messageId, $folderId, $grantId, ?int $companyEmailId = null): bool
    {
        return $this->moveOrDeleteMessage($messageId, $grantId, $companyEmailId, $folderId);
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

    public function getMessages(string $grantId, array $queryParams = [], bool $withHeaders = false, ?\App\Models\CompanyEmail $companyEmail = null): array
    {
        $query = array_filter(
            $queryParams,
            static fn ($value) => $value !== null && $value !== ''
        );

        $requestedFolder = null;
        $resolvedFolder = null;

        if (isset($query['in']) && is_string($query['in']) && $query['in'] !== '') {
            $requestedFolder = $query['in'];
            $resolvedFolder = $this->resolveFolderIdentifier($grantId, $query['in'], $companyEmail);
            if ($resolvedFolder) {
                $query['in'] = $resolvedFolder;
            }
        }

        if ($withHeaders) {
            $query['fields'] = 'include_headers';
        }

        return $this->retryWithBackoff(function () use ($grantId, $query, $withHeaders, $requestedFolder, $resolvedFolder) {
            try {
                $response = $this->httpClient->get($this->baseUrl . "/grants/{$grantId}/messages", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'query' => $query,
                ]);

                $decoded = json_decode($response->getBody(), true) ?? [];

                if (!is_array($decoded)) {
                    Log::channel('nylas')->warning('Unexpected getMessages response payload', [
                        'grant_id' => $grantId,
                        'with_headers' => $withHeaders,
                        'query_params' => $query,
                        'requested_folder' => $requestedFolder,
                        'resolved_folder' => $resolvedFolder,
                    ]);

                    return [
                        'status' => $response->getStatusCode(),
                        'data' => [],
                        'next_cursor' => null,
                        'request_id' => $response->hasHeader('x-request-id')
                            ? $response->getHeader('x-request-id')[0]
                            : null,
                    ];
                }

                $data = $decoded['data'] ?? [];

                return [
                    'status' => $response->getStatusCode(),
                    'data' => $data,
                    'next_cursor' => $decoded['next_cursor'] ?? null,
                    'request_id' => $decoded['request_id'] ?? ($response->hasHeader('x-request-id')
                        ? $response->getHeader('x-request-id')[0]
                        : null),
                ];
            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
                $isRetriableError = in_array($statusCode, [503, 429, 502, 504]);
                
                // Only log as error if not retriable, otherwise warning since retries were exhausted
                if ($isRetriableError) {
                    Log::channel('nylas')->warning('Get Messages API Error (Retries Exhausted)', ApiErrorFormatter::format($e, [
                        'grant_id' => $grantId,
                        'with_headers' => $withHeaders,
                        'query_params' => $query,
                        'requested_folder' => $requestedFolder,
                        'resolved_folder' => $resolvedFolder,
                    ]));
                } else {
                    Log::channel('nylas')->error('Get Messages API Error', ApiErrorFormatter::format($e, [
                        'grant_id' => $grantId,
                        'with_headers' => $withHeaders,
                        'query_params' => $query,
                        'requested_folder' => $requestedFolder,
                        'resolved_folder' => $resolvedFolder,
                    ]));
                }
                
                $response = $e->getResponse();

                return [
                    'status' => $statusCode,
                    'data' => [],
                    'error' => $e->getMessage(),
                    'request_id' => $response && $response->hasHeader('x-request-id')
                        ? $response->getHeader('x-request-id')[0]
                        : null,
                ];
            }
        });
    }

    protected function resolveFolderIdentifier(string $grantId, string $identifier, ?\App\Models\CompanyEmail $companyEmail = null): ?string
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }

        // Heuristic: Nylas folder IDs are long base64-like strings (>= 20 chars) 
        // They often start with AAk/AAMk/AQMk and may contain '=' for padding
        // If it's already a long alphanumeric string, assume it's a folder ID
        if (strlen($trimmed) >= 20 && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $trimmed)) {
            return $trimmed;
        }

        $normalized = strtolower($trimmed);
        
        // Check config first for known grant/folder mappings (avoids API calls)
        $configFolderId = $this->getConfigFolderId($grantId, $normalized);
        if ($configFolderId) {
            return $configFolderId;
        }
        
        // For company emails, check if they have a cached HIVE_RECEIPTS_FOLDER in api_json
        if ($companyEmail && $companyEmail->grant_id === $grantId) {
            $apiJson = $companyEmail->api_json ?? [];
            
            // Check if requesting inbox - return null to use default
            if (in_array($normalized, ['inbox', '\\inbox'])) {
                return null;
            }
            
            // If they have a cached HIVE_RECEIPTS_FOLDER and requesting something with "hive" and "receipt", use it
            if (isset($apiJson['HIVE_RECEIPTS_FOLDER']) && is_string($apiJson['HIVE_RECEIPTS_FOLDER'])) {
                if (str_contains($normalized, 'hive') && str_contains($normalized, 'receipt')) {
                    return $apiJson['HIVE_RECEIPTS_FOLDER'];
                }
            }
        }
        
        // Inbox requests without company email - return null to use default inbox (this is expected)
        if (in_array($normalized, ['inbox', '\\inbox'])) {
            return null;
        }
        
        // If we reach here with a folder name (not ID), something unexpected is happening
        // Log a warning so we can investigate, but return the original value
        if (!str_contains($trimmed, '=')) {
            \Illuminate\Support\Facades\Log::channel('nylas')->warning('Folder name resolution requested for unknown grant/folder combination', [
                'grant_id' => $grantId,
                'folder' => $trimmed,
                'has_company_email' => $companyEmail !== null,
            ]);
        }
        
        return $trimmed;
    }

    protected function getConfigFolderId(string $grantId, string $folderName): ?string
    {
        // Receipts grant
        if ($grantId === config('nylas.receipts_grant_id')) {
            return match($folderName) {
                'inbox' => null, // Use default inbox, no special ID needed
                'deleted', 'deleteditems' => config('nylas.receipts_deleted_folder_id'),
                'duplicate', 'duplicates' => config('nylas.hive_receipts_duplicate_folder_id'),
                'error', 'errors' => config('nylas.hive_receipts_error_folder_id'),
                'needtoadd', 'need_to_add' => config('nylas.hive_receipts_need_to_add_folder_id'),
                'saved' => config('nylas.hive_receipts_saved_folder_id'),
                'test' => config('nylas.hive_receipts_test_folder_id'),
                default => null,
            };
        }
        
        // Insurance grant
        if ($grantId === config('nylas.certificates_grant_id')) {
            return match($folderName) {
                'inbox' => null, // Use default inbox
                'saved', 'certificates' => config('nylas.certificates_saved_folder_id'),
                'error', 'errors' => config('nylas.certificates_error_folder_id'),
                'deleted', 'deleteditems' => config('nylas.certificates_deleted_folder_id'),
                default => null,
            };
        }
        
        return null;
    }

    /**
     * Unified folder fetcher supporting both incremental (bounded) and full fetch.
     * Options:
     *  - full_fetch: bool (if true, ignores limit & received_after; fetches all pages)
     *  - limit: int|null (default config('nylas.message_limit') when not full_fetch)
     *  - received_after: int|Carbon|null (timestamp or Carbon instance for incremental sync)
     *  - include_headers: bool (default false)
     *  - raw_params: array (extra query params to merge last; unsafe / advanced usage)
     *  - company_email: CompanyEmail|null (for logging context; when provided and NOT full_fetch)
     */
    public function fetchFolderMessages(string $grantId, string $folder, array $options = []): array
    {
        $fullFetch      = (bool)($options['full_fetch'] ?? false);
        $limit          = $options['limit'] ?? null;
        $receivedAfter  = $options['received_after'] ?? null;
        $includeHeaders = (bool)($options['include_headers'] ?? false);
        /** @var CompanyEmail|null $companyEmail */
        $companyEmail   = $options['company_email'] ?? null;
        $rawParams      = $options['raw_params'] ?? [];

        // Normalize received_after to timestamp when provided & not full fetch
        if (!$fullFetch && $receivedAfter instanceof Carbon) {
            $receivedAfter = $receivedAfter->timestamp;
        }

        // Derive default limit & received_after for incremental mode
        if (!$fullFetch) {
            if ($limit === null) {
                $limit = config('nylas.message_limit');
            }
            if ($receivedAfter === null) {
                $receivedAfter = Carbon::now()->subDays(config('nylas.message_limit_days'))->timestamp;
            }
        }

        // Base query params
        $params = ['in' => $folder];
        if (!$fullFetch) {
            if ($limit) { $params['limit'] = $limit; }
            if ($receivedAfter) { $params['received_after'] = $receivedAfter; }
        }
        // Merge raw_params last to allow explicit overrides (use cautiously)
        if (!empty($rawParams) && is_array($rawParams)) {
            $params = array_merge($params, $rawParams);
        }

        $all = [];
        $next = null;
        $page = 1;
        do {
            $current = $params;
            if ($next) {
                $current['page_token'] = $next;
            }
            $resp = $this->getMessages($grantId, $current, $includeHeaders, $companyEmail);
            $data = $resp['data'] ?? [];

            if (!is_array($data)) {
                if ($companyEmail) {
                    Log::channel('nylas')->warning('Unexpected response format while fetching folder', [
                        'company_email_id' => $companyEmail->id,
                        'grant_id' => $grantId,
                        'folder' => $folder,
                        'page' => $page,
                    ]);
                } else {
                    Log::channel('nylas')->warning('Unexpected response format while fetching folder (stateless)', [
                        'grant_id' => $grantId,
                        'folder' => $folder,
                        'page' => $page,
                    ]);
                }
                break;
            }

            foreach ($data as $m) {
                if (!isset($m['id'])) { continue; }
                $all[$m['id']] = $m; // de-dupe if overlapping pages
            }

            $next = $resp['next_cursor'] ?? null;
            $page++;

            // Safety stop (only for full fetch) if a soft cap is configured
            if ($fullFetch) {
                $cap = (int) config('nylas.full_fetch_soft_cap', 0); // 0 means no cap
                if ($cap > 0 && count($all) >= $cap) {
                    Log::channel('nylas')->info('Full fetch soft cap reached', [
                        'grant_id' => $grantId,
                        'folder' => $folder,
                        'cap' => $cap,
                        'fetched' => count($all),
                    ]);
                    break;
                }
            }
        } while ($next);

        return array_values($all);
    }

    /**
     * Deprecated: use fetchFolderMessages($grantId, $folder, ['full_fetch'=>true]) instead.
     */
    public function getAllMessagesForFolder(string $grantId, string $folder, array $queryParams = []): array
    {
        $rawParams = $queryParams; // keep legacy behavior of passing arbitrary params
        return $this->fetchFolderMessages($grantId, $folder, [
            'full_fetch' => true,
            'raw_params' => $rawParams,
        ]);
    }

    /**
     * Fetch a single message with full details.
     */
    public function getMessage(string $grantId, string $messageId, bool $withHeaders = false): array
    {
        $url = $this->baseUrl . "/grants/{$grantId}/messages/{$messageId}";
        if ($withHeaders) {
            $url .= '?fields=include_headers';
        }

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody(), true) ?? [];
        } catch (RequestException $e) {
            Log::channel('nylas')->error('Get Single Message API Error', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
                'message_id' => $messageId,
                'with_headers' => $withHeaders,
            ]));
            return [];
        }
    }

    /**
     * Fetch messages for a specific folder with date filtering.
     * Uses 'received_after' timestamp parameter directly.
     */
    public function getMessagesForFolder(array $queryParams, string $grantId, string $folderName, CompanyEmail $companyEmail): array
    {
        // Map legacy $queryParams to new options.
        $limit = $queryParams['limit'] ?? null;
        $receivedAfter = $queryParams['received_after'] ?? null;
        return $this->fetchFolderMessages($grantId, $folderName, [
            'full_fetch' => false,
            'limit' => $limit,
            'received_after' => $receivedAfter,
            'company_email' => $companyEmail,
            'raw_params' => $queryParams, // preserve any additional params passed previously
        ]);
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
        $folderResults = [];
        
        // Create a mapping from folder IDs to friendly names
        $folderIdToName = array_flip($companyEmail->api_json['folders'] ?? []);
        
        foreach ($folders as $folder) {
            $folderKey = (string) $folder;
            $friendlyName = $folderIdToName[$folderKey] ?? $folderKey;
            
            $params = [
                'limit' => config('nylas.message_limit'),
                'in' => $folderKey,
                'received_after' => Carbon::now()->subDays(config('nylas.message_limit_days'))->timestamp,
            ];
            
            $messages = $this->getMessagesForFolder($params, $grantId, $friendlyName, $companyEmail);
            $messageCount = count($messages);
            
            $folderResults[$friendlyName] = [
                'count' => $messageCount,
            ];
            
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

    /**
     * Clone ("forward-like") a message by re-sending its content & attachments
     * to the centralized receipts mailbox using the source grant's send endpoint.
     */
    public function sendForwardCopy(string $sourceGrantId, string $messageId, bool $includeAttachments = true, int $companyEmailId): array
    {
        // Fetch original message (with attachments meta)
        $original = $this->getMessage($sourceGrantId, $messageId, true);
        if (empty($original)) {
            return [ 'status' => 404, 'error' => 'Original message fetch returned empty response' ];
        }

        $requestId = $original['request_id'] ?? null;
        $messageData = $original['data'] ?? [];
        if (empty($messageData) || !isset($messageData['id'])) {
            Log::channel('nylas')->warning('Message fetch returned unexpected structure', [
                'source_grant_id' => $sourceGrantId,
                'message_id' => $messageId,
                'request_id' => $requestId,
            ]);
            return [ 'status' => 404, 'error' => 'Message not found or invalid format' ];
        }

        $companyEmail = CompanyEmail::withoutGlobalScopes()->find($companyEmailId);
        if (!$companyEmail) {
            Log::channel('nylas')->warning('CompanyEmail record missing while forwarding receipt', [
                'source_grant_id' => $sourceGrantId,
                'message_id' => $messageId,
                'company_email_id' => $companyEmailId,
            ]);
        }

        $origSubject = $messageData['subject'] ?? '(no subject)';
        $bodyHtml = $messageData['body'] ?? '';

        // If body is non-empty but plain text (no HTML tags), convert to basic HTML preserving formatting.
        if ($bodyHtml !== '' && !preg_match('/<\w+[^>]*>/u', $bodyHtml)) {
            // Normalize line endings then HTML-escape and convert newlines to <br>.
            $normalized = str_replace(["\r\n", "\r"], "\n", $bodyHtml);
            $escaped = htmlspecialchars($normalized, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // Convert double line breaks into paragraph breaks for readability.
            $escaped = preg_replace("/\n{2,}/", "</p><p>", $escaped);
            $escaped = nl2br($escaped, false); // single newlines become <br>
            $bodyHtml = '<div style="font-family:monospace;white-space:pre-wrap"><p>' . $escaped . '</p></div>';
        }

        // If body is empty, attempt raw MIME fetch and naive extraction of HTML/text part
        if ($bodyHtml === '') {
            $raw = $this->getRawMime($sourceGrantId, $messageId);
            if ($raw && isset($raw['data'])) {
                $mime = $raw['data'];
                // Very naive extraction: look for first <html>...</html>
                if (preg_match('/<html[\s\S]*?<\/html>/i', $mime, $m)) {
                    $bodyHtml = $m[0];
                } else {
                    // fallback: wrap plain text
                    $snippet = substr($mime, 0, 4000);
                    $bodyHtml = '<pre style="white-space:pre-wrap;font-family:monospace">'.htmlspecialchars($snippet, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</pre>';
                }
            }
            if ($bodyHtml === '') {
                $bodyHtml = '<p>(Original body not returned)</p>';
            }
        }

        // Prepare attachments (inline exclude). Nylas send expects base64 in 'content'.
        $attachmentsPayload = [];
        if ($includeAttachments && !empty($messageData['attachments'])) {
            foreach ($messageData['attachments'] as $att) {
                if (!empty($att['is_inline'])) { continue; }
                $attId = $att['id'] ?? null;
                if (! $attId) { continue; }
                try {
                    $binary = $this->downloadAttachment($attId, $sourceGrantId, $messageId);
                    $attachmentsPayload[] = [
                        'filename' => $att['filename'] ?? ('attachment-' . $attId),
                        'content_type' => $att['content_type'] ?? 'application/octet-stream',
                        'content' => base64_encode($binary),
                    ];
                } catch (Exception $e) {
                    Log::channel('nylas')->warning('Attachment download failed; skipping', [
                        'message_id' => $messageId,
                        'attachment_id' => $attId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Send using the receipts grant ID to avoid cluttering source account's sent folder
        $receiptsGrantId = config('nylas.receipts_grant_id');
        $endpoint = $this->baseUrl . "/grants/{$receiptsGrantId}/messages/send";

        // Use original body without any modification - no header injection
        $finalBody = $bodyHtml ?: '(original message body unavailable)';

        // Extract original message metadata for custom headers
        $fromEmail = $messageData['from'][0]['email'] ?? null;
        
        // Check if this is a forwarded email and extract original sender from body
        if (!empty($bodyHtml) && preg_match('/From:.*?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i', $bodyHtml, $matches)) {
            $originalFromEmail = strtolower(trim($matches[1]));
            if (!empty($originalFromEmail) && filter_var($originalFromEmail, FILTER_VALIDATE_EMAIL)) {
                $fromEmail = $originalFromEmail;
            }
        }
        
        $sourceMailboxEmail = $companyEmail?->email ?? ($messageData['to'][0]['email'] ?? null);
        $originalToEmail = $messageData['to'][0]['email'] ?? null;

        // Build single custom header with all metadata as JSON (to avoid provider header limits)
        $metadata = [
            'from_email' => (string) $fromEmail,
            'to_email' => (string) $sourceMailboxEmail,
            'subject' => (string) $origSubject,
            'unix_date' => (string) $messageData['date'],
            'company_email_id' => (string) $companyEmailId,
            'original_to_email' => (string) $originalToEmail,
        ];
        
        $customHeaders = [
            ['name' => 'X-Hive-Metadata', 'value' => json_encode($metadata)],
        ];

        $payload = [
            'to' => [ ['email' => config('nylas.receipts_email')] ], // Destination mailbox (central receipts)
            'subject' => $origSubject,
            'body' => $finalBody,
            'attachments' => $attachmentsPayload,
            'custom_headers' => $customHeaders,
        ];

        try {
            $response = $this->makeNylasRequest('POST', "/grants/{$receiptsGrantId}/messages/send", $payload);

            if (!$response['success']) {
                Log::channel('nylas')->error('Send forward copy failed', [
                    'source_grant_id' => $sourceGrantId,
                    'receipts_grant_id' => $receiptsGrantId,
                    'company_email_id' => $companyEmailId,
                    'message_id' => $messageId,
                    'request_id' => $requestId,
                    'status' => $response['status'],
                    'error' => $response['error'] ?? $response['body'],
                ]);
                return [
                    'status' => $response['status'],
                    'error' => $response['data']['message'] ?? $response['error'] ?? 'Send failed'
                ];
            }
            $sentMessageId = $response['data']['data']['id'] ?? null;
            
            // Only log if there's an issue with sent_message_id extraction
            if (!$sentMessageId) {
                Log::channel('nylas')->warning('Send forward copy success but no sent message ID returned', [
                    'source_grant_id' => $sourceGrantId,
                    'receipts_grant_id' => $receiptsGrantId,
                    'company_email_id' => $companyEmailId,
                    'message_id' => $messageId,
                    'status' => $response['status'],
                    'request_id' => $requestId,
                ]);
            }
            
            // Move the original message to HIVE_RECEIPTS_FOLDER after successful forwarding
            // Delete the sent copy from receipts grant ID's sent folder
            $this->moveOriginalMessageToHiveFolder($sourceGrantId, $messageId, $companyEmailId, $sentMessageId, $receiptsGrantId);
            
            return [
                'status' => $response['status'],
                'data' => $response['data'] + [
                    'request_id' => $requestId,
                ],
            ];
        } catch (Exception $e) {
            Log::channel('nylas')->error('Send forward copy exception', ApiErrorFormatter::format($e, [
                'source_grant_id' => $sourceGrantId,
                'receipts_grant_id' => $receiptsGrantId,
                'company_email_id' => $companyEmailId,
                'message_id' => $messageId,
                'request_id' => $requestId,
            ]));
            return [
                'status' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Move the original message to HIVE_RECEIPTS_FOLDER after successful forwarding
     */
    public function moveOriginalMessageToHiveFolder(string $sourceGrantId, string $messageId, int $companyEmailId, ?string $sentMessageId = null, ?string $receiptsGrantId = null): void
    {
        try {
            // Get the company email to access the HIVE_RECEIPTS_FOLDER ID
            $companyEmail = CompanyEmail::find($companyEmailId);
            if (!$companyEmail) {
                Log::channel('nylas')->warning('Company email not found for moving message', [
                    'company_email_id' => $companyEmailId,
                    'source_grant_id' => $sourceGrantId,
                    'message_id' => $messageId,
                ]);
                return;
            }

            $hiveReceiptsFolderId = $companyEmail->api_json['HIVE_RECEIPTS_FOLDER'] ?? null;
            if (!$hiveReceiptsFolderId) {
                Log::channel('nylas')->warning('HIVE_RECEIPTS_FOLDER not found in company email', [
                    'company_email_id' => $companyEmailId,
                    'source_grant_id' => $sourceGrantId,
                    'message_id' => $messageId,
                ]);
                return;
            }

            // Move the original message to HIVE_RECEIPTS_FOLDER using unified method
            $this->moveOrDeleteMessage($messageId, $sourceGrantId, $companyEmailId, $hiveReceiptsFolderId);
            
            // Delete the sent copy from receipts grant's sent folder to declutter
            if ($sentMessageId && $receiptsGrantId) {
                $deletedFolderId = config('nylas.receipts_deleted_folder_id');
                $success = $this->moveOrDeleteMessage($sentMessageId, $receiptsGrantId, $companyEmailId, $deletedFolderId);
                
                // Only log if it actually failed (not 404 which means already gone)
                if (!$success) {
                    Log::channel('nylas')->warning('Could not move forwarded sent copy to deleted folder', [
                        'sent_message_id' => $sentMessageId,
                        'receipts_grant_id' => $receiptsGrantId,
                        'deleted_folder_id' => $deletedFolderId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('nylas')->error('Exception moving original message to HIVE folder', ApiErrorFormatter::format($e, [
                'company_email_id' => $companyEmailId,
                'source_grant_id' => $sourceGrantId,
                'message_id' => $messageId,
            ]));
        }
    }

    /**
     * Fetch raw RFC822 for a message (if supported). Returns ['status'=>, 'data'=> raw_string] or ['status'=>404].
     */
    protected function getRawMime(string $grantId, string $messageId): array
    {
        $url = $this->baseUrl . "/grants/{$grantId}/messages/{$messageId}/rfc822";
        try {
            $resp = Http::withHeaders([
                'Accept' => 'message/rfc822, text/plain, */*',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get($url);
            if (! $resp->successful()) {
                return ['status' => $resp->status(), 'error' => 'raw fetch failed'];
            }
            return ['status' => 200, 'data' => $resp->body()];
        } catch (\Throwable $e) {
            Log::channel('nylas')->warning('Raw MIME fetch failed', [
                'grant_id' => $grantId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return ['status' => 500, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch messages matching receipt criteria for forwarding
     */
    public function getMessagesMatchingCriteria(string $grantId, array $receiptCriteria, Carbon $receivedAfter, ?\App\Models\CompanyEmail $companyEmail = null): array
    {
        if (empty($receiptCriteria)) {
            return [];
        }
        
        // Get inbox folder ID from company email's api_json if available
        $inboxFolderId = $companyEmail->api_json['INBOX_FOLDER'] ?? null;
        
        // In non-production, look back a year to test with older messages
        $lookbackDate = env('APP_ENV') === 'production'
            ? $receivedAfter
            : Carbon::now()->subYear();
        
        // Build base query params - will paginate through all results
        $baseQuery = [
            'limit' => 50, // Per-page limit to avoid timeouts
            'received_after' => $lookbackDate->timestamp,
        ];
        
        // Only add 'in' if we have the inbox folder ID
        if ($inboxFolderId) {
            $baseQuery['in'] = $inboxFolderId;
        }
        
        // Paginate through all messages in date range
        $allMessages = [];
        $nextCursor = null;
        $maxPages = 10; // Safety limit: 10 pages * 50 = 500 messages max
        $page = 0;
        
        do {
            $query = $baseQuery;
            if ($nextCursor) {
                $query['page_token'] = $nextCursor;
            }
            
            $resp = $this->getMessages($grantId, $query, false, $companyEmail);
            $messages = $resp['data'] ?? [];
            
            if (!is_array($messages)) {
                break;
            }
            
            foreach ($messages as $m) {
                if (isset($m['id'])) {
                    $allMessages[$m['id']] = $m; // De-dupe by ID
                }
            }
            
            $nextCursor = $resp['next_cursor'] ?? null;
            $page++;
        } while ($nextCursor && $page < $maxPages);

        // Fast filtering using pre-built criteria
        $aggregated = [];
        foreach ($allMessages as $m) {
            if (empty($m['id'])) {
                continue;
            }
            
            $fromEmail = strtolower($m['from'][0]['email'] ?? '');
            $messageSubject = strtolower($m['subject'] ?? '');
            $messageBody = $m['body'] ?? '';
            
            // Check if this is a forwarded email and extract original sender
            // Look for email address immediately after "From:" in the body
            if (preg_match('/From:.*?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i', $messageBody, $matches)) {
                $originalFromEmail = strtolower(trim($matches[1]));
                if (!empty($originalFromEmail) && filter_var($originalFromEmail, FILTER_VALIDATE_EMAIL)) {
                    $fromEmail = $originalFromEmail;
                }
            }

            // Clean subject - remove Fw:, Fwd:, Re: prefixes for matching
            $cleanSubject = preg_replace('/^(fw:|fwd:|re:)\s*/i', '', $messageSubject);
            
            // Check all receipt criteria (both specific emails and domain patterns)
            $messageIncluded = false;
            foreach ($receiptCriteria as $address => $requiredSubjects) {
                $isMatch = str_starts_with($address, '@') 
                    ? str_ends_with($fromEmail, $address)  // Domain pattern
                    : $fromEmail === $address;            // Exact email
                
                if ($isMatch) {
                    // If no subject requirements or subject matches
                    if (empty($requiredSubjects)) {
                        $aggregated[$m['id']] = $m;
                        $messageIncluded = true;
                        break; // Found match, no need to check other criteria
                    }
                    
                    // Check subject requirements (use cleaned subject)
                    foreach ($requiredSubjects as $reqSubject) {
                        if (str_contains($cleanSubject, $reqSubject)) {
                            $aggregated[$m['id']] = $m;
                            $messageIncluded = true;
                            break; // Break inner loop only
                        }
                    }
                    
                    // If we found a match, no need to check more criteria
                    if ($messageIncluded) {
                        break;
                    }
                }
            }
        }
 
        return array_values($aggregated); // Return indexed array
    }

    /**
     * Create a folder in the specified grant
     */
    public function createFolder(string $grantId, string $folderName, ?string $parentFolderId = null): array
    {
        $payload = ['name' => $folderName];
        if ($parentFolderId) {
            $payload['parent_id'] = $parentFolderId;
        }
        
        $response = $this->makeNylasRequest('POST', "/grants/{$grantId}/folders", $payload);
        
        if (!$response['success']) {
            Log::channel('nylas')->error('Create Folder Failed', [
                'grant_id' => $grantId,
                'folder_name' => $folderName,
                'error' => $response['error'] ?? $response['body'],
            ]);
        }
        
        return [
            'status' => $response['status'] ?? 500,
            'data' => $response['data'] ?? [],
            'error' => $response['error'] ?? null,
        ];
    }

    /**
     * Get all folders for the specified grant
     */
    public function getFolders(string $grantId): array
    {
        $response = $this->makeNylasRequest('GET', "/grants/{$grantId}/folders");
        
        if (!$response['success']) {
            // Only log if it's not a timeout/503 error (those are already retried)
            $status = $response['status'] ?? 0;
            $isRetriableError = in_array($status, [503, 429, 502, 504]);
            
            if (!$isRetriableError) {
                Log::channel('nylas')->error('Get Folders Failed', [
                    'grant_id' => $grantId,
                    'error' => $response['error'] ?? $response['body'],
                    'status' => $status,
                ]);
            } else {
                // Log as warning since retries were exhausted
                Log::channel('nylas')->warning('Get Folders Failed After Retries', [
                    'grant_id' => $grantId,
                    'error' => $response['error'] ?? $response['body'],
                    'status' => $status,
                ]);
            }
        }
        
        return [
            'status' => $response['status'] ?? 500,
            'data' => $response['data'] ?? [],
            'error' => $response['error'] ?? null,
        ];
    }

    /**
     * Create a contact in Nylas
     * 
     * @param string $grantId The grant ID for the account
     * @param array $contactData Contact data (given_name, surname, emails, phone_numbers, etc.)
     * @return array
     */
    public function createContact(string $grantId, array $contactData): array
    {
        $response = $this->makeNylasRequest('POST', "/grants/{$grantId}/contacts", $contactData);
        
        if (!$response['success']) {
            Log::channel('nylas')->error('Create Contact Failed', [
                'grant_id' => $grantId,
                'contact_data' => $contactData,
                'error' => $response['error'] ?? $response['body'],
            ]);
        }
        
        return [
            'status' => $response['status'] ?? 500,
            'data' => $response['data'] ?? [],
            'error' => $response['error'] ?? null,
        ];
    }

    /**
     * Get a contact from Nylas
     * 
     * @param string $grantId The grant ID for the account
     * @param string $contactId The contact ID to retrieve
     * @return array
     */
    public function getContact(string $grantId, string $contactId): array
    {
        $response = $this->makeNylasRequest('GET', "/grants/{$grantId}/contacts/{$contactId}");
        
        return [
            'status' => $response['status'] ?? 500,
            'data' => $response['data'] ?? [],
            'error' => $response['error'] ?? null,
            'exists' => $response['success'] ?? false,
        ];
    }

    /**
     * Update a contact in Nylas
     * 
     * @param string $grantId The grant ID for the account
     * @param string $contactId The contact ID to update
     * @param array $contactData Updated contact data
     * @return array
     */
    public function updateContact(string $grantId, string $contactId, array $contactData): array
    {
        $response = $this->makeNylasRequest('PUT', "/grants/{$grantId}/contacts/{$contactId}", $contactData);
        
        if (!$response['success']) {
            Log::channel('nylas')->error('Update Contact Failed', [
                'grant_id' => $grantId,
                'contact_id' => $contactId,
                'contact_data' => $contactData,
                'error' => $response['error'] ?? $response['body'],
            ]);
        }
        
        return [
            'status' => $response['status'] ?? 500,
            'data' => $response['data'] ?? [],
            'error' => $response['error'] ?? null,
        ];
    }

    /**
     * Delete a contact from Nylas
     * 
     * @param string $grantId The grant ID for the account
     * @param string $contactId The contact ID to delete
     * @return array
     */
    public function deleteContact(string $grantId, string $contactId): array
    {
        $response = $this->makeNylasRequest('DELETE', "/grants/{$grantId}/contacts/{$contactId}");
        
        if (!$response['success']) {
            Log::channel('nylas')->error('Delete Contact Failed', [
                'grant_id' => $grantId,
                'contact_id' => $contactId,
                'error' => $response['error'] ?? $response['body'],
            ]);
        }
        
        return [
            'status' => $response['status'] ?? 500,
            'data' => $response['data'] ?? [],
            'error' => $response['error'] ?? null,
        ];
    }

}
