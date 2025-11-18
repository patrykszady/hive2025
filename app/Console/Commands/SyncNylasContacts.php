<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NylasContactSyncService;
use Illuminate\Console\Command;

class SyncNylasContacts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nylas:sync-contacts {--user_id=} {--recreate : Delete and recreate all contacts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync client users to Nylas contacts for all vendor grants';

    protected NylasContactSyncService $contactSyncService;

    public function __construct(NylasContactSyncService $contactSyncService)
    {
        parent::__construct();
        $this->contactSyncService = $contactSyncService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->option('user_id');
        $recreate = $this->option('recreate');

        if ($userId) {
            // Sync specific user
            $user = User::find($userId);
            if (!$user) {
                $this->error("User with ID {$userId} not found");
                return 1;
            }

            $this->info("Syncing contacts for user: {$user->full_name}");
            
            if ($recreate) {
                $this->info("Recreating contacts (deleting and creating fresh)...");
                $this->contactSyncService->recreateContactsForUser($user);
            } else {
                $this->contactSyncService->updateContactsForUser($user);
            }
            
            $this->info("Sync complete for user {$user->id}");
        } else {
            // Sync all users with clients
            $this->info('Syncing Nylas contacts for recently updated users...');
            
            if ($recreate) {
                $this->warn('⚠️  This will DELETE and RECREATE all contacts!');
                if (!$this->confirm('Are you sure you want to continue?')) {
                    $this->info('Aborted.');
                    return 0;
                }
                
                // When recreating, process all users
                $users = User::has('clients')->get();
            } else {
                // Only sync users updated in the last 25 hours (to account for schedule timing)
                $users = User::has('clients')
                    ->where('updated_at', '>=', now()->subHours(25))
                    ->get();
            }
            
            if ($users->isEmpty()) {
                $this->info('No users need syncing.');
                return 0;
            }
            
            $bar = $this->output->createProgressBar($users->count());
            $bar->start();

            foreach ($users as $user) {
                if ($recreate) {
                    $this->contactSyncService->recreateContactsForUser($user);
                } else {
                    $this->contactSyncService->updateContactsForUser($user);
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info("Synced {$users->count()} users to Nylas contacts");
        }

        return 0;
    }
}
