<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VendorAvailabilityController extends Controller
{
    /**
     * Show all pending tasks for a vendor to approve/reject.
     */
    public function index(string $token): View
    {
        // Find any task with this token (regardless of status) to get the vendor
        $task = Task::where('vendor_status_token', $token)
            ->with(['vendor'])
            ->first();

        if (! $task || ! $task->vendor) {
            return view('vendor.availability-index', [
                'valid' => false,
                'message' => 'This link is no longer valid.',
                'tasks' => collect(),
                'vendor' => null,
                'token' => $token,
            ]);
        }

        // Get all tasks for this vendor (any status)
        $vendor = $task->vendor;
        $allTasks = Task::where('vendor_id', $vendor->id)
            ->whereIn('vendor_status', [
                Task::VENDOR_STATUS_REQUESTED,
                Task::VENDOR_STATUS_CONFIRMED,
                Task::VENDOR_STATUS_REJECTED,
            ])
            ->with(['project', 'owner'])
            ->orderBy('start_date')
            ->get();

        return view('vendor.availability-index', [
            'valid' => true,
            'tasks' => $allTasks,
            'vendor' => $vendor,
            'token' => $token,
        ]);
    }

    /**
     * Confirm vendor availability for a task.
     */
    public function confirm(string $token, int $taskId): RedirectResponse
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_status', Task::VENDOR_STATUS_REQUESTED)
            ->first();

        if (! $task) {
            return redirect()->route('vendor.availability.index', $token)
                ->with('error', 'Task not found or already responded.');
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        ]);

        return redirect()->route('vendor.availability.index', $token)
            ->with('success', "You confirmed \"{$task->title}\"!");
    }

    /**
     * Reject vendor availability for a task.
     */
    public function reject(string $token, int $taskId): RedirectResponse
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_status', Task::VENDOR_STATUS_REQUESTED)
            ->first();

        if (! $task) {
            return redirect()->route('vendor.availability.index', $token)
                ->with('error', 'Task not found or already responded.');
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REJECTED,
        ]);

        return redirect()->route('vendor.availability.index', $token)
            ->with('success', "You declined \"{$task->title}\".");
    }
}
