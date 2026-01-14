<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Models\Task;
use App\Models\Vendor;
use App\Notifications\VendorAvailabilitySmsNotification;
use App\Services\SmsScheduleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBatchVendorAvailabilitySms implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     * This allows a new job to be dispatched for the same vendor after the lock expires.
     */
    public int $uniqueFor = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $vendorId,
        public array $taskIds = []
    ) {}

    /**
     * The unique ID of the job - prevents duplicate jobs for the same vendor.
     */
    public function uniqueId(): string
    {
        return 'vendor_batch_sms_' . $this->vendorId;
    }

    /**
     * Execute the job.
     */
    public function handle(SmsScheduleService $smsService): void
    {
        $log = $smsService->getLogger('vendor');
        
        $log->info("Job started for vendor {$this->vendorId}", [
            'vendor_id' => $this->vendorId,
            'task_ids' => $this->taskIds,
            'current_time' => now()->toDateTimeString(),
        ]);

        // Check if within business hours, if not, re-queue for next morning
        if (! $smsService->isWithinBusinessHours()) {
            $nextMorning = $smsService->getNextBusinessHoursStart();
            $log->info(
                "SendBatchVendorAvailabilitySms: Outside business hours, re-queuing for vendor {$this->vendorId} at {$nextMorning->toDateTimeString()}",
                [
                'vendor_id' => $this->vendorId,
                'next_run' => $nextMorning->toDateTimeString(),
                ]
            );
            
            self::dispatch($this->vendorId, $this->taskIds)
                ->delay($nextMorning);
            
            return;
        }

        $vendor = Vendor::find($this->vendorId);

        if (! $vendor) {
            $log->warning("Vendor not found, skipping", ['vendor_id' => $this->vendorId]);
            return;
        }

        // Get admin users with cell phones for this vendor
        $adminUsers = $vendor->getAdminUsersWithCellPhones();

        $log->info("Vendor found", [
            'vendor_id' => $vendor->id,
            'vendor_name' => $vendor->name,
            'business_phone' => $vendor->business_phone,
            'admin_users_count' => $adminUsers->count(),
            'admin_user_phones' => $adminUsers->map(fn ($u) => [
                'user_id' => $u->id,
                'name' => $u->name,
                'cell_phone' => $u->cell_phone,
            ])->toArray(),
        ]);

        if ($adminUsers->isEmpty()) {
            $log->warning("No admin users with cell phones found, skipping", [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
            ]);
            return;
        }

        // Find all tasks for this vendor that need notifications
        // Tasks with vendor_status = 'requested' and no token haven't had SMS sent yet
        $tasks = Task::with(['project', 'owner'])
            ->where('vendor_id', $this->vendorId)
            ->where('vendor_status', Task::VENDOR_STATUS_REQUESTED)
            ->whereNull('vendor_status_token')
            ->whereNotNull('start_date')
            ->where('start_date', '>=', now()->startOfDay())
            ->orderBy('start_date')
            ->get();

        $log->info("Found eligible tasks", [
            'vendor_id' => $this->vendorId,
            'task_count' => $tasks->count(),
            'task_ids' => $tasks->pluck('id')->toArray(),
        ]);

        if ($tasks->isEmpty()) {
            $log->info("No eligible tasks, skipping", ['vendor_id' => $this->vendorId]);
            return;
        }

        $vendorToken = $vendor->getOrCreateAvailabilityToken();

        // Mark tasks as sent by setting a (vendor-level) token on each task
        foreach ($tasks as $task) {
            $task->update([
                'vendor_status_token' => $vendorToken,
            ]);
            
            $log->debug("Set vendor token for task", [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'project' => $task->project?->short_address,
                'start_date' => $task->start_date?->toDateString(),
                'vendor_token' => $vendorToken,
            ]);
        }

        // Send consolidated notification to each admin user
        $notification = new VendorAvailabilitySmsNotification($tasks, $vendorToken);
        $successCount = 0;
        $failureCount = 0;

        foreach ($adminUsers as $adminUser) {
            try {
                $adminUser->notify($notification);
                $successCount++;
                
                // Log the send for each task
                foreach ($tasks as $task) {
                    SmsLog::logSent([
                        'channel' => SmsLog::CHANNEL_VENDOR,
                        'type' => SmsLog::TYPE_AVAILABILITY,
                        'user_id' => $adminUser->id,
                        'project_id' => $task->project_id,
                        'task_id' => $task->id,
                        'vendor_id' => $vendor->id,
                        'target_date' => $task->start_date?->format('Y-m-d'),
                    ]);
                }
                
                $log->info("SMS notification sent successfully", [
                    'vendor_id' => $vendor->id,
                    'vendor_name' => $vendor->name,
                    'admin_user_id' => $adminUser->id,
                    'admin_user_name' => $adminUser->name,
                    'phone' => $adminUser->routeNotificationForTwilio(),
                    'task_count' => $tasks->count(),
                    'task_ids' => $tasks->pluck('id')->toArray(),
                ]);
            } catch (\Exception $e) {
                $failureCount++;
                $log->error("Failed to send SMS notification to admin user", [
                    'vendor_id' => $vendor->id,
                    'vendor_name' => $vendor->name,
                    'admin_user_id' => $adminUser->id,
                    'admin_user_name' => $adminUser->name,
                    'phone' => $adminUser->routeNotificationForTwilio(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue sending to other admins even if one fails
            }
        }

        $log->info("SMS batch complete", [
            'vendor_id' => $vendor->id,
            'admin_users_count' => $adminUsers->count(),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
        ]);

        // If all failed, throw exception to trigger job retry
        if ($successCount === 0 && $failureCount > 0) {
            throw new \RuntimeException("All SMS notifications failed for vendor {$vendor->id}");
        }
    }
}
