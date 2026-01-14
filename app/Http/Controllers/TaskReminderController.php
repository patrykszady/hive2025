<?php

namespace App\Http\Controllers;

use App\Models\SmsLog;
use App\Models\User;
use App\Models\Task;
use App\Notifications\TeamTaskSmsNotification;
use App\Services\SmsScheduleService;
use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use App\Support\ApiErrorFormatter;
use Illuminate\Support\Facades\Redis;

class TaskReminderController extends Controller
{
    protected SmsScheduleService $smsService;

    public function __construct()
    {
        $this->smsService = app(SmsScheduleService::class);
    }

    /**
     * Notify users about changes to their tasks for the current day
     * With a configurable delay that resets with each change
     */
    public function notifyTodayTaskChanges($task, $originalUserIds, $newUserIds, $originalStartDate = null, $originalEndDate = null)
    {
        $today = Carbon::today();
        
        // Task date checks
        $taskStartDate = Carbon::parse($task->start_date);
        $taskEndDate = Carbon::parse($task->end_date);
        $taskIncludesToday = $taskStartDate->lte($today) && $taskEndDate->gte($today);
        
        $originalTaskIncludesToday = false;
        if ($originalStartDate && $originalEndDate) {
            $originalStartCarbon = Carbon::parse($originalStartDate);
            $originalEndCarbon = Carbon::parse($originalEndDate);
            $originalTaskIncludesToday = $originalStartCarbon->lte($today) && $originalEndCarbon->gte($today);
        }
        
        // Weekend check
        if ($this->shouldSkipWeekendTask($task, $today)) {
            return ['status' => 'skipped', 'reason' => 'weekend_excluded'];
        }
        
        // User calculations
        $removedUserIds = array_diff($originalUserIds, $newUserIds);
        $addedUserIds = array_diff($newUserIds, $originalUserIds);
        
        $stats = [
            'notifications_queued' => 0,
            'errors' => 0
        ];
        
        // Check if we're within business hours
        $withinBusinessHours = $this->smsService->isWithinBusinessHours();
        
        // Determine which users are affected by this change
        $affectedUserIds = array_unique(array_merge($removedUserIds, $addedUserIds));
        
        // Special case for date changes that don't affect user assignments
        if (!$affectedUserIds && $originalTaskIncludesToday != $taskIncludesToday) {
            if ($originalTaskIncludesToday) {
                $affectedUserIds = $originalUserIds;
            } else {
                $affectedUserIds = $newUserIds;
            }
        }
        
        foreach ($affectedUserIds as $userId) {
            $user = User::find($userId);
            if (!$user || !$user->cell_phone) {
                continue;
            }
            
            // Prepare notification data
            $notificationData = $this->prepareUserNotificationData(
                $userId, 
                $today, 
                $task, 
                $removedUserIds, 
                $originalTaskIncludesToday, 
                $taskIncludesToday, 
                $originalUserIds
            );
            
            if (!$notificationData) {
                continue;
            }
            
            try {
                // Queue notification based on business hours
                if ($withinBusinessHours) {
                    $this->queueDelayedNotification($userId, $notificationData, $today, $task->id);
                    $stats['notifications_queued']++;
                } else {
                    $this->queueMorningNotification($userId, $notificationData, $today, $task->id);
                    $stats['notifications_queued']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->smsService->getLogger('team')->error('Failed to queue task update', ApiErrorFormatter::format($e, [
                    'user_id' => $userId,
                ]));
            }
        }
        
        return $stats;
    }
    
    /**
     * Process delayed notifications that have passed their waiting period
     * To be run by a scheduled command every minute
     */
    public function processDelayedNotifications()
    {
        $today = Carbon::today();
        $delayedKey = 'delayed_task_updates:' . $today->format('Y-m-d');
        $currentTime = now()->timestamp;
        
        $stats = [
            'notifications_sent' => 0,
            'still_waiting' => 0,
            'errors' => 0
        ];
        
        // Get all pending delayed updates from Redis
        $allPendingUpdates = Redis::hgetall($delayedKey);
        
        foreach ($allPendingUpdates as $userId => $updateJson) {
            // Check if the timer for this user has expired
            $timerKey = "task_notification_timer:{$userId}:{$today->format('Y-m-d')}";
            $sendAfter = Redis::get($timerKey);
            
            if (!$sendAfter || $currentTime < (int)$sendAfter) {
                // Timer not expired yet
                $stats['still_waiting']++;
                continue;
            }
            
            // Process this notification
            $result = $this->processNotification($userId, $updateJson, $delayedKey, $today, ['timerKey' => $timerKey]);
            
            // Update stats
            $stats['notifications_sent'] += $result['sent'] ? 1 : 0;
            $stats['errors'] += $result['error'] ? 1 : 0;
        }
        
        return $stats;
    }
    
    /**
     * Send all pending task updates queued for morning
     * To be run by a scheduled command at business hours start
     */
    public function sendPendingTaskUpdates()
    {
        $today = Carbon::today();
        $morningKey = 'pending_task_updates:' . $today->format('Y-m-d');
        
        $stats = [
            'notifications_sent' => 0,
            'errors' => 0
        ];
        
        // Get all pending updates from Redis
        $allPendingUpdates = Redis::hgetall($morningKey);
        
        foreach ($allPendingUpdates as $userId => $updateJson) {
            // Process this notification
            $result = $this->processNotification($userId, $updateJson, $morningKey, $today);
            
            // Update stats
            $stats['notifications_sent'] += $result['sent'] ? 1 : 0;
            $stats['errors'] += $result['error'] ? 1 : 0;
        }
        
        return $stats;
    }
    
    /**
     * Prepare notification data for a user
     */
    private function prepareUserNotificationData($userId, $today, $task, $removedUserIds, $originalTaskIncludesToday, $taskIncludesToday, $originalUserIds)
    {
        // Get user's current tasks for today
        $currentTasks = Task::whereJsonContains('user_ids', $userId)
            ->where(function ($query) use ($today) {
                $query->whereDate('start_date', '<=', $today)
                      ->whereDate('end_date', '>=', $today);
            })
            ->with(['project.client'])
            ->get();
        
        // Determine removed tasks
        $removedTasks = [];
        
        if (in_array($userId, $removedUserIds) && $taskIncludesToday) {
            $removedTasks[] = $task;
        }
        
        if ($originalTaskIncludesToday && !$taskIncludesToday && in_array($userId, $originalUserIds)) {
            $removedTasks[] = $task;
        }
        
        return [
            'current_task_ids' => $currentTasks->pluck('id')->toArray(),
            'removed_task_ids' => collect($removedTasks)->pluck('id')->toArray(),
            'timestamp' => now()->timestamp
        ];
    }
    
    /**
     * Queue a notification for delayed delivery
     */
    private function queueDelayedNotification($userId, $notificationData, $today, $taskId)
    {
        $delayedKey = 'delayed_task_updates:' . $today->format('Y-m-d');
        
        // Store notification data
        Redis::hset($delayedKey, $userId, json_encode($notificationData));
        
        // Set expiration timer using config delay
        $timerKey = "task_notification_timer:{$userId}:{$today->format('Y-m-d')}";
        $delayMinutes = $this->smsService->getChangeDelayMinutes();
        
        Redis::set($timerKey, now()->addMinutes($delayMinutes)->timestamp);
    }
    
    /**
     * Queue a notification for morning delivery
     */
    private function queueMorningNotification($userId, $notificationData, $today, $taskId)
    {
        $morningKey = 'pending_task_updates:' . $today->format('Y-m-d');
        
        // Store notification data
        Redis::hset($morningKey, $userId, json_encode($notificationData));
    }
    
    /**
     * Process a single notification
     */
    private function processNotification($userId, $updateJson, $redisKey, $today, $options = [])
    {
        $result = [
            'sent' => false,
            'error' => false
        ];
        
        try {
            $update = json_decode($updateJson, true);
            $user = User::find($userId);
            
            if (!$user || !$user->cell_phone) {
                Redis::hdel($redisKey, $userId);
                
                // Also delete timer key if provided
                if (isset($options['timerKey'])) {
                    Redis::del($options['timerKey']);
                }
                
                return $result;
            }
            
            // Get current tasks from IDs
            $currentTasks = Task::whereIn('id', $update['current_task_ids'])
                ->with(['project.client'])
                ->get();
            
            // Get removed tasks from IDs, including soft-deleted ones
            $removedTasks = Task::withTrashed()->whereIn('id', $update['removed_task_ids'])
                ->with(['project.client'])
                ->get();
            
            // Send notification (dev/local are forced to the Twilio dev number)
            if (app()->environment(['production', 'local', 'development'])) {
                $user->notify(new TeamTaskSmsNotification($currentTasks, $today, 'update', $removedTasks));
                
                // Log the send for each task
                foreach ($currentTasks as $task) {
                    SmsLog::logSent([
                        'channel' => SmsLog::CHANNEL_TEAM,
                        'type' => SmsLog::TYPE_UPDATE,
                        'user_id' => $user->id,
                        'project_id' => $task->project_id,
                        'task_id' => $task->id,
                        'target_date' => $today->format('Y-m-d'),
                    ]);
                }
            }
            
            // Remove from Redis
            Redis::hdel($redisKey, $userId);
            
            // Also delete timer key if provided
            if (isset($options['timerKey'])) {
                Redis::del($options['timerKey']);
            }
            
            $result['sent'] = true;
        } catch (\Exception $e) {
            $result['error'] = true;
            $this->smsService->getLogger('team')->error('Failed to process notification', ApiErrorFormatter::format($e, [
                'user_id' => $userId,
            ]));
        }
        
        return $result;
    }
    
    /**
     * Send SMS reminders for tasks happening tomorrow
     */
    public function sendTomorrowReminders()
    {
        $tomorrow = Carbon::tomorrow();

        // More efficient query with our updated implementation
        $tasks = Task::where(function ($query) use ($tomorrow) {
            $query->whereDate('start_date', '<=', $tomorrow)
                  ->whereDate('end_date', '>=', $tomorrow);
        })
        ->whereNotNull('user_ids')
        ->whereRaw('JSON_LENGTH(user_ids) > 0')  // Ensure there are actually user IDs
        ->with(['project.client'])
        ->get();

        $userTasks = [];

        // Group tasks by user
        foreach ($tasks as $task) {
            // Check if tomorrow is a weekend day and if it's excluded in options
            if ($this->shouldSkipWeekendTask($task, $tomorrow)) {
                continue;
            }

            foreach ($task->users as $user) {
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
                // Check if already sent for tomorrow
                $tomorrowStr = $tomorrow->format('Y-m-d');
                if (SmsLog::wasAlreadySent(SmsLog::CHANNEL_TEAM, SmsLog::TYPE_REMINDER, $user->id, $tomorrowStr)) {
                    continue;
                }
                
                // Dev/local are forced to the Twilio dev number
                if (app()->environment(['production', 'local', 'development'])) {
                    $user->notify(new TeamTaskSmsNotification($userTaskList, $tomorrow, 'reminder'));
                    
                    // Log the send
                    SmsLog::logSent([
                        'channel' => SmsLog::CHANNEL_TEAM,
                        'type' => SmsLog::TYPE_REMINDER,
                        'user_id' => $user->id,
                        'target_date' => $tomorrowStr,
                    ]);
                }
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $this->smsService->getLogger('team')->error('Failed to queue task reminder notification', ApiErrorFormatter::format($e, [
                    'user_id' => $user->id,
                    'user_name' => $user->full_name,
                    'phone' => $user->cell_phone,
                ]));
            }
        }

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

    /**
     * Process all pending task notifications (both delayed and morning)
     * To be run by a scheduled command every minute
     */
    public function processTaskNotifications()
    {
        $today = Carbon::today();
        $currentTime = now()->timestamp;
        
        // Process delayed notifications (during business hours)
        $delayedKey = 'delayed_task_updates:' . $today->format('Y-m-d');
        $morningKey = 'pending_task_updates:' . $today->format('Y-m-d');
        
        $stats = [
            'notifications_sent' => 0,
            'still_waiting' => 0,
            'errors' => 0
        ];
        
        // 1. Process delayed notifications first
        $allDelayedUpdates = Redis::hgetall($delayedKey);
        
        foreach ($allDelayedUpdates as $userId => $updateJson) {
            // Check if the timer for this user has expired
            $timerKey = "task_notification_timer:{$userId}:{$today->format('Y-m-d')}";
            $sendAfter = Redis::get($timerKey);
            
            if (!$sendAfter || $currentTime < (int)$sendAfter) {
                // Timer not expired yet
                $stats['still_waiting']++;
                continue;
            }
            
            // Process this notification
            $result = $this->processNotification($userId, $updateJson, $delayedKey, $today, ['timerKey' => $timerKey]);
            
            // Update stats
            $stats['notifications_sent'] += $result['sent'] ? 1 : 0;
            $stats['errors'] += $result['error'] ? 1 : 0;
        }
        
        // 2. Process morning notifications if within business hours
        if ($this->smsService->isWithinBusinessHours()) {
            $allMorningUpdates = Redis::hgetall($morningKey);
            
            foreach ($allMorningUpdates as $userId => $updateJson) {
                // Process this notification
                $result = $this->processNotification($userId, $updateJson, $morningKey, $today);
                
                // Update stats
                $stats['notifications_sent'] += $result['sent'] ? 1 : 0;
                $stats['errors'] += $result['error'] ? 1 : 0;
            }
        }
        
        return $stats;
    }
}
