<?php

namespace App\Observers;

use App\Jobs\SendBatchVendorAvailabilitySms;
use App\Jobs\SendPendingTaskReminderToClients;
use App\Jobs\SendRealtimeTaskNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use App\Services\SmsScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        // Queue realtime notifications (client + team) if task includes today
        $this->queueNotificationIfNeeded($task);

        // Queue vendor availability SMS if vendor is assigned
        $this->queueVendorNotificationIfNeeded($task);

        // Send task reminder email to unregistered client users (15-min delay for batching)
        SendPendingTaskReminderToClients::dispatch($task->project_id)
            ->delay(now()->addMinutes(15));
    }

    public function creating(Task $task): void
    {
        $project = Project::findOrFail($task->project_id);

        $task->belongs_to_vendor_id = auth()->user()->vendor->id == $project->belongs_to_vendor_id ? auth()->user()->vendor->id : $project->belongs_to_vendor_id;
        $task->created_by_user_id = auth()->user()->id;

        // Handle options - set to null if empty
        if (is_array($task->options) && empty($task->options)) {
            $task->options = null;
        }
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        // Get the original (before update) and current (after update) values
        $originalUserIds = $task->getOriginal('user_ids') ?? [];
        $newUserIds = $task->user_ids ?? [];

        // Queue realtime notifications (client + team) if task includes today
        $this->queueNotificationIfNeeded($task, $originalUserIds, $newUserIds);

        // Queue vendor availability SMS if vendor was just assigned, dates changed,
        // or vendor is assigned but status was never set (legacy tasks)
        $originalVendorId = $task->getOriginal('vendor_id');
        $newVendorId = $task->vendor_id;
        $vendorChanged = $originalVendorId != $newVendorId && $newVendorId !== null;
        
        // Check if dates changed (start_date, end_date, or options->dates)
        $originalOptions = $task->getOriginal('options');
        $originalOptionsDates = is_object($originalOptions) ? ($originalOptions->dates ?? []) : (is_array($originalOptions) ? ($originalOptions['dates'] ?? []) : []);
        $newOptionsDates = is_object($task->options) ? ($task->options->dates ?? []) : (is_array($task->options) ? ($task->options['dates'] ?? []) : []);
        
        $datesChanged = $task->getOriginal('start_date') != $task->start_date 
            || $task->getOriginal('end_date') != $task->end_date
            || $originalOptionsDates != $newOptionsDates;
        $needsStatusSet = $task->vendor_id && $task->vendor_status === null;

        // But only if the change came from the dashboard (authenticated user), not from the vendor's public page
        $isFromDashboard = auth()->check();

        // If vendor changed, reset status to require confirmation from the new vendor
        if ($vendorChanged && $isFromDashboard) {
            $log = Log::channel('vendor_sms');
            $log->info("TaskObserver: Vendor changed from dashboard, resetting vendor status for new vendor confirmation", [
                'task_id' => $task->id,
                'old_vendor_id' => $originalVendorId,
                'new_vendor_id' => $newVendorId,
                'old_status' => $task->vendor_status,
                'changed_by_user_id' => auth()->id(),
            ]);
            
            // Reset status and clear token so new SMS will be sent to the new vendor
            $task->updateQuietly([
                'vendor_status' => null,
                'vendor_status_token' => null,
            ]);
            $task->refresh();
            $needsStatusSet = true;
        }

        // If dates changed and task has a vendor with a response, reset status to require re-confirmation
        
        if ($datesChanged && $task->vendor_id && $isFromDashboard && in_array($task->vendor_status, [Task::VENDOR_STATUS_CONFIRMED, Task::VENDOR_STATUS_REJECTED], true)) {
            $log = Log::channel('vendor_sms');
            $log->info("TaskObserver: Dates changed from dashboard, resetting vendor status for re-confirmation", [
                'task_id' => $task->id,
                'vendor_id' => $task->vendor_id,
                'old_status' => $task->vendor_status,
                'changed_by_user_id' => auth()->id(),
            ]);
            
            // Reset status and clear token so new SMS will be sent
            $task->updateQuietly([
                'vendor_status' => null,
                'vendor_status_token' => null,
            ]);
            $task->refresh();
            $needsStatusSet = true;
        }

        if ($vendorChanged || ($datesChanged && $isFromDashboard) || $needsStatusSet) {
            $this->queueVendorNotificationIfNeeded($task);
        }
    }

    public function updating(Task $task): void
    {
        // Handle options - set to null if empty
        if (is_array($task->options) && empty($task->options)) {
            $task->options = null;
        }
    }

    /**
     * Handle the Task "deleted" event.
     */
    public function deleted(Task $task): void
    {
        // Queue realtime notifications for affected users
        $this->queueNotificationIfNeeded($task, $task->user_ids ?? [], []);
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        // Queue realtime notifications for restored task users
        $this->queueNotificationIfNeeded($task);
    }

    /**
     * Handle the Task "force deleted" event.
     */
    public function forceDeleted(Task $task): void
    {
        // Queue realtime notifications for affected users
        $this->queueNotificationIfNeeded($task, $task->user_ids ?? [], []);
    }

    /**
     * Queue vendor availability SMS notification if conditions are met.
     * The job will be dispatched with a 1 hour delay to allow for task edits.
     * ShouldBeUnique ensures only one job per vendor, consolidating multiple tasks
     * assigned to the same vendor within the hour into a single SMS.
     */
    private function queueVendorNotificationIfNeeded(Task $task): void
    {
        $log = Log::channel('vendor_sms');

        // Must have a vendor assigned
        if (!$task->vendor_id) {
            return;
        }

        $logContext = [
            'task_id' => $task->id,
            'vendor_id' => $task->vendor_id,
            'vendor_status' => $task->vendor_status,
            'vendor_status_token' => $task->vendor_status_token,
            'start_date' => $task->start_date?->toDateString(),
        ];

        // Skip if already confirmed/rejected (but allow re-requesting if status was cleared)
        if (in_array($task->vendor_status, [Task::VENDOR_STATUS_CONFIRMED, Task::VENDOR_STATUS_REJECTED], true)) {
            $log->debug("TaskObserver: Skipping - vendor already confirmed/rejected", $logContext);
            return;
        }

        // Skip if already requested and SMS was sent (has token)
        if ($task->vendor_status === Task::VENDOR_STATUS_REQUESTED && $task->vendor_status_token !== null) {
            $log->debug("TaskObserver: Skipping - already requested with token", $logContext);
            return;
        }

        // Task should have dates set
        if (!$task->start_date) {
            $log->debug("TaskObserver: Skipping - no start date", $logContext);
            return;
        }

        // Task should be current or future (end_date >= today, or start_date >= today)
        $today = Carbon::today();
        $taskEndDate = $task->end_date ?? $task->start_date;
        if ($taskEndDate->lt($today)) {
            $log->debug("TaskObserver: Skipping - task has already ended", $logContext);
            return;
        }

        // Set vendor_status to 'requested' immediately so it shows in the UI
        // The token remains null until the SMS is actually sent
        if ($task->vendor_status !== Task::VENDOR_STATUS_REQUESTED) {
            $task->updateQuietly(['vendor_status' => Task::VENDOR_STATUS_REQUESTED]);
            $log->info("TaskObserver: Set vendor_status to 'requested'", $logContext);
        }

        // Dispatch the job with a 1 hour delay, keyed by vendor_id
        // Using ShouldBeUnique, if another task for the same vendor is created within the hour,
        // the new dispatch will be ignored. The original job will run after 1 hour and
        // find ALL eligible tasks for that vendor at that time.
        SendBatchVendorAvailabilitySms::dispatch($task->vendor_id)
            ->delay(now()->addHour());

        $log->info("TaskObserver: Queued SendBatchVendorAvailabilitySms job with 1-hour delay", [
            ...$logContext,
            'scheduled_for' => now()->addHour()->toDateTimeString(),
        ]);
    }

    /**
     * Queue unified realtime notifications (client + team, all channels).
     * Uses ShouldBeUnique job with 15-minute delay to consolidate rapid changes.
     *
     * @param  array  $originalUserIds  Users assigned before the change (for updates/deletes)
     * @param  array  $newUserIds  Users assigned after the change (for updates/creates)
     */
    protected function queueNotificationIfNeeded(Task $task, array $originalUserIds = [], array $newUserIds = []): void
    {
        if (! $this->taskIncludesToday($task)) {
            return;
        }

        // Only dispatch from authenticated dashboard changes
        if (! auth()->check()) {
            return;
        }

        // Skip if notifications are entirely disabled for this task's vendor
        if (! $this->smsEnabledForTask($task, 'client') && ! $this->smsEnabledForTask($task, 'team')) {
            return;
        }

        // Determine affected user IDs
        if (empty($originalUserIds) && empty($newUserIds)) {
            $affectedUserIds = $task->user_ids ?? [];
        } else {
            $affectedUserIds = array_values(array_unique(array_merge($originalUserIds, $newUserIds)));
        }

        $smsService = app(SmsScheduleService::class);
        $vendorTimezone = $this->getOwningVendor($task)?->timezone;
        $sendAt = $smsService->isWithinBusinessHours($vendorTimezone)
            ? now()->addMinutes(15)
            : $smsService->getNextBusinessHoursStart($vendorTimezone);

        Log::channel('notification')->info('TaskObserver: Queueing realtime task notification', [
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'affected_user_ids' => $affectedUserIds,
            'changed_by_user_id' => auth()->id(),
            'scheduled_for' => $sendAt->toDateTimeString(),
        ]);

        SendRealtimeTaskNotification::dispatch($task->project_id, $affectedUserIds)
            ->delay($sendAt);
    }

    /**
     * Check if a task includes today based on options->dates or date range.
     */
    protected function taskIncludesToday(Task $task): bool
    {
        $today = Carbon::today();
        $todayStr = $today->format('Y-m-d');
        $selectedDates = (array) data_get($task->options, 'dates', []);

        if (! empty($selectedDates)) {
            return in_array($todayStr, $selectedDates);
        }

        if ($task->start_date && $task->end_date) {
            return Carbon::parse($task->start_date)->lte($today)
                && Carbon::parse($task->end_date)->gte($today);
        }

        return false;
    }

    private function smsEnabledForTask(Task $task, string $type): bool
    {
        $vendor = $this->getOwningVendor($task);

        if (! $vendor) {
            return true;
        }

        $baseEnabled = (bool) data_get($vendor->options, 'sms_enabled', true);

        return match ($type) {
            'client' => (bool) data_get($vendor->options, 'sms_client_enabled', $baseEnabled),
            'team' => (bool) data_get($vendor->options, 'sms_team_enabled', $baseEnabled),
            default => $baseEnabled,
        };
    }

    private function getOwningVendor(Task $task): ?Vendor
    {
        return $task->project?->createdByVendor;
    }
}
