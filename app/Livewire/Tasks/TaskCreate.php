<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Models\Vendor;
use App\Models\TaskDependency;
use App\Livewire\Forms\TaskForm;
use App\Livewire\Planner\GanttIndex;
use App\Livewire\Planner\CardsIndex;
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

    public $view_text = [
        'card_title' => 'Create Task',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    protected $listeners = ['editTask', 'addTask'];

    #[Computed]
    public function vendors()
    {
        // Use Scout search to sort by ytd_expense_sum
        return Vendor::search('*')
            ->orderBy('ytd_expense_sum', 'desc')
            ->get();
    }

    #[Computed]
    public function employees()
    {
        return auth()->user()->vendor->users()->employed()->get();
    }

    #[Computed]
    public function duration()
    {
        $startDate = $this->form->dates['start'] ?? null;
        $endDate = $this->form->dates['end'] ?? null;

        if (is_null($startDate) || is_null($endDate)) {
            return 0;
        }

        $period = CarbonPeriod::create($startDate, $endDate);
        $totalDays = 0;

        foreach ($period as $date) {
            $isSaturday = $date->isSaturday();
            $isSunday = $date->isSunday();

            // Include the day if:
            // - It's a weekday (not Saturday or Sunday)
            // - It's Saturday and Saturday is enabled
            // - It's Sunday and Sunday is enabled
            if ((!$isSaturday && !$isSunday) ||
                ($isSaturday && $this->form->saturday) ||
                ($isSunday && $this->form->sunday)) {
                $totalDays++;
            }
        }

        return $totalDays;
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

    public function render()
    {
        return view('livewire.tasks.create');
    }
}