<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NylasContactSyncService
{
    protected NylasService $nylasService;

    public function __construct(NylasService $nylasService)
    {
        $this->nylasService = $nylasService;
    }

    /**
     * Sync a user's contact to all relevant Nylas grants
     * This happens when a user is attached to a client
     * 
     * @param User $user
     * @param Client $client
     * @return void
     */
    public function syncUserContactsForClient(User $user, Client $client): void
    {
        Log::channel('nylas')->info('Starting contact sync for client', [
            'user_id' => $user->id,
            'client_id' => $client->id,
        ]);
        
        // Get all Hive Contractor vendors that have this client
        $vendors = $client->vendors()->hiveVendors()->get();
        
        Log::channel('nylas')->info('Found vendors for client', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'vendor_count' => $vendors->count(),
        ]);
        
        if ($vendors->isEmpty()) {
            Log::channel('nylas')->warning('No Hive vendors found for client', [
                'user_id' => $user->id,
                'client_id' => $client->id,
            ]);
            return;
        }
        
        foreach ($vendors as $vendor) {
            $this->syncUserContactForVendor($user, $client, $vendor);
        }
    }

    /**
     * Sync a user's contact for a specific vendor's grant
     * 
     * @param User $user
     * @param Client $client
     * @param Vendor $vendor
     * @return void
     */
    public function syncUserContactForVendor(User $user, Client $client, Vendor $vendor): void
    {
        Log::channel('nylas')->info('Syncing contact for vendor', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'vendor_id' => $vendor->id,
        ]);
        
        // Get the vendor's company emails (grants)
        $companyEmails = $vendor->company_emails;
        
        Log::channel('nylas')->info('Found company emails for vendor', [
            'user_id' => $user->id,
            'vendor_id' => $vendor->id,
            'email_count' => $companyEmails->count(),
        ]);
        
        if ($companyEmails->isEmpty()) {
            Log::channel('nylas')->warning('No company emails found for vendor', [
                'user_id' => $user->id,
                'vendor_id' => $vendor->id,
            ]);
            return;
        }
        
        foreach ($companyEmails as $companyEmail) {
            $this->syncUserContactForGrant($user, $client, $companyEmail);
        }
    }

    /**
     * Sync a user contact for a specific grant
     * 
     * @param User $user
     * @param Client $client
     * @param \App\Models\CompanyEmail $companyEmail
     * @return void
     */
    public function syncUserContactForGrant(User $user, Client $client, $companyEmail): void
    {
        $grantId = $companyEmail->grant_id;
        
        Log::channel('nylas')->info('Starting contact sync for grant', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'grant_id' => $grantId,
        ]);
        
        try {
            // Get the pivot record to check for existing Nylas contact ID
            $pivot = DB::table('client_user')
                ->where('client_id', $client->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$pivot) {
                Log::channel('nylas')->warning('No client_user pivot found', [
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                    'grant_id' => $grantId,
                ]);
                return;
            }

            // Get existing contact IDs (JSON)
            $existingContactIds = json_decode($pivot->nylas_contact_ids ?? '{}', true) ?? [];
            $existingContactId = $existingContactIds[$grantId] ?? null;

            Log::channel('nylas')->info('Checked for existing contact', [
                'user_id' => $user->id,
                'grant_id' => $grantId,
                'existing_contact_id' => $existingContactId,
            ]);

            // Prepare contact data
            $contactData = $this->prepareContactData($user, $client, $companyEmail);

            if ($existingContactId) {
                // Verify contact exists in Nylas before updating
                $contactExists = $this->nylasService->getContact($grantId, $existingContactId);
                
                if ($contactExists['exists']) {
                    // Update existing contact
                    $result = $this->nylasService->updateContact($grantId, $existingContactId, $contactData);
                    
                    if ($result['status'] === 200) {
                        Log::channel('nylas')->info('Updated Nylas contact', [
                            'grant_id' => $grantId,
                            'contact_id' => $existingContactId,
                            'user_id' => $user->id,
                        ]);
                    } else {
                        Log::channel('nylas')->warning('Failed to update Nylas contact', [
                            'grant_id' => $grantId,
                            'contact_id' => $existingContactId,
                            'user_id' => $user->id,
                            'status' => $result['status'],
                            'response' => $result['data'] ?? null,
                        ]);
                    }
                } else {
                    // Contact ID exists in DB but not in Nylas - recreate it
                    Log::channel('nylas')->warning('Contact ID exists locally but not in Nylas, recreating', [
                        'grant_id' => $grantId,
                        'contact_id' => $existingContactId,
                        'user_id' => $user->id,
                    ]);
                    
                    // Create new contact
                    $result = $this->nylasService->createContact($grantId, $contactData);
                    
                    if ($result['status'] === 200 && isset($result['data']['data']['id'])) {
                        $newContactId = $result['data']['data']['id'];
                        
                        // Update the pivot table with the new contact ID
                        $existingContactIds[$grantId] = $newContactId;
                        
                        DB::table('client_user')
                            ->where('client_id', $client->id)
                            ->where('user_id', $user->id)
                            ->update([
                                'nylas_contact_ids' => json_encode($existingContactIds),
                                'updated_at' => now(),
                            ]);

                        Log::channel('nylas')->info('Recreated missing Nylas contact', [
                            'grant_id' => $grantId,
                            'old_contact_id' => $existingContactId,
                            'new_contact_id' => $newContactId,
                            'user_id' => $user->id,
                        ]);
                    } else {
                        Log::channel('nylas')->error('Failed to recreate missing Nylas contact', [
                            'grant_id' => $grantId,
                            'old_contact_id' => $existingContactId,
                            'user_id' => $user->id,
                            'status' => $result['status'] ?? null,
                            'response' => $result['data'] ?? null,
                        ]);
                    }
                }
            } else {
                // Create new contact
                $result = $this->nylasService->createContact($grantId, $contactData);
                
                if ($result['status'] === 200 && isset($result['data']['data']['id'])) {
                    $newContactId = $result['data']['data']['id'];
                    
                    // Update the pivot table with the new contact ID
                    $existingContactIds[$grantId] = $newContactId;
                    
                    DB::table('client_user')
                        ->where('client_id', $client->id)
                        ->where('user_id', $user->id)
                        ->update([
                            'nylas_contact_ids' => json_encode($existingContactIds),
                            'updated_at' => now(),
                        ]);

                    Log::channel('nylas')->info('Created Nylas contact', [
                        'grant_id' => $grantId,
                        'contact_id' => $newContactId,
                        'user_id' => $user->id,
                    ]);
                } else {
                    Log::channel('nylas')->warning('Failed to create Nylas contact', [
                        'grant_id' => $grantId,
                        'user_id' => $user->id,
                        'status' => $result['status'] ?? null,
                        'response' => $result['data'] ?? null,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::channel('nylas')->error('Failed to sync Nylas contact', [
                'grant_id' => $grantId,
                'user_id' => $user->id,
                'client_id' => $client->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Update contacts for a user across all clients when user data changes
     * 
     * @param User $user
     * @return void
     */
    public function updateContactsForUser(User $user): void
    {
        $clients = $user->clients;
        
        foreach ($clients as $client) {
            $this->syncUserContactsForClient($user, $client);
        }
    }

    /**
     * Recreate contacts for a user (delete and create fresh)
     * Useful when moving contacts to correct groups
     * 
     * @param User $user
     * @return void
     */
    public function recreateContactsForUser(User $user): void
    {
        $clients = $user->clients;
        
        foreach ($clients as $client) {
            // First, remove all existing contacts
            $this->removeUserContactsForClient($user, $client);
            
            // Clear the nylas_contact_ids from the pivot table
            DB::table('client_user')
                ->where('client_id', $client->id)
                ->where('user_id', $user->id)
                ->update([
                    'nylas_contact_ids' => null,
                    'updated_at' => now(),
                ]);
            
            // Then create fresh contacts
            $this->syncUserContactsForClient($user, $client);
        }
    }

    /**
     * Prepare contact data for Nylas API
     * 
     * @param User $user
     * @param Client $client
     * @param \App\Models\CompanyEmail $companyEmail
     * @return array
     */
    protected function prepareContactData(User $user, Client $client, $companyEmail): array
    {
        $data = [
            'given_name' => $user->first_name,
            'surname' => $user->last_name,
        ];

        // Add email if present
        if ($user->email) {
            $data['emails'] = [
                [
                    'type' => 'home',
                    'email' => $user->email,
                ]
            ];
        }

        // Add phone if present
        if ($user->cell_phone) {
            $data['phone_numbers'] = [
                [
                    'type' => 'mobile',
                    'number' => $user->cell_phone,
                ]
            ];
        }

        // Add company name from client if available
        if ($client->business_name) {
            $data['company_name'] = $client->business_name;
        }

        // Add physical address if client has one
        if ($client->address) {
            $data['physical_addresses'] = [
                [
                    'type' => 'home',
                    'street_address' => $client->address . ($client->address_2 ? "\n" . $client->address_2 : ''),
                    'city' => $client->city,
                    'state' => $client->state,
                    'postal_code' => (string) $client->zip_code,
                    'country' => 'US',
                ]
            ];
        }

        return $data;
    }

    /**
     * Remove contact from Nylas when user is detached from client
     * 
     * @param User $user
     * @param Client $client
     * @return void
     */
    public function removeUserContactsForClient(User $user, Client $client): void
    {
        try {
            // Get the pivot record
            $pivot = DB::table('client_user')
                ->where('client_id', $client->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$pivot || !$pivot->nylas_contact_ids) {
                return;
            }

            $contactIds = json_decode($pivot->nylas_contact_ids, true) ?? [];

            // Delete all contacts for this user from all grants
            foreach ($contactIds as $grantId => $contactId) {
                $this->nylasService->deleteContact($grantId, $contactId);
                
                Log::channel('nylas')->info('Deleted Nylas contact', [
                    'grant_id' => $grantId,
                    'contact_id' => $contactId,
                    'user_id' => $user->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('nylas')->error('Failed to remove Nylas contacts', [
                'user_id' => $user->id,
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
