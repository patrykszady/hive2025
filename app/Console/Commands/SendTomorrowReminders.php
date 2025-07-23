<?php

namespace App\Console\Commands;

use App\Http\Controllers\TaskReminderController;
use Illuminate\Console\Command;

class SendTomorrowReminders extends Command
{
    protected $signature = 'tasks:send-tomorrow-reminders';
    protected $description = 'Send reminders for tasks scheduled tomorrow';

    public function handle()
    {
        $controller = new TaskReminderController();
        $stats = $controller->sendTomorrowReminders();
        
        $this->info("Task reminders sent for tomorrow");
        $this->info("Total tasks: {$stats['total_tasks']}");
        $this->info("Users notified: {$stats['notifications_queued']}");
        
        if ($stats['errors'] > 0) {
            $this->warn("Errors encountered: {$stats['errors']}");
        }
        
        return Command::SUCCESS;
    }
}