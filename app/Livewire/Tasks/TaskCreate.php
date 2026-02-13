<?php

namespace App\Livewire\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use App\Models\TaskDependency;
use App\Livewire\Forms\TaskForm;
use App\Livewire\Planner\GanttIndex;
use App\Livewire\Planner\CardsIndex;
use App\Livewire\Planner\PlannerTaskCard;
use App\Livewire\Projects\UpcomingTasks;
use App\Livewire\Dashboard\UserTasks;
use App\Livewire\Clients\UpcomingClientTasks;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\Attributes\Computed;

class TaskCreate extends Component
{
    use AuthorizesRequests;

    public TaskForm $form;

    //$projects  come from the Planner Component
    public $projects = [];
    public $selectedPredecessorId = null;
    public $dependencyType = 'finish_to_start';
    public $lagDays = 0;
    public $showCompletedChecklist = false;

    public $view_text = [
        'card_title' => 'Create Task',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    protected $listeners = ['editTask', 'addTask'];

    private function ensureProjectOptionLoaded(?int $projectId): void
    {
        if (! $projectId) {
            return;
        }

        $existingIds = collect($this->projects)
            ->map(fn ($project) => is_object($project) ? ($project->id ?? null) : (is_array($project) ? ($project['id'] ?? null) : null))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($existingIds->contains((int) $projectId)) {
            return;
        }

        $project = Project::query()
            ->with('latestStatus')
            ->whereKey($projectId)
            ->first();

        if (! $project) {
            return;
        }

        $this->projects = array_values(array_merge([$project], (array) $this->projects));
    }

    #[Computed]
    public function taskTypeTextClasses(): array
    {
        return collect(Task::TYPE_UI)
            ->mapWithKeys(fn (array $ui, string $type) => [$type => $ui['text']])
            ->all();
    }

    #[Computed]
    public function taskTypeUi(): array
    {
        return Task::TYPE_UI[$this->form->type ?? 'Task'] ?? Task::TYPE_UI['Task'];
    }

    #[Computed]
    public function taskTypeTabClasses(): array
    {
        return collect(Task::TYPE_UI)
            ->mapWithKeys(fn (array $ui, string $type) => [
                $type => trim(($ui['border'] ?? '').' '.($ui['text'] ?? '')),
            ])
            ->all();
    }

    #[Computed]
    public function vendors()
    {
        // Use Scout search to sort by ytd_expense_sum
        // Must specify take() to override Scout's default limit of 20
        return Vendor::search('*')
            ->orderBy('ytd_expense_sum', 'desc')
            ->take(1000)
            ->get();
    }

    #[Computed]
    public function employees()
    {
        $vendor = auth()->user()?->vendor;

        if (!$vendor) {
            return collect();
        }

        return $vendor->users()->employed()->get();
    }

    #[Computed]
    public function duration()
    {
        if (empty($this->form->dates) || !is_array($this->form->dates)) {
            return 0;
        }

        return count($this->form->dates);
    }

    /**
     * Reset form and dependency fields to initial state
     */
    private function resetFormFields()
    {
        $this->form->reset();
        $this->resetErrorBag();
        $this->selectedPredecessorId = null;
        $this->dependencyType = 'finish_to_start';
        $this->lagDays = 0;
    }

    /**
     * Clear all time settings for all selected dates
     */
    public function clearAllTimes()
    {
        $this->form->time_settings = [];
    }

    /**
     * Copy times from the first date that has them to the given date.
     * Called via x-on:change when use_time toggle fires.
     */
    public function copyTimesToDate(string $targetDate): void
    {
        // Only act when use_time is ON for this date
        if (! ($this->form->time_settings[$targetDate]['use_time'] ?? false)) {
            return;
        }

        // Already has times set — nothing to copy
        if (! empty($this->form->time_settings[$targetDate]['start_time'])) {
            return;
        }

        // Find the first other date that has times configured
        foreach ($this->form->dates as $date) {
            if ($date === $targetDate) {
                continue;
            }

            $settings = $this->form->time_settings[$date] ?? [];

            if (! empty($settings['start_time'])) {
                $this->form->time_settings[$targetDate]['start_time'] = $settings['start_time'];
                $this->form->time_settings[$targetDate]['end_time'] = $settings['end_time'] ?? null;
                break;
            }
        }
    }

    public function toggleArrivalTime(string $date): void
    {
        $current = data_get($this->form->time_settings, "$date.use_time", false);

        data_set($this->form->time_settings, "$date.use_time", ! $current);
    }

    /**
     * Update end time to 2 hours after start time
     */
    public function updateEndTime($date)
    {
        if (!isset($this->form->time_settings[$date]['start_time'])) {
            return;
        }

        $startTime = $this->form->time_settings[$date]['start_time'];
        
        try {
            $endTime = Carbon::createFromFormat('H:i', $startTime)
                ->format('H:i');
            
            $this->form->time_settings[$date]['end_time'] = $endTime;
            
            // Apply same times to all other dates
            $this->applyTimeToAllDates($date);
        } catch (\Exception $e) {
            // If parsing fails, do nothing
        }
    }

    /**
     * Apply time settings from one date to all other dates
     * Only copies start/end times if this is the only date with use_time enabled
     * Does not toggle use_time on for other dates
     */
    public function applyTimeToAllDates($sourceDate)
    {
        if (!isset($this->form->time_settings[$sourceDate])) {
            return;
        }

        // Count how many dates have use_time enabled
        $enabledCount = 0;
        foreach ($this->form->dates as $date) {
            if ($this->form->time_settings[$date]['use_time'] ?? false) {
                $enabledCount++;
            }
        }

        // Only propagate if this is the only date with use_time enabled
        if ($enabledCount > 1) {
            return;
        }

        $sourceSettings = $this->form->time_settings[$sourceDate];

        foreach ($this->form->dates as $date) {
            if ($date !== $sourceDate) {
                $this->form->time_settings[$date] = array_merge(
                    $this->form->time_settings[$date] ?? [],
                    [
                        // Preserve existing use_time state, default to false
                        'use_time' => $this->form->time_settings[$date]['use_time'] ?? false,
                        'start_time' => $sourceSettings['start_time'] ?? null,
                        'end_time' => $sourceSettings['end_time'] ?? null,
                    ]
                );
            }
        }
    }

    /**
     * Set the view text configuration based on mode
     */
    private function setupViewText(string $mode)
    {
        $config = [
            'create' => [
                'card_title' => 'Create Task',
                'button_text' => 'Create',
                'form_submit' => 'save',
            ],
            'edit' => [
                'card_title' => 'Edit Task',
                'button_text' => 'Update',
                'form_submit' => 'edit',
            ],
            'duplicate' => [
                'card_title' => 'Duplicate Task',
                'button_text' => 'Create',
                'form_submit' => 'save',
            ],
        ];

        $this->view_text = $config[$mode] ?? $config['create'];
    }

    /**
     * Handle common task operations (show modal, dispatch events)
     */
    private function handleTaskOperation(string $operation, ?Task $task = null)
    {
        if ($operation === 'start' && $task) {
            $this->dispatch('task-operation-started', taskId: $task->id)->to(GanttIndex::class);
        } elseif ($operation === 'complete') {
            $this->refreshPlannerComponents();
            $this->modal('task_create_form_modal')->close();
            $this->dispatch('task-operation-completed')->to(GanttIndex::class);
        }
    }

    /**
     * Show a standardized toast notification
     */
    private function showNotification(string $action)
    {
        $messages = [
            'created' => 'Task Created',
            'updated' => 'Task Updated',
            'removed' => 'Task Removed',
            'dependency_added' => 'Dependency Added',
            'dependency_removed' => 'Dependency Removed',
        ];

        $descriptions = [
            'dependency_added' => 'Task dependency has been created.',
            'dependency_removed' => 'Task dependency has been removed.',
        ];

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: $messages[$action] ?? 'Action Completed',
            text: $descriptions[$action] ?? '',
        );
    }

    /**
     * Helper method to refresh all planner components
     */
    private function refreshPlannerComponents()
    {
        $this->dispatch('refreshComponent')->to(GanttIndex::class);
        $this->dispatch('refreshComponent')->to(CardsIndex::class);
        $this->dispatch('refreshComponent')->to(PlannerTaskCard::class);
        $this->dispatch('refreshComponent')->to(UpcomingTasks::class);
        $this->dispatch('refreshComponent')->to(UserTasks::class);
        $this->dispatch('refreshComponent')->to(UpcomingClientTasks::class);
    }

    /**
     * Copy task data for duplication
     */
    private function copyTaskData(Task $task)
    {
        $this->form->title = $task->title;
        $this->form->type = $task->type;
        $this->form->project_id = $task->project_id;
        $this->form->vendor_id = $task->vendor_id;
        $this->form->user_ids = $task->user_ids;
        $this->form->notes = $task->notes;
        
        // Set up parent-child relationship
        if ($task->parent_task_id) {
            // If current task is already a child, make duplicate a sibling
            $this->form->parent_task_id = $task->parent_task_id;
        } else {
            // If current task is standalone/parent, make duplicate its child
            $this->form->parent_task_id = $task->id;
        }
        
        // Leave dates empty as requested
        $this->form->dates = [];
    }

    public function addTask($project_id = null, $date = null, $vendor_id = null, $user_ids = [])
    {
        $this->resetFormFields();
        $this->setupViewText('create');
        
        $this->form->dates = $date ? [Carbon::parse($date)->format('Y-m-d')] : [];

        // Set the appropriate fields based on what was passed
        if ($project_id) {
            $this->form->project_id = $project_id;
            $this->ensureProjectOptionLoaded((int) $project_id);
        }

        if ($vendor_id) {
            $this->form->vendor_id = $vendor_id;
        }

        if (!empty($user_ids)) {
            $this->form->user_ids = $user_ids;
        }

        $this->modal('task_create_form_modal')->show();
    }

    public function editTask(Task $task)
    {
        $this->handleTaskOperation('start', $task);
        $this->resetFormFields();
        $this->setupViewText('edit');

        $this->ensureProjectOptionLoaded((int) $task->project_id);
        
        // Simply use the task as-is without reloading
        $this->form->setTask($task);
        
        $this->modal('task_create_form_modal')->show();
        $this->dispatch('task-modal-opened');
    }

    public function duplicateTask()
    {
        // Get the current task data
        $currentTask = $this->form->task;
        $this->modal('task_create_form_modal')->close();
        
        $this->resetFormFields();
        $this->setupViewText('duplicate');
        
        // Copy relevant data from current task
        $this->copyTaskData($currentTask);
        
        // Open the modal again with the duplicated data
        $this->modal('task_create_form_modal')->show();
        $this->dispatch('task-modal-opened');
    }

    public function removeTask()
    {
        $task = $this->form->task;

        if (!$task) {
            return;
        }

        $task->delete();
        
        $this->handleTaskOperation('complete');
        $this->showNotification('removed');
    }

    public function edit()
    {
        $this->authorize('update', $this->form->task);
        $result = $this->form->update();

        if ($result === false) {
            // The form's errors need to be copied to the component's error bag
            $formErrors = $this->form->getErrorBag();
            
            // Add each form error to the component's error bag with 'form.' prefix
            foreach ($formErrors->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("form.{$field}", $message);
                }
            }
            return; // Don't close modal
        }

        $this->handleTaskOperation('complete');
        $this->showNotification('updated');
    }

    public function save()
    {
        $this->form->store();
        $this->handleTaskOperation('complete');
        $this->showNotification('created');
    }

    public function addDependency()
    {
        $this->validate([
            'selectedPredecessorId' => [
                'required',
                'exists:tasks,id',
                function ($attribute, $value, $fail) {
                    if ($value == $this->form->task->id) {
                        $fail('A task cannot depend on itself.');
                    }
                }
            ],
            'dependencyType' => 'required|in:finish_to_start,start_to_start,finish_to_finish,start_to_finish',
            'lagDays' => 'integer',
        ]);

        // Check for circular dependencies
        if (TaskDependency::wouldCreateCircularDependency($this->selectedPredecessorId, $this->form->task->id)) {
            $this->addError('selectedPredecessorId', 'This would create a circular dependency.');
            return;
        }

        // Check if dependency already exists
        $existingDependency = TaskDependency::where('predecessor_task_id', $this->selectedPredecessorId)
            ->where('successor_task_id', $this->form->task->id)
            ->first();

        if ($existingDependency) {
            $this->addError('selectedPredecessorId', 'This dependency already exists.');
            return;
        }

        // Create the dependency
        TaskDependency::create([
            'predecessor_task_id' => $this->selectedPredecessorId,
            'successor_task_id' => $this->form->task->id,
            'type' => $this->dependencyType,
            'lag_days' => $this->lagDays,
        ]);
        
        // Reset form fields
        $this->selectedPredecessorId = null;
        $this->lagDays = 0;

        // Refresh task data with eager loading
        $this->form->refreshTaskWithDependencies($this->form->task->id);
        
        // Refresh planner components
        $this->refreshPlannerComponents();
        
        $this->showNotification('dependency_added');
    }

    public function removeDependency($dependencyId)
    {
        TaskDependency::find($dependencyId)->delete();
        
        // Refresh task data with eager loading
        $this->form->refreshTaskWithDependencies($this->form->task->id);
        
        // Refresh planner components
        $this->refreshPlannerComponents();
        
        $this->showNotification('dependency_removed');
    }

    #[Computed]
    public function availableTasks()
    {
        if (!$this->form->task || !$this->form->task->project_id) {
            return collect();
        }

        $excludeIds = [$this->form->task->id];

        // Exclude tasks that are already predecessors
        $existingPredecessorIds = $this->form->task->predecessorTasks->pluck('id')->toArray();
        $excludeIds = array_merge($excludeIds, $existingPredecessorIds);

        return Task::where('project_id', $this->form->task->project_id)
                ->whereNotIn('id', $excludeIds)
                ->whereNotNull('start_date')
                ->whereNotNull('end_date')
                ->orderBy('start_date')
                ->get();
    }

    /**
     * View a dependent task by opening its edit modal
     */
    public function viewDependentTask($taskId)
    {
        // Close current task modal
        $this->modal('task_create_form_modal')->close();
        
        // Get fresh task data with no query cache
        $task = Task::withoutGlobalScopes()->findOrFail($taskId);
    
        // Dispatch the editTask event to open the task
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    /**
     * Check if any dependency is blocking
     */
    #[Computed]
    public function hasBlockingDependency()
    {
        if (!isset($this->form->task)) {
            return false;
        }
        
        // Check predecessor dependencies first
        foreach($this->form->task->predecessorDependencies as $dependency) {
            if($dependency->isBlocking()) {
                return true;
            }
        }
        
        // Then check successor dependencies
        foreach($this->form->task->successorDependencies as $dependency) {
            if($dependency->isBlocking()) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Add a new checklist item
     */
    public function addChecklistItem($text = '')
    {
        $this->form->checklist[] = [
            'text' => $text,
            'completed' => false,
        ];

        // Auto-save the checklist
        $this->saveChecklistOnly();
    }

    /**
     * Remove a checklist item
     */
    public function removeChecklistItem($index)
    {
        unset($this->form->checklist[$index]);
        $this->form->checklist = array_values($this->form->checklist); // Re-index array
    }

    /**
     * Toggle visibility of completed checklist items
     */
    public function toggleCompletedChecklist()
    {
        $this->showCompletedChecklist = !$this->showCompletedChecklist;
    }

    /**
     * Toggle checklist item completion status
     */
    public function toggleChecklistItem($index)
    {
        if (isset($this->form->checklist[$index])) {
            $item = $this->form->checklist[$index];
            $isCompleted = is_array($item) ? ($item['completed'] ?? false) : ($item->completed ?? false);
            
            if (is_object($item)) {
                $item = (array) $item;
            }
            
            $item['completed'] = !$isCompleted;
            $this->form->checklist[$index] = $item;
            
            // Auto-save the checklist without closing modal
            $this->saveChecklistOnly();
        }
    }

    /**
     * Sort checklist items via drag-and-drop
     */
    public function sortChecklistItems($key, $position)
    {
        $items = $this->form->checklist;
        
        // Find the item by its original index
        $fromIndex = (int) $key;
        
        if (!isset($items[$fromIndex])) {
            return;
        }
        
        // Remove item from original position
        $item = $items[$fromIndex];
        array_splice($items, $fromIndex, 1);
        
        // Insert at new position
        array_splice($items, $position, 0, [$item]);
        
        // Re-index and update
        $this->form->checklist = array_values($items);
        
        // Auto-save
        $this->saveChecklistOnly();
    }

    /**
     * Save only the checklist without closing the modal
     */
    private function saveChecklistOnly()
    {
        $task = $this->form->task;

        if (!$task) {
            return;
        }

        // Checklist is stored in options JSON column
        $options = (array) ($task->options ?? []);
        $options['checklist'] = $this->form->checklist;

        $task->update([
            'options' => $options,
        ]);
    }

    /**
     * Save only the notes without closing the modal
     */
    public function saveNotes()
    {
        $task = $this->form->task;

        if (!$task) {
            return;
        }

        $task->update([
            'notes' => $this->form->notes,
        ]);
    }

    public function render()
    {
        return view('livewire.tasks.create');
    }

    public function confirmVendorAvailability(): void
    {
        if (! $this->form->task) {
            return;
        }

        $task = Task::find($this->form->task->id);

        if (! $task || ! $task->vendor_id) {
            Flux::toast(text: 'Vendor not assigned.', variant: 'danger');
            return;
        }

        $this->authorize('update', $task);

        if (! in_array($task->vendor_status, [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED], true)) {
            Flux::toast(text: 'Task is not awaiting vendor response.', variant: 'danger');
            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        ]);

        $this->form->task->refresh();
        $this->refreshPlannerComponents();

        Flux::toast(text: 'Vendor confirmed for this task.', variant: 'success');
    }

    public function rejectVendorAvailability(): void
    {
        if (! $this->form->task) {
            return;
        }

        $task = Task::find($this->form->task->id);

        if (! $task || ! $task->vendor_id) {
            Flux::toast(text: 'Vendor not assigned.', variant: 'danger');
            return;
        }

        $this->authorize('update', $task);

        if (! in_array($task->vendor_status, [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED], true)) {
            Flux::toast(text: 'Task is not awaiting vendor response.', variant: 'danger');
            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REJECTED,
        ]);

        $this->form->task->refresh();
        $this->refreshPlannerComponents();

        Flux::toast(text: 'Vendor rejected for this task.', variant: 'success');
    }

    public function resetVendorAvailability(): void
    {
        if (! $this->form->task) {
            return;
        }

        $task = Task::find($this->form->task->id);

        if (! $task || ! $task->vendor_id) {
            Flux::toast(text: 'Vendor not assigned.', variant: 'danger');
            return;
        }

        $this->authorize('update', $task);

        if ($task->vendor_status !== Task::VENDOR_STATUS_CONFIRMED) {
            Flux::toast(text: 'Task is not confirmed.', variant: 'danger');
            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        ]);

        $this->form->task->refresh();
        $this->refreshPlannerComponents();

        Flux::toast(text: 'Vendor status reset to requested.', variant: 'success');
    }
}