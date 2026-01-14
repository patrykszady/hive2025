<?php

namespace App\Livewire\Vendor;

use App\Models\Task;
use App\Models\Vendor;
use Carbon\Carbon;
use Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AvailabilityIndex extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public ?int $vendorId = null;

    public bool $valid = false;
    public string $message = '';

    /**
     * Task being edited for date proposal.
     */
    public ?int $proposingTaskId = null;
    public array $proposedDates = [];
    public array $proposedTimeSettings = [];

    public function mount(string $token): void
    {
        $this->token = $token;

        $vendor = Vendor::where('availability_token', $token)->first();

        // Legacy support: old SMS links used per-task tokens.
        if (! $vendor) {
            $task = Task::where('vendor_status_token', $token)
                ->with(['vendor'])
                ->first();

            if ($task?->vendor) {
                $vendor = $task->vendor;
                $canonicalToken = $vendor->getOrCreateAvailabilityToken();

                if ($canonicalToken !== $token) {
                    $this->redirect(route('vendor.availability.index', $canonicalToken));

                    return;
                }
            }
        }

        if (! $vendor) {
            $this->valid = false;
            $this->message = 'This link is no longer valid.';

            return;
        }

        $this->valid = true;
        $this->vendorId = $vendor->id;
    }

    public function getTasks()
    {
        if (! $this->vendorId) {
            return collect();
        }

        return Task::where('vendor_id', $this->vendorId)
            ->whereIn('vendor_status', [
                Task::VENDOR_STATUS_REQUESTED,
                Task::VENDOR_STATUS_CONFIRMED,
                Task::VENDOR_STATUS_REJECTED,
                Task::VENDOR_STATUS_PROPOSED,
            ])
            ->with(['project', 'owner'])
            ->orderBy('start_date')
            ->get();
    }

    public function getVendor()
    {
        if (! $this->vendorId) {
            return null;
        }

        return Vendor::find($this->vendorId);
    }

    /**
     * Confirm vendor availability for a task.
     */
    public function confirm(int $taskId): void
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_id', $this->vendorId)
            ->whereIn('vendor_status', [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED])
            ->first();

        if (! $task) {
            Flux::toast(
                text: 'Task not found or already responded.',
                variant: 'danger',
            );

            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        ]);

        Flux::toast(
            text: "You confirmed \"{$task->title}\"!",
            variant: 'success',
        );
    }

    /**
     * Reject vendor availability for a task.
     */
    public function reject(int $taskId): void
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_id', $this->vendorId)
            ->whereIn('vendor_status', [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED])
            ->first();

        if (! $task) {
            Flux::toast(
                text: 'Task not found or already responded.',
                variant: 'danger',
            );

            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REJECTED,
        ]);

        Flux::toast(
            text: "You declined \"{$task->title}\".",
            variant: 'success',
        );
    }

    /**
     * Open the date proposal modal for a task.
     */
    public function openProposeDatesModal(int $taskId): void
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_id', $this->vendorId)
            ->whereIn('vendor_status', [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED, Task::VENDOR_STATUS_CONFIRMED])
            ->first();

        if (! $task) {
            return;
        }

        $this->proposingTaskId = $taskId;

        // Pre-populate with existing dates from the task
        $existingDates = $task->options->dates ?? [];
        $this->proposedDates = ! empty($existingDates) ? (array) $existingDates : [];

        // Pre-populate time settings (convert nested stdClass to arrays)
        $existingTimeSettings = json_decode(json_encode($task->options->time_settings ?? []), true) ?? [];
        $this->proposedTimeSettings = [];

        foreach ($this->proposedDates as $date) {
            $daySettings = $existingTimeSettings[$date] ?? [];
            $this->proposedTimeSettings[$date] = [
                'use_time' => $daySettings['use_time'] ?? false,
                'start_time' => $daySettings['start_time'] ?? '',
                'end_time' => $daySettings['end_time'] ?? '',
            ];
        }

        $this->modal('vendor_propose_dates_modal')->show();
    }

    /**
     * Called when dates are updated to sync time settings.
     */
    public function updatedProposedDates(): void
    {
        // Add new dates to time settings
        foreach ($this->proposedDates as $date) {
            if (! isset($this->proposedTimeSettings[$date])) {
                $this->proposedTimeSettings[$date] = [
                    'use_time' => false,
                    'start_time' => '',
                    'end_time' => '',
                ];
            }
        }

        // Remove dates that are no longer selected
        $this->proposedTimeSettings = array_filter(
            $this->proposedTimeSettings,
            fn ($key) => in_array($key, $this->proposedDates),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Auto-set end time when start time changes.
     */
    public function updateEndTime(string $date): void
    {
        $settings = $this->proposedTimeSettings[$date] ?? null;

        if (! $settings || empty($settings['start_time'])) {
            return;
        }

        try {
            $startTime = Carbon::createFromFormat('H:i', $settings['start_time']);
            $this->proposedTimeSettings[$date]['end_time'] = $startTime->addHours(2)->format('H:i');
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Save proposed dates.
     */
    public function saveProposedDates(): void
    {
        if (empty($this->proposedDates)) {
            Flux::toast(
                text: 'Please select at least one date.',
                variant: 'warning',
            );

            return;
        }

        $task = Task::where('id', $this->proposingTaskId)
            ->where('vendor_id', $this->vendorId)
            ->whereIn('vendor_status', [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED, Task::VENDOR_STATUS_CONFIRMED])
            ->first();

        if (! $task) {
            Flux::toast(
                text: 'Task not found or already responded.',
                variant: 'danger',
            );

            return;
        }

        // Sort dates
        $sortedDates = collect($this->proposedDates)->sort()->values()->all();

        // Build new options with proposed dates
        $options = (array) ($task->options ?? []);
        $options['dates'] = $sortedDates;
        $options['time_settings'] = $this->proposedTimeSettings;
        $options['vendor_proposed_dates'] = $sortedDates;
        $options['vendor_proposed_at'] = now()->toISOString();

        // Update start_date and end_date based on proposed dates
        $firstDate = Carbon::parse($sortedDates[0]);
        $lastDate = Carbon::parse(end($sortedDates));

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
            'options' => (object) $options,
            'start_date' => $firstDate,
            'end_date' => count($sortedDates) > 1 ? $lastDate : $firstDate,
        ]);

        $this->modal('vendor_propose_dates_modal')->close();

        Flux::toast(
            text: "You confirmed \"{$task->title}\" for the selected dates!",
            variant: 'success',
        );

        // Reset state
        $this->proposingTaskId = null;
        $this->proposedDates = [];
        $this->proposedTimeSettings = [];
    }

    public function cancelProposal(): void
    {
        $this->modal('vendor_propose_dates_modal')->close();
        $this->proposingTaskId = null;
        $this->proposedDates = [];
        $this->proposedTimeSettings = [];
    }

    public function render()
    {
        return view('livewire.vendor.availability-index', [
            'tasks' => $this->getTasks(),
            'vendor' => $this->getVendor(),
        ])->layout('components.layouts.guest');
    }
}
