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
            // 'scope' => config('nylas.scopes'),
            'state' => csrf_token(),
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
    protected function makeNylasRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
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
    }

    /**
     * Move or delete a message
     */
    public function moveOrDeleteMessage(string $messageId, string $grantId, int $companyEmailId, ?string $folderId = null): bool
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
            Log::channel('nylas')->error("Email {$action} failed", [
                'company_email_id' => $companyEmailId,
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
    public function moveEmailToFolder($messageId, $folderId, $grantId, int $companyEmailId): bool
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

        try {
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
        } catch (RequestException $e) {
            // Log the error but return empty array so sync can continue
            Log::channel('nylas')->error('Get Messages API Error', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
                'with_headers' => $withHeaders,
                'query_params' => $queryParams,
            ]));
            
            // Return empty array instead of error array to allow processing to continue
            return [];
        }
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
        $params = $queryParams;
        $params['limit'] = config('nylas.message_limit');

        try {
            // Pass false for $withHeaders while listing; include_headers seems rejected (400) on list queries.
            $allMessages = [];
            $nextCursor = null;
            
            do {
                // Add cursor to params if we have one for pagination
                $currentParams = $params;
                if ($nextCursor) {
                    $currentParams['page_token'] = $nextCursor;
                }
                
                $resp = $this->getMessages($currentParams, $grantId, false);
                
                if (is_array($resp) && array_key_exists('data', $resp)) {
                    $messages = $resp['data'];
                    $allMessages = array_merge($allMessages, $messages);
                    
                    // Check if there's a next page
                    $nextCursor = $resp['next_cursor'] ?? null;
                } else {
                    Log::channel('nylas')->warning($companyEmail->id . ' Invalid response format', [
                        'grant_id' => $grantId
                    ]);
                    break;
                }
                
            } while ($nextCursor);
            
            return $allMessages;
            
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
        
        // Single summary log instead of per-folder logs
        Log::channel('nylas')->info($companyEmail->id . " Sync completed", [
            'grant_id' => $grantId,
            'total_messages' => count($allMessages),
            'folders_synced' => $folderResults,
        ]);
        
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
        $toEmail   = $messageData['to'][0]['email'] ?? null;

        // Build single custom header with all metadata as JSON (to avoid provider header limits)
        $metadata = [
            'from_email' => (string) $fromEmail,
            'to_email' => (string) $toEmail,
            'subject' => (string) $origSubject,
            'unix_date' => (string) $messageData['date'],
            'company_email_id' => (string) $companyEmailId,
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
    protected function moveOriginalMessageToHiveFolder(string $sourceGrantId, string $messageId, int $companyEmailId, ?string $sentMessageId = null, ?string $receiptsGrantId = null): void
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
            
            // Delete the sent copy from receipts grant ID's sent folder if we have both IDs
            if ($sentMessageId && $receiptsGrantId) {
                $this->deleteForwardedMessageFromSentItems($receiptsGrantId, $companyEmailId, $sentMessageId);
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
     * Move the forwarded message from sent items to configured deleted folder
     */
    protected function deleteForwardedMessageFromSentItems(string $grantId, int $companyEmailId, string $sentMessageId): void
    {
        try {
            $deletedFolderId = config('nylas.receipts_deleted_folder_id');
            
            // Use unified method - pass null for folderId to delete, or actual folderId to move
            $success = $this->moveOrDeleteMessage($sentMessageId, $grantId, $companyEmailId, $deletedFolderId);
            
            if (!$success) {
                $action = $deletedFolderId ? 'move to deleted folder' : 'delete';
                Log::channel('nylas')->error("Failed to {$action} forwarded message", [
                    'grant_id' => $grantId,
                    'company_email_id' => $companyEmailId,
                    'sent_message_id' => $sentMessageId,
                    'deleted_folder_id' => $deletedFolderId,
                ]);
            }
            
        } catch (\Throwable $e) {
            Log::channel('nylas')->error('Exception processing forwarded message deletion', ApiErrorFormatter::format($e, [
                'grant_id' => $grantId,
                'company_email_id' => $companyEmailId,
                'sent_message_id' => $sentMessageId,
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
    public function getMessagesMatchingCriteria(string $grantId, array $receiptCriteria, Carbon $receivedAfter): array
    {
        if (empty($receiptCriteria)) {
            return [];
        }
        
        // Single API call to get all recent messages
        $query = [
            'limit' => 200,
            'in' => 'inbox',
            'received_after' => $receivedAfter->timestamp,
        ];
        
        $resp = $this->getMessages($query, $grantId, false);
        $messages = $resp['data'] ?? [];
        
        // Fast filtering using pre-built criteria
        $aggregated = [];
        foreach ($messages as $m) {
            if (empty($m['id'])) {
                continue;
            }
            
            $fromEmail = strtolower($m['from'][0]['email'] ?? '');
            $messageSubject = strtolower($m['subject'] ?? '');
            
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
                    
                    // Check subject requirements
                    foreach ($requiredSubjects as $reqSubject) {
                        if (str_contains($messageSubject, $reqSubject)) {
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
            'status' => $response['status'],
            'data' => $response['data'],
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
            Log::channel('nylas')->error('Get Folders Failed', [
                'grant_id' => $grantId,
                'error' => $response['error'] ?? $response['body'],
            ]);
        }
        
        return [
            'status' => $response['status'],
            'data' => $response['data'],
            'error' => $response['error'] ?? null,
        ];
    }

    /**
     * Retrieve a grant's metadata (scopes, status, etc.).
     */
    // public function getGrant(string $grantId): array
    // {
    //     $url = $this->baseUrl . "/grants/{$grantId}";
    //     try {
    //         $resp = Http::withHeaders([
    //             'Accept' => 'application/json',
    //             'Authorization' => 'Bearer ' . $this->apiKey,
    //         ])->get($url);
    //         return [
    //             'status' => $resp->status(),
    //             'data' => $resp->json(),
    //         ];
    //     } catch (\Throwable $e) {
    //         Log::channel('nylas')->error('Get Grant Failed', [
    //             'grant_id' => $grantId,
    //             'error' => $e->getMessage(),
    //         ]);
    //         return [
    //             'status' => 500,
    //             'error' => $e->getMessage(),
    //         ];
    //     }
    // }
}
