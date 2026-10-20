<?php

namespace App\Services\Tasks;

use App\Models\Task;
use Illuminate\Validation\ValidationException;

/**
 * Confirms, rejects or resets a task's vendor-availability status exactly
 * like App\Livewire\Tasks\TaskCreate's own
 * confirmVendorAvailability()/rejectVendorAvailability()/
 * resetVendorAvailability() — pulled out so the Hive Contractors app's task
 * screen can write the identical vendor_status transitions (and trigger the
 * same App\Observers\TaskObserver side effects, since this is a plain
 * Task::update() call, not a quiet one).
 *
 * Every method throws Illuminate\Validation\ValidationException (message
 * key `vendor_status`) on an invalid transition, the same wording the web's
 * Flux::toast() shows — callers decide how to present it (the Livewire
 * component toasts it, the app controller returns it as a 422).
 */
class TaskVendorAvailability
{
    public function confirm(Task $task): Task
    {
        $this->assertVendorAssigned($task);

        if (! in_array($task->vendor_status, [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED], true)) {
            throw ValidationException::withMessages(['vendor_status' => 'Task is not awaiting vendor response.']);
        }

        $task->update(['vendor_status' => Task::VENDOR_STATUS_CONFIRMED]);

        return $task;
    }

    public function reject(Task $task): Task
    {
        $this->assertVendorAssigned($task);

        if (! in_array($task->vendor_status, [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED], true)) {
            throw ValidationException::withMessages(['vendor_status' => 'Task is not awaiting vendor response.']);
        }

        $task->update(['vendor_status' => Task::VENDOR_STATUS_REJECTED]);

        return $task;
    }

    public function reset(Task $task): Task
    {
        $this->assertVendorAssigned($task);

        if ($task->vendor_status !== Task::VENDOR_STATUS_CONFIRMED) {
            throw ValidationException::withMessages(['vendor_status' => 'Task is not confirmed.']);
        }

        $task->update(['vendor_status' => Task::VENDOR_STATUS_REQUESTED]);

        return $task;
    }

    private function assertVendorAssigned(Task $task): void
    {
        if (! $task->vendor_id) {
            throw ValidationException::withMessages(['vendor_status' => 'Vendor not assigned.']);
        }
    }
}
