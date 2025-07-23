<?php

namespace App\Console\Commands;

use App\Http\Controllers\TaskReminderController;
use Illuminate\Console\Command;

class ProcessTaskNotifications extends Command
{
    protected $signature = 'tasks:process-notifications';
    protected $description = 'Process all pending task notifications that are due to be sent';

    public function handle()
    {
        $controller = new TaskReminderController();
        $stats = $controller->processTaskNotifications();
        
        $this->info("Processed task notifications");
        $this->info("Sent: {$stats['notifications_sent']}");
        $this->info("Still waiting: {$stats['still_waiting']}");
        
        if ($stats['errors'] > 0) {
            $this->warn("Errors encountered: {$stats['errors']}");
        }
        
        return Command::SUCCESS;
    }
}