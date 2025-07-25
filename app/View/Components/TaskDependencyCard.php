<?php

namespace App\View\Components;

use Illuminate\View\Component;
use App\Models\TaskDependency;

class TaskDependencyCard extends Component
{
    public $dependency;
    public $mode;
    public $isBlocking;
    public $task;
    public $actionType;

    public function __construct(TaskDependency $dependency, $mode = 'predecessor')
    {
        $this->dependency = $dependency;
        $this->mode = $mode;
        $this->isBlocking = $dependency->isBlocking();
        
        // Set the task based on mode
        $this->task = $mode === 'predecessor' 
            ? $dependency->predecessor 
            : $dependency->successor;
            
        // Set action type based on mode
        $this->actionType = $mode === 'predecessor' ? 'remove' : 'view';
    }

    public function render()
    {
        return view('components.task-dependency-card');
    }
}