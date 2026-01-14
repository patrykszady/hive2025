<?php

namespace App\Console\Commands;

use App\Channels\TwilioChannel;
use App\Models\Task;
use App\Models\Vendor;
use App\Notifications\VendorAvailabilitySmsNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class TestVendorAvailabilitySms extends Command
{
    protected $signature = 'test:vendor-availability-sms 
                            {task_ids?* : Task ID(s) to test - provide multiple to test batch SMS}
                            {--vendor= : Vendor ID to find all pending tasks for}';

    protected $description = 'Test vendor availability SMS notification (sends to dev number). Supports multiple tasks to test batch SMS.';

    public function handle(): int
    {
        $taskIds = $this->argument('task_ids');
        $vendorId = $this->option('vendor');

        if ($vendorId) {
            return $this->testByVendor((int) $vendorId);
        }

        if (!empty($taskIds)) {
            return $this->testByTaskIds($taskIds);
        }

        // Find a task with a vendor assigned
        $task = Task::with(['project', 'vendor'])
            ->whereNotNull('vendor_id')
            ->latest()
            ->first();

        if (!$task) {
            $this->error('No tasks with vendors found. Please provide task_id(s) or --vendor option.');
            return 1;
        }

        return $this->testByTaskIds([$task->id]);
    }

    private function testByVendor(int $vendorId): int
    {
        $vendor = Vendor::find($vendorId);
        if (!$vendor) {
            $this->error("Vendor {$vendorId} not found.");
            return 1;
        }

        $tasks = Task::with(['project', 'owner'])
            ->where('vendor_id', $vendorId)
            ->whereNull('vendor_status')
            ->whereNotNull('start_date')
            ->where('start_date', '>=', now()->startOfDay())
            ->orderBy('start_date')
            ->get();

        if ($tasks->isEmpty()) {
            $this->error("No eligible tasks found for vendor {$vendorId}.");
            return 1;
        }

        $this->info("Found {$tasks->count()} eligible task(s) for vendor: {$vendor->business_name}");

        return $this->sendNotification($vendor, $tasks);
    }

    private function testByTaskIds(array $taskIds): int
    {
        $tasks = Task::with(['project', 'vendor', 'owner'])
            ->whereIn('id', $taskIds)
            ->get();

        if ($tasks->isEmpty()) {
            $this->error('No tasks found with the provided IDs.');
            return 1;
        }

        // Verify all tasks belong to the same vendor
        $vendorIds = $tasks->pluck('vendor_id')->unique()->filter();

        if ($vendorIds->count() > 1) {
            $this->error('All tasks must belong to the same vendor for batch SMS testing.');
            return 1;
        }

        if ($vendorIds->isEmpty()) {
            $this->error('None of the provided tasks have a vendor assigned.');
            return 1;
        }

        $vendor = $tasks->first()->vendor;

        return $this->sendNotification($vendor, $tasks);
    }

    private function sendNotification(Vendor $vendor, Collection $tasks): int
    {
        $this->info("Testing vendor availability SMS...");
        $this->newLine();
        $this->line("Vendor: {$vendor->business_name} (ID: {$vendor->id})");
        $this->newLine();

        $this->table(
            ['Task ID', 'Title', 'Address', 'Date'],
            $tasks->map(fn ($task) => [
                $task->id,
                $task->title,
                $this->formatAddress($task->project),
                $task->start_date?->format('M j, Y') ?? 'N/A',
            ])
        );

        $vendorToken = $vendor->getOrCreateAvailabilityToken();

        // Mark tasks as sent by setting vendor token (do not overwrite existing legacy tokens)
        foreach ($tasks as $task) {
            $updates = [
                'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
            ];

            if (! $task->vendor_status_token) {
                $updates['vendor_status_token'] = $vendorToken;
            }

            $task->update($updates);
        }

        $this->newLine();
        $this->info("Sending SMS to dev number: " . config('services.twilio.dev_to', '+12249993880'));

        // Send the notification directly (bypasses queue for immediate testing)
        $notification = new VendorAvailabilitySmsNotification($tasks, $vendorToken);

        try {
            $channel = new TwilioChannel();
            $channel->send($vendor, $notification);

            $baseUrl = config('app.dev_webhook_url') ?: config('app.url');

            $this->newLine();
            $this->info("✓ SMS sent successfully!");
            $this->info("✓ Task status set to 'requested' for {$tasks->count()} task(s)");
            $this->newLine();

            if (config('app.dev_webhook_url')) {
                $this->line("Using DEV_WEBHOOK_URL: {$baseUrl}");
            } else {
                $this->warn("⚠ No DEV_WEBHOOK_URL set - links will use local URL");
                $this->line("  Set DEV_WEBHOOK_URL in .env to a public tunnel (ngrok, etc.)");
            }

            $this->newLine();
            $this->line("Response page link sent in SMS:");
            $this->line("  {$baseUrl}/vendor/availability/{$vendorToken}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to send SMS: " . $e->getMessage());
            return 1;
        }
    }

    private function formatAddress(?object $project): string
    {
        if (!$project) {
            return 'N/A';
        }

        $lines = [];

        if ($project->address) {
            $lines[] = $project->address;
        }

        $cityStateZip = collect([
            $project->city,
            $project->state,
            $project->zip_code,
        ])->filter()->implode(', ');

        if ($cityStateZip) {
            $lines[] = $cityStateZip;
        }

        return !empty($lines) ? implode("\n", $lines) : 'N/A';
    }
}
