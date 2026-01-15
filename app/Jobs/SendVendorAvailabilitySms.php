<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\Vendor;
use App\Notifications\VendorAvailabilitySmsNotification;
use App\Services\SmsScheduleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendVendorAvailabilitySms implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     * This allows a new job to be dispatched for the same task after the lock expires.
     */
    public int $uniqueFor = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $taskId
    ) {}

    /**
     * The unique ID of the job - prevents duplicate jobs for the same task.
     */
    public function uniqueId(): string
    {
        return 'vendor_sms_' . $this->taskId;
    }

    /**
     * Execute the job.
     */
    public function handle(SmsScheduleService $smsService): void
    {
        $task = Task::with(['vendor', 'project', 'owner'])->find($this->taskId);

        if (!$task) {
            Log::info("SendVendorAvailabilitySms: Task {$this->taskId} not found, skipping");
            return;
        }

        $owningVendor = $task->project?->createdByVendor;

        if (! $this->smsEnabledForVendor($owningVendor)) {
            return;
        }

        $vendorTimezone = $owningVendor?->timezone;

        if (! $smsService->isWithinBusinessHours($vendorTimezone)) {
            $nextStart = $smsService->getNextBusinessHoursStart($vendorTimezone);
            self::dispatch($this->taskId)->delay($nextStart);

            return;
        }

        // Check if we should still send the notification
        if (!$this->shouldSendNotification($task)) {
            Log::info("SendVendorAvailabilitySms: Conditions not met for task {$this->taskId}, skipping");
            return;
        }

        $vendor = $task->vendor;
        if (!$vendor) {
            Log::info("SendVendorAvailabilitySms: No vendor for task {$this->taskId}, skipping");
            return;
        }

        // Ensure the task has a token (do not regenerate existing token so old links keep working)
        $token = $task->vendor_status_token ?: bin2hex(random_bytes(32));

        $updates = [
            'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        ];

        if (! $task->vendor_status_token) {
            $updates['vendor_status_token'] = $token;
        }

        $task->update($updates);

        // Send the notification
        $vendor->notify(new VendorAvailabilitySmsNotification($task, $token));

        Log::info("SendVendorAvailabilitySms: Sent availability request for task {$this->taskId} to vendor {$vendor->id}");
    }

    /**
     * Determine if we should send the notification.
     */
    private function shouldSendNotification(Task $task): bool
    {
        // Must have a vendor assigned
        if (!$task->vendor_id) {
            return false;
        }

        // Vendor status should be null (not already requested/confirmed/rejected)
        if ($task->vendor_status !== null) {
            return false;
        }

        // Task should have dates set
        if (!$task->start_date) {
            return false;
        }

        // Task start date should be in the future
        if ($task->start_date->isPast()) {
            return false;
        }

        return true;
    }

    private function smsEnabledForVendor(?Vendor $vendor): bool
    {
        if (! $vendor) {
            return true;
        }

        $baseEnabled = (bool) data_get($vendor->options, 'sms_enabled', true);

        return (bool) data_get($vendor->options, 'sms_vendor_enabled', $baseEnabled);
    }
}
