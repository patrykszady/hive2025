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
    protected $signature = 'nylas:sync-contacts {--user_id=}';

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

        if ($userId) {
            // Sync specific user
            $user = User::find($userId);
            if (!$user) {
                $this->error("User with ID {$userId} not found");
                return 1;
            }

            $this->info("Syncing contacts for user: {$user->full_name}");
            $this->contactSyncService->updateContactsForUser($user);
            $this->info("Sync complete for user {$user->id}");
        } else {
            // Sync all users with clients
            $this->info('Syncing all client users to Nylas contacts...');
            
            $users = User::has('clients')->get();
            $bar = $this->output->createProgressBar($users->count());
            $bar->start();

            foreach ($users as $user) {
                $this->contactSyncService->updateContactsForUser($user);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info("Synced {$users->count()} users to Nylas contacts");
        }

        return 0;
    }
}
