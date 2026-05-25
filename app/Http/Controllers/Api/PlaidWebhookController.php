<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPlaidTransactionSync;
use App\Mail\BankErrorAlert;
use App\Models\Bank;
use App\Services\PlaidService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PlaidWebhookController extends Controller
{
    public function __construct(
        private PlaidService $plaidService
    ) {}

    /**
     * Handle incoming Plaid webhooks.
     * 
     * Webhook types we handle:
     * - TRANSACTIONS: SYNC_UPDATES_AVAILABLE, DEFAULT_UPDATE, TRANSACTIONS_REMOVED, INITIAL_UPDATE, HISTORICAL_UPDATE
     * - ITEM: ERROR, PENDING_EXPIRATION, USER_PERMISSION_REVOKED
     */
    public function handle(Request $request)
    {
        $payload = $request->all();
        
        $webhookType = $payload['webhook_type'] ?? null;
        $webhookCode = $payload['webhook_code'] ?? null;
        $itemId = $payload['item_id'] ?? null;
        $environment = $payload['environment'] ?? null;
        
        // Log all incoming webhooks
        Log::channel('plaid_adds')->info('Plaid webhook received', [
            'webhook_type' => $webhookType,
            'webhook_code' => $webhookCode,
            'item_id' => $itemId,
            'environment' => $environment,
            'payload' => $payload,
        ]);
        
        // Ignore sandbox/development webhooks in production
        if (app()->environment('production') && $environment !== 'production') {
            Log::channel('plaid_skips')->info('Ignoring non-production webhook', [
                'environment' => $environment,
                'item_id' => $itemId,
            ]);
            return response()->json(['status' => 'ignored', 'reason' => 'non-production environment']);
        }
        
        // Find the bank by item_id
        $bank = Bank::withoutGlobalScopes()
            ->where('plaid_item_id', $itemId)
            ->first();
            
        if (!$bank) {
            Log::channel('plaid_skips')->warning('Plaid webhook for unknown item_id', [
                'item_id' => $itemId,
                'webhook_type' => $webhookType,
                'webhook_code' => $webhookCode,
            ]);
            return response()->json(['status' => 'ignored', 'reason' => 'unknown item_id']);
        }
        
        // Route to appropriate handler based on webhook type
        match ($webhookType) {
            'TRANSACTIONS' => $this->handleTransactionsWebhook($bank, $webhookCode, $payload),
            'ITEM' => $this->handleItemWebhook($bank, $webhookCode, $payload),
            default => Log::channel('plaid_skips')->info('Unhandled webhook type', [
                'webhook_type' => $webhookType,
                'webhook_code' => $webhookCode,
            ]),
        };
        
        return response()->json(['status' => 'received']);
    }
    
    /**
     * Handle TRANSACTIONS webhook events.
     */
    private function handleTransactionsWebhook(Bank $bank, string $webhookCode, array $payload): void
    {
        match ($webhookCode) {
            // Primary webhook for /transactions/sync - new transactions available
            'SYNC_UPDATES_AVAILABLE' => $this->dispatchBankSync($bank, $webhookCode, $payload),
            
            // Legacy webhook - still dispatch sync for backward compatibility
            'DEFAULT_UPDATE' => $this->dispatchBankSync($bank, $webhookCode, $payload),
            
            // Initial batch of transactions ready (after Link)
            'INITIAL_UPDATE' => $this->dispatchBankSync($bank, $webhookCode, $payload),
            
            // Historical transactions ready (30+ days)
            'HISTORICAL_UPDATE' => $this->dispatchBankSync($bank, $webhookCode, $payload),
            
            // Transactions were removed (reversed, deleted by institution)
            'TRANSACTIONS_REMOVED' => $this->dispatchBankSync($bank, $webhookCode, $payload),
            
            // Recurring transactions updated
            'RECURRING_TRANSACTIONS_UPDATE' => Log::channel('plaid_adds')->info('Recurring transactions updated', [
                'bank_id' => $bank->id,
            ]),
            
            default => Log::channel('plaid_skips')->info('Unhandled TRANSACTIONS webhook code', [
                'webhook_code' => $webhookCode,
                'bank_id' => $bank->id,
            ]),
        };
    }
    
    /**
     * Handle ITEM webhook events.
     */
    private function handleItemWebhook(Bank $bank, string $webhookCode, array $payload): void
    {
        match ($webhookCode) {
            // Item error - update bank status
            'ERROR' => $this->handleItemError($bank, $payload),
            
            // Access token expiring soon
            'PENDING_EXPIRATION' => $this->handlePendingExpiration($bank, $payload),
            
            // User revoked permission
            'USER_PERMISSION_REVOKED' => $this->handleUserPermissionRevoked($bank, $payload),
            
            // New accounts available
            'NEW_ACCOUNTS_AVAILABLE' => Log::channel('plaid_adds')->info('New accounts available', [
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
            ]),

            // Plaid acknowledges webhook URL updates
            'WEBHOOK_UPDATE_ACKNOWLEDGED' => Log::channel('plaid_adds')->info('Plaid webhook update acknowledged', [
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
            ]),
            
            default => Log::channel('plaid_skips')->info('Unhandled ITEM webhook code', [
                'webhook_code' => $webhookCode,
                'bank_id' => $bank->id,
            ]),
        };
    }
    
    /**
     * Dispatch sync job for a bank.
     */
    private function dispatchBankSync(Bank $bank, string $webhookCode, array $payload): void
    {
        // Check if bank is in error state - if so, don't sync
        if ($bank->plaid_options['error']['error_code'] ?? false) {
            Log::channel('plaid_skips')->info('Skipping sync for bank in error state', [
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
                'error_code' => $bank->plaid_options['error']['error_code'],
            ]);
            return;
        }
        
        // Check if vendor has registration date (active)
        if (!$bank->vendor?->registration_date) {
            Log::channel('plaid_skips')->info('Skipping sync for inactive vendor', [
                'bank_id' => $bank->id,
                'vendor_id' => $bank->vendor_id,
            ]);
            return;
        }
        
        Log::channel('plaid_adds')->info('Dispatching Plaid sync job', [
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'webhook_code' => $webhookCode,
            'initial_update_complete' => $payload['initial_update_complete'] ?? null,
            'historical_update_complete' => $payload['historical_update_complete'] ?? null,
        ]);
        
        // Dispatch job to process sync asynchronously
        ProcessPlaidTransactionSync::dispatch($bank, $webhookCode);
    }
    
    /**
     * Handle ITEM ERROR webhook.
     */
    private function handleItemError(Bank $bank, array $payload): void
    {
        $error = $payload['error'] ?? null;
        
        Log::channel('plaid_adds')->error('Plaid ITEM ERROR webhook', [
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'error' => $error,
        ]);
        
        // Update bank's plaid_options with the error
        if ($error) {
            $plaidOptions = $bank->plaid_options ?? [];
            $plaidOptions['error'] = $error;
            $bank->plaid_options = $plaidOptions;
            $bank->save();

            $this->notifyAdminsOfBankError(
                $bank,
                $error['error_code'] ?? 'UNKNOWN',
                $error['error_message'] ?? $error['display_message'] ?? 'An error occurred with this bank connection.',
            );
        }
    }
    
    /**
     * Handle PENDING_EXPIRATION webhook.
     */
    private function handlePendingExpiration(Bank $bank, array $payload): void
    {
        $consentExpirationTime = $payload['consent_expiration_time'] ?? null;
        
        Log::channel('plaid_adds')->warning('Plaid access token pending expiration', [
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'expiration_time' => $consentExpirationTime,
        ]);
        
        // Update bank's plaid_options with pending expiration info
        $plaidOptions = $bank->plaid_options ?? [];
        $plaidOptions['pending_expiration'] = [
            'notified_at' => now()->toIso8601String(),
            'consent_expiration_time' => $consentExpirationTime,
        ];
        $bank->plaid_options = $plaidOptions;
        $bank->save();
        
        // TODO: Optionally notify the vendor/user to re-authenticate
    }
    
    /**
     * Handle USER_PERMISSION_REVOKED webhook.
     */
    private function handleUserPermissionRevoked(Bank $bank, array $payload): void
    {
        Log::channel('plaid_adds')->warning('User permission revoked for Plaid item', [
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
        ]);
        
        // Update bank's plaid_options with revoked status
        $plaidOptions = $bank->plaid_options ?? [];
        $plaidOptions['error'] = [
            'error_type' => 'ITEM_ERROR',
            'error_code' => 'USER_PERMISSION_REVOKED',
            'error_message' => 'User revoked access to this bank connection',
        ];
        $bank->plaid_options = $plaidOptions;
        $bank->save();

        $this->notifyAdminsOfBankError(
            $bank,
            'USER_PERMISSION_REVOKED',
            'User revoked access to this bank connection.',
        );
    }

    /**
     * Send a BankErrorAlert email to all admin users of the bank's vendor.
     */
    private function notifyAdminsOfBankError(Bank $bank, string $errorCode, string $errorMessage): void
    {
        if (!$bank->vendor) {
            return;
        }

        $admins = $bank->vendor->users()
            ->wherePivot('role_id', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        foreach ($admins as $admin) {
            Mail::to($admin->email)->queue(new BankErrorAlert($bank, $errorCode, $errorMessage));
        }
    }

    /**
     * Fire a test webhook for a bank (sandbox only).
     * 
     * GET /plaid_webhooks/test/{bank}?type=TRANSACTIONS&code=SYNC_UPDATES_AVAILABLE
     */
    public function fireTestWebhook(Request $request, Bank $bank)
    {
        // Only allow in local/sandbox environment
        if (app()->environment('production')) {
            return response()->json([
                'error' => true,
                'message' => 'Test webhooks are only available in non-production environments',
            ], 403);
        }

        // Validate bank has Plaid access token
        if (!$bank->plaid_access_token) {
            return response()->json([
                'error' => true,
                'message' => 'Bank does not have a Plaid access token',
            ], 400);
        }

        $webhookType = $request->get('type', 'TRANSACTIONS');
        $webhookCode = $request->get('code', 'SYNC_UPDATES_AVAILABLE');

        Log::channel('plaid_adds')->info('Firing test webhook', [
            'bank_id' => $bank->id,
            'bank_name' => $bank->name,
            'webhook_type' => $webhookType,
            'webhook_code' => $webhookCode,
        ]);

        $result = $this->plaidService->fireWebhook(
            $bank->plaid_access_token,
            $webhookType,
            $webhookCode
        );

        return response()->json([
            'success' => $result['webhook_fired'] ?? false,
            'bank' => [
                'id' => $bank->id,
                'name' => $bank->name,
            ],
            'webhook_type' => $webhookType,
            'webhook_code' => $webhookCode,
            'plaid_response' => $result,
        ]);
    }

    /**
     * List all banks available for webhook testing (sandbox only).
     * 
     * GET /plaid_webhooks/test
     */
    public function listTestBanks()
    {
        // Only allow in local/sandbox environment
        if (app()->environment('production')) {
            return response()->json([
                'error' => true,
                'message' => 'Test webhooks are only available in non-production environments',
            ], 403);
        }

        $banks = Bank::withoutGlobalScopes()
            ->whereNotNull('plaid_access_token')
            ->select(['id', 'name', 'plaid_item_id', 'vendor_id'])
            ->with('vendor:id,name')
            ->get();

        return response()->json([
            'banks' => $banks,
            'webhook_codes' => [
                'TRANSACTIONS' => [
                    'SYNC_UPDATES_AVAILABLE',
                    'DEFAULT_UPDATE',
                    'INITIAL_UPDATE',
                    'HISTORICAL_UPDATE',
                    'TRANSACTIONS_REMOVED',
                ],
                'ITEM' => [
                    'ERROR',
                    'PENDING_EXPIRATION',
                    'USER_PERMISSION_REVOKED',
                ],
            ],
            'usage' => 'GET /plaid_webhooks/test/{bank_id}?type=TRANSACTIONS&code=SYNC_UPDATES_AVAILABLE',
        ]);
    }
}
