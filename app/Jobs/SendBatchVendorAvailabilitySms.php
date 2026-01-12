<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\Vendor;
use App\Notifications\VendorAvailabilityNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendBatchVendorAvailabilitySms implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Business hours for sending vendor SMS (same as team member task SMS).
     */
    protected const BUSINESS_HOURS_START = 7; // 7am
    protected const BUSINESS_HOURS_END = 18; // 6pm

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
    public function handle(): void
    {
        // Check if within business hours, if not, re-queue for next morning
        if (! $this->isWithinBusinessHours()) {
            $nextMorning = $this->getNextBusinessHoursStart();
            Log::info("SendBatchVendorAvailabilitySms: Outside business hours, re-queuing for vendor {$this->vendorId} at {$nextMorning->format('Y-m-d H:i')}");
            
            self::dispatch($this->vendorId, $this->taskIds)
                ->delay($nextMorning);
            
            return;
        }

        $vendor = Vendor::find($this->vendorId);

        if (! $vendor) {
            Log::info("SendBatchVendorAvailabilitySms: Vendor {$this->vendorId} not found, skipping");

            return;
        }

        // Find all tasks for this vendor that need notifications
        $tasks = Task::with(['project', 'owner'])
            ->where('vendor_id', $this->vendorId)
            ->whereNull('vendor_status')
            ->whereNotNull('start_date')
            ->where('start_date', '>=', now()->startOfDay())
            ->orderBy('start_date')
            ->get();

        if ($tasks->isEmpty()) {
            Log::info("SendBatchVendorAvailabilitySms: No eligible tasks for vendor {$this->vendorId}, skipping");

            return;
        }

        // Generate tokens for all tasks
        $taskTokens = [];
        foreach ($tasks as $task) {
            $token = bin2hex(random_bytes(32));
            $task->update([
                'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
                'vendor_status_token' => $token,
            ]);
            $taskTokens[$task->id] = $token;
        }

        // Send consolidated notification
        $vendor->notify(new VendorAvailabilityNotification($tasks, $taskTokens));

        Log::info("SendBatchVendorAvailabilitySms: Sent batch availability request for " . $tasks->count() . " task(s) to vendor {$vendor->id}");
    }

    /**
     * Check if current time is within business hours.
     */
    private function isWithinBusinessHours(): bool
    {
        $now = Carbon::now();
        
        return $now->hour >= self::BUSINESS_HOURS_START && $now->hour < self::BUSINESS_HOURS_END;
    }

    /**
     * Get the next business hours start time.
     */
    private function getNextBusinessHoursStart(): Carbon
    {
        $now = Carbon::now();
        
        // If before business hours today, return today at start hour
        if ($now->hour < self::BUSINESS_HOURS_START) {
            return $now->copy()->setTime(self::BUSINESS_HOURS_START, 0);
        }
        
        // Otherwise return tomorrow at start hour
        return $now->copy()->addDay()->setTime(self::BUSINESS_HOURS_START, 0);
    }
}
