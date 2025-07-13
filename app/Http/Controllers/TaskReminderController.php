<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Notifications\TaskReminderNotification;
use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class TaskReminderController extends Controller
{
    /**
     * Send SMS reminders for tasks happening tomorrow
     */
    public function sendTomorrowReminders()
    {
        $tomorrow = Carbon::tomorrow();

        // Get all tasks that have tomorrow as one of their days
        $tasks = Task::where(function ($query) use ($tomorrow) {
            $query->whereDate('start_date', '<=', $tomorrow)
                  ->whereDate('end_date', '>=', $tomorrow);
        })
        ->whereNotNull('user_ids')
        ->with(['project.client'])
        ->get();

        $userTasks = [];

        // Group tasks by user
        foreach ($tasks as $task) {
            // Skip if no project or no address
            if (!$task->project || !$task->project->address) {
                continue;
            }

            // Check if tomorrow is a weekend day and if it's excluded in options
            if ($this->shouldSkipWeekendTask($task, $tomorrow)) {
                continue;
            }

            // Use the users attribute (which calls getUsersAttribute)
            $taskUsers = $task->users;

            foreach ($taskUsers as $user) {
                if (!$user->cell_phone) {
                    continue;
                }

                if (!isset($userTasks[$user->id])) {
                    $userTasks[$user->id] = [
                        'user' => $user,
                        'tasks' => []
                    ];
                }

                $userTasks[$user->id]['tasks'][] = $task;
            }
        }

        $successCount = 0;
        $errorCount = 0;

        // Send notifications
        foreach ($userTasks as $userData) {
            $user = $userData['user'];
            $userTaskList = $userData['tasks'];

            try {
                $user->notify(new TaskReminderNotification($userTaskList, $tomorrow));
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::channel('task_reminder')->error("Failed to queue task reminder notification", [
                    'user_id' => $user->id,
                    'user_name' => $user->full_name,
                    'phone' => $user->cell_phone,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::channel('task_reminder')->info("Daily task reminder summary", [
            'date' => $tomorrow->format('Y-m-d'),
            'total_tasks' => $tasks->count(),
            'unique_users' => count($userTasks),
            'notifications_queued' => $successCount,
            'errors' => $errorCount
        ]);

        return [
            'total_tasks' => $tasks->count(),
            'unique_users' => count($userTasks),
            'notifications_queued' => $successCount,
            'errors' => $errorCount
        ];
    }

    /**
     * Check if a task should be skipped for weekend days based on options
     */
    private function shouldSkipWeekendTask($task, $date)
    {
        // Check if the date is a weekend
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 6 = Saturday

        if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
            return false; // Not a weekend, don't skip
        }

        // Get the options
        $options = $task->options;

        // If no options, skip weekend tasks by default
        if (!$options) {
            return true;
        }

        // Check for Saturday
        if ($dayOfWeek === 6) { // Saturday
            return !isset($options->saturday) || $options->saturday !== true;
        }

        // Check for Sunday
        if ($dayOfWeek === 0) { // Sunday
            return !isset($options->sunday) || $options->sunday !== true;
        }

        return false;
    }
}
