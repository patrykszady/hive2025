{{-- filepath: c:\Users\patry\web\hive\resources\views\livewire\planner\gantt.blade.php --}}
@php
    $dayColumnWidth = 100;
    $taskBarHeight = 60; // Height of each task bar
    $taskBarMarginY = 4;
@endphp

<div
    class="h-full overflow-auto"
    x-data="{
        scrollToToday() {
            // Find yesterday's element instead of today
            const todayElement = document.querySelector('[data-today]');
            if (todayElement) {
                // Get the previous sibling (yesterday)
                const yesterdayElement = todayElement.previousElementSibling;
                if (yesterdayElement) {
                    yesterdayElement.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
                } else {
                    // If no previous sibling (today is first day), scroll to today
                    todayElement.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
                }
            }
        },

        taskResize() {
            return {
                taskId: null,
                dayWidth: {{ $dayColumnWidth }},
                startIndex: 0,
                endIndex: 0,
                resizing: false,
                updating: false,

                init(taskId, startIndex, endIndex) {
                    this.taskId = taskId;
                    this.startIndex = startIndex;
                    this.endIndex = endIndex;
                },

                startResize(side, event, taskStartDate, taskEndDate, viewStartDate, viewEndDate) {
                    // Don't allow resize if already updating
                    if (this.updating) {
                        return;
                    }

                    this.resizing = true;
                    event.stopPropagation();
                    event.preventDefault();

                    // Prevent text selection during resize
                    document.body.style.userSelect = 'none';
                    document.body.style.webkitUserSelect = 'none';
                    document.body.style.mozUserSelect = 'none';
                    document.body.style.msUserSelect = 'none';

                    // Clear any existing text selection
                    if (window.getSelection) {
                        window.getSelection().removeAllRanges();
                    }

                    const taskBar = event.target.parentElement;
                    const container = event.target.closest('.relative');

                    const actualStartIndex = Math.floor((new Date(taskStartDate) - new Date(viewStartDate)) / (24 * 60 * 60 * 1000));
                    const actualEndIndex = Math.floor((new Date(taskEndDate) - new Date(viewEndDate)) / (24 * 60 * 60 * 1000)) + {{ count($this->days) - 1 }};

                    const moveHandler = (e) => {
                        if (!this.resizing) return;

                        const rect = container.getBoundingClientRect();

                        // Handle both mouse and touch events
                        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                        const x = clientX - rect.left;
                        const newIndex = Math.floor(x / this.dayWidth);

                        if (side === 'left') {
                            if (newIndex <= Math.min({{ count($this->days) - 1 }}, actualEndIndex)) {
                                this.startIndex = newIndex;
                                const displayEndIndex = Math.min({{ count($this->days) - 1 }}, actualEndIndex);
                                const newLeft = Math.max(0, this.startIndex) * this.dayWidth + 2;
                                const newWidth = (displayEndIndex - Math.max(0, this.startIndex) + 1) * this.dayWidth - 4;

                                taskBar.style.left = newLeft + 'px';
                                taskBar.style.width = newWidth + 'px';
                            }
                        } else { // right
                            if (newIndex >= Math.max(0, actualStartIndex)) {
                                this.endIndex = newIndex;
                                const newWidth = (this.endIndex - this.startIndex + 1) * this.dayWidth - 4;
                                taskBar.style.width = newWidth + 'px';
                            }
                        }
                    };

                    const cleanup = () => {
                        // Restore text selection
                        document.body.style.userSelect = '';
                        document.body.style.webkitUserSelect = '';
                        document.body.style.mozUserSelect = '';
                        document.body.style.msUserSelect = '';

                        // Remove event listeners for both mouse and touch
                        document.removeEventListener('mousemove', moveHandler);
                        document.removeEventListener('mouseup', upHandler);
                        document.removeEventListener('mouseleave', upHandler);
                        document.removeEventListener('touchmove', moveHandler);
                        document.removeEventListener('touchend', upHandler);
                        document.removeEventListener('touchcancel', upHandler);

                        // Reset resizing state
                        this.resizing = false;
                    };

                    const upHandler = (e) => {
                        if (this.resizing) {
                            // Set updating state AFTER resize is done
                            this.updating = true;

                            if (side === 'left') {
                                const finalEndIndex = actualEndIndex > {{ count($this->days) - 1 }} ? actualEndIndex : this.endIndex;

                                $wire.updateTaskDates(this.taskId, this.startIndex, finalEndIndex)
                                    .then(() => {
                                        this.updating = false;
                                        // Dispatch custom event to trigger sticky recalculation for ALL tasks
                                        window.dispatchEvent(new CustomEvent('task-resize-complete', {
                                            detail: { taskId: this.taskId, updateAll: true }
                                        }));
                                    })
                                    .catch(() => {
                                        this.updating = false;
                                        // Dispatch event even on error
                                        window.dispatchEvent(new CustomEvent('task-resize-complete', {
                                            detail: { taskId: this.taskId, updateAll: true }
                                        }));
                                    });
                            } else { // right
                                const finalStartIndex = actualStartIndex < 0 ? actualStartIndex : this.startIndex;

                                $wire.updateTaskDates(this.taskId, finalStartIndex, this.endIndex)
                                    .then(() => {
                                        this.updating = false;
                                        // Dispatch custom event to trigger sticky recalculation for ALL tasks
                                        window.dispatchEvent(new CustomEvent('task-resize-complete', {
                                            detail: { taskId: this.taskId, updateAll: true }
                                        }));
                                    })
                                    .catch(() => {
                                        this.updating = false;
                                        // Dispatch event even on error
                                        window.dispatchEvent(new CustomEvent('task-resize-complete', {
                                            detail: { taskId: this.taskId, updateAll: true }
                                        }));
                                    });
                            }
                        }
                        cleanup();
                    };

                    // Add event listeners for both mouse and touch
                    document.addEventListener('mousemove', moveHandler);
                    document.addEventListener('mouseup', upHandler);
                    document.addEventListener('mouseleave', upHandler);
                    document.addEventListener('touchmove', moveHandler, { passive: false });
                    document.addEventListener('touchend', upHandler);
                    document.addEventListener('touchcancel', upHandler);
                }
            }
        }
    }"
    x-init="$nextTick(() => scrollToToday())"
    >

    <div class="min-w-max relative">
        <!-- Date header - always on top -->
        <div
            class="sticky top-0 grid bg-white border-b border-gray-200 z-30 h-[49px]"
            style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
        >
            @foreach ($this->days as $day)
                <div class="p-2 text-left text-xs border-r border-gray-300 {{ $day->isToday() && $day->isWeekend() ? 'font-bold bg-[conic-gradient(from_45deg,transparent_25%,rgb(59,130,246,0.3)_25%,rgb(59,130,246,0.3)_50%,transparent_50%,transparent_75%,rgb(59,130,246,0.3)_75%)] bg-[length:8px_8px]' : ($day->isToday() ? 'font-bold bg-blue-50' : ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(0,0,0,0.05)_25%,rgb(0,0,0,0.05)_50%,transparent_50%,transparent_75%,rgb(0,0,0,0.05)_75%)] bg-[length:8px_8px]' : '')) }}" @if($day->isToday()) data-today @endif>
                    <div>{{ $day->format('D') }}</div>
                    <div>{{ $day->format('M j') }}</div>
                </div>
            @endforeach
        </div>

        <!-- Projects and Tasks -->
        @foreach ($projectsData as $projectData)
            @php
                $project = $projectData['project'];
                $renderedTasks = $projectData['renderedTasks'];
                $projectTimelineHeight = $projectData['projectTimelineHeight'];
            @endphp

            <!-- Project header - sticks below date header -->
            <div
                class="sticky top-[49px] relative bg-gray-200 border-b border-gray-200 z-20"
                >
                <!-- Background grid -->
                <div
                    class="absolute inset-0 grid"
                    style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
                    >
                    @foreach ($this->days as $day)
                        <div class="border-r border-gray-200 h-full {{ $day->isToday() && $day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(59,130,246,0.3)_25%,rgb(59,130,246,0.3)_50%,transparent_50%,transparent_75%,rgb(59,130,246,0.3)_75%)] bg-[length:8px_8px]' : ($day->isToday() ? 'bg-blue-200/80' : ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(0,0,0,0.05)_25%,rgb(0,0,0,0.05)_50%,transparent_50%,transparent_75%,rgb(0,0,0,0.05)_75%)] bg-[length:8px_8px]' : '')) }}"></div>
                    @endforeach
                </div>

                <!-- Project name section - fixed height -->
                <div class="relative flex items-center h-[40px]">
                    <div class="sticky left-0 px-2 text-sm flex items-center z-30">
                        <div class="flex items-center gap-x-4 flex-shrink-0">
                            <div class="flex flex-col">
                                <a href="{{route('projects.show', $project->id)}}" target="_blank" class="font-semibold text-gray-800">
                                    {{ $project->address }}
                                </a>
                                <span class="text-xs italic text-gray-500">{{ $project->client->name }}</span>
                            </div>
                            <flux:button
                                wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{$project->id}} })"
                                variant="filled"
                                size="sm"
                                icon="plus"
                            />
                        </div>
                    </div>
                </div>

                <!-- Unscheduled Tasks - Always show row for consistent spacing -->
                <div class="relative w-full bg-gray-100/50 h-[36px]">
                    <!-- Background grid for consistency -->
                    <div
                        class="absolute inset-0 grid"
                        style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
                    >
                        @foreach ($this->days as $day)
                            <div class="border-r border-gray-200 h-full {{ $day->isToday() && $day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(59,130,246,0.3)_25%,rgb(59,130,246,0.3)_50%,transparent_50%,transparent_75%,rgb(59,130,246,0.3)_75%)] bg-[length:8px_8px]' : ($day->isToday() ? 'bg-blue-200/80' : ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(0,0,0,0.05)_25%,rgb(0,0,0,0.05)_50%,transparent_50%,transparent_75%,rgb(0,0,0,0.05)_75%)] bg-[length:8px_8px]' : '')) }}"></div>
                        @endforeach
                    </div>

                    @if($projectData['unscheduledTasks']->count() > 0)
                        <!-- Unscheduled tasks content -->
                        <div class="relative flex items-center h-full">
                            <!-- Sticky container for the entire task area -->
                            <div class="sticky left-0 z-30 h-full flex items-center">
                                <!-- Scrollable tasks are a inside the sticky container -->
                                <div
                                    class="overflow-x-auto flex items-center px-2 py-1 gap-2 h-full"
                                    style="scrollbar-width: thin; scrollbar-color: #9CA3AF #F3F4F6; max-width: 100vw;"
                                    x-data="{ isHovered: false }"
                                    @mouseenter="isHovered = true"
                                    @mouseleave="isHovered = false"
                                    {{-- x-bind:class="{ 'bg-gray-100/80': isHovered }" --}}
                                    >
                                    @foreach($projectData['unscheduledTasks'] as $unscheduledTask)
                                        @php
                                            $taskTypeColor = $unscheduledTask->type === 'Task' ? 'blue' : ($unscheduledTask->type === 'Milestone' ? 'indigo' : 'blue');
                                        @endphp
                                        <flux:badge
                                            size="sm"
                                            variant="outline"
                                            color="{{ $taskTypeColor }}"
                                            class="flex-shrink-0 cursor-pointer hover:border hover:border-{{ $taskTypeColor }}-500 whitespace-nowrap"
                                            wire:click="editTask({{ $unscheduledTask->id }})"
                                            >
                                            {{ $unscheduledTask->title }}
                                        </flux:badge>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @else
                        <!-- Empty row for projects without unscheduled tasks -->
                        <div class="relative flex items-center h-full">
                            <!-- Empty space to maintain layout consistency -->
                        </div>
                    @endif
                </div>
            </div>

            <!-- Tasks timeline -->
            <div
                class="relative border-b border-gray-200"
                style="height: {{ $projectTimelineHeight }}px;"
            >
                <!-- Background grid -->
                <div
                    class="absolute inset-0 grid"
                    style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
                >
                    @foreach ($this->days as $day)
                        <div class="border-r border-gray-200 h-full {{ $day->isToday() && $day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(59,130,246,0.3)_25%,rgb(59,130,246,0.3)_50%,transparent_50%,transparent_75%,rgb(59,130,246,0.3)_75%)] bg-[length:8px_8px]' : ($day->isToday() ? 'bg-blue-200/80' : ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(0,0,0,0.05)_25%,rgb(0,0,0,0.05)_50%,transparent_50%,transparent_75%,rgb(0,0,0,0.05)_75%)] bg-[length:8px_8px]' : '')) }}"></div>
                    @endforeach
                </div>

                <!-- Task bars -->
                @foreach ($renderedTasks as $taskIndex => $taskData)
                    @php
                        $task = $taskData['task'];
                        $taskStartDate = $taskData['taskStartDate'];
                        $taskEndDate = $taskData['taskEndDate'];
                        $renderStartDayIndex = $taskData['renderStartDayIndex'];
                        $renderEndDayIndex = $taskData['renderEndDayIndex'];
                        $leftPosition = $taskData['leftPosition'];
                        $barWidth = $taskData['barWidth'];
                        $topPosition = $taskIndex * ($taskBarHeight + $taskBarMarginY * 2) + $taskBarMarginY;
                        $taskTypeColor = $task->type === 'Task' ? 'blue' : ($task->type === 'Milestone' ? 'indigo' : 'blue');
                    @endphp

                    <div
                        wire:key="task-{{ $task->id }}"
                        class="group absolute bg-white/50 border-{{ $taskTypeColor }}-500 border-opacity-30 group-hover:border-opacity-50 text-md flex items-center shadow select-none overflow-visible {{ $taskStartDate->isBefore($this->days->first()) ? 'border-r border-t border-b rounded-r' : ($taskEndDate->isAfter($this->days->last()) ? 'border-l border-t border-b rounded-l' : ($taskStartDate->isBefore($this->days->first()) && $taskEndDate->isAfter($this->days->last()) ? 'border-t border-b' : 'border rounded')) }}"
                        style="left: {{ $leftPosition + 2 }}px; width: {{ $barWidth - 4 }}px; top: {{ $topPosition }}px; height: {{ $taskBarHeight }}px;"
                        title="{{ $task->title }} ({{ $taskStartDate->format('M j') }} - {{ $taskEndDate->format('M j') }})"
                        x-data="taskResize()"
                        x-init="init({{ $task->id }}, {{ $renderStartDayIndex }}, {{ $renderEndDayIndex }})"
                        x-bind:class="{
                            'shadow-lg z-15': resizing,
                            '!border-opacity-100': resizing,
                            'animate-pulse': updating,
                            'cursor-pointer hover:bg-gray-100/90': !updating && !resizing,
                            'cursor-not-allowed': updating,
                            'cursor-grabbing': resizing
                        }"
                    >
                        <!-- Loading indicator -->
                        <div
                            x-show="updating"
                            class="absolute inset-0 bg-{{ $taskTypeColor }}-100/30 rounded flex items-center justify-center z-40"
                        >
                            <div class="w-4 h-4 border-2 border-{{ $taskTypeColor }}-500 border-t-transparent rounded-full animate-spin"></div>
                        </div>

                        <!-- Left resize handle -->
                        @if(!$taskStartDate->isBefore($this->days->first()))
                            <div
                                class="w-2 h-full absolute top-0 left-0 bg-{{ $taskTypeColor }}-500 opacity-30 group-hover:opacity-50 hover:!opacity-100 rounded-l hover:bg-{{ $taskTypeColor }}-700 z-15"
                                x-bind:class="{
                                    'opacity-100 bg-{{ $taskTypeColor }}-700': resizing,
                                    'cursor-ew-resize': !updating,
                                    'cursor-not-allowed opacity-20': updating
                                }"
                                @mousedown="startResize('left', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                @touchstart="startResize('left', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                            ></div>
                        @endif

                        <!-- Task content -->
                        <div class="flex-1 relative mx-2 h-full overflow-visible">
                            <flux:card
                                class="select-none !bg-transparent border-0 shadow-none p-1 my-0 overflow-visible h-full"
                                x-on:click="updating = true; $wire.editTask({{$task->id}})"
                                @task-modal-opened.window="updating = false"
                                @task-modal-closed.window="updating = false"
                                :key="$task->id"
                                x-bind:class="{
                                    'cursor-pointer': !updating,
                                    'cursor-not-allowed': updating
                                }"
                            >
                                <div class="flex flex-col justify-between h-full gap-1 min-h-0 overflow-visible">
                                    <div class="flex items-center justify-between flex-shrink-0 relative overflow-visible">
                                        <!-- Task Title and Vendor - smooth sticky behavior -->
                                        <div
                                            class="flex flex-col gap-1 flex-1 min-w-0 relative overflow-visible"
                                            x-data="{
                                                shouldShowOutside: false,
                                                stickyOffset: 0,

                                                init() {
                                                    this.$nextTick(() => {
                                                        this.setupSticky();
                                                    });
                                                },

                                                setupSticky() {
                                                    const container = this.$el.closest('.h-full.overflow-auto');
                                                    let taskBar = this.$el;

                                                    // Walk up the DOM to find the task bar element
                                                    while (taskBar && !taskBar.hasAttribute('wire:key')) {
                                                        taskBar = taskBar.parentElement;
                                                    }

                                                    if (!container || !taskBar) {
                                                        return;
                                                    }

                                                    const updatePosition = () => {
                                                        const taskBarRect = taskBar.getBoundingClientRect();
                                                        const containerRect = container.getBoundingClientRect();

                                                        // Calculate visible area of the task bar
                                                        const visibleLeft = Math.max(taskBarRect.left, containerRect.left);
                                                        const visibleRight = Math.min(taskBarRect.right, containerRect.right);
                                                        const visibleTaskWidth = Math.max(0, visibleRight - visibleLeft);

                                                        // Check if task bar is too narrow (less than 100) - always show outside
                                                        if (taskBarRect.width < 100) {
                                                            this.shouldShowOutside = true;
                                                            this.stickyOffset = taskBarRect.width + 4;
                                                            this.$el.style.transform = '';
                                                            this.$el.style.position = '';
                                                            this.$el.style.zIndex = '';
                                                        } else if (taskBarRect.left >= containerRect.left) {
                                                            // Task is fully visible and wide enough - normal position
                                                            this.shouldShowOutside = false;
                                                            this.stickyOffset = 0;
                                                            this.$el.style.transform = '';
                                                            this.$el.style.position = '';
                                                            this.$el.style.zIndex = '';
                                                        } else if (taskBarRect.right > containerRect.left && visibleTaskWidth >= 100) {
                                                            // Task is partially off-screen but has enough visible area - stick inside
                                                            this.shouldShowOutside = false;
                                                            this.stickyOffset = containerRect.left - taskBarRect.left;
                                                            this.$el.style.transform = 'translateX(' + this.stickyOffset + 'px)';
                                                            this.$el.style.position = 'relative';
                                                            this.$el.style.zIndex = '10';
                                                        } else {
                                                            // Task is mostly/completely off-screen - show outside
                                                            this.shouldShowOutside = true;

                                                            if (taskBarRect.right < containerRect.left) {
                                                                // Task is completely off-screen
                                                                this.stickyOffset = taskBarRect.width + 4;
                                                            } else {
                                                                // Task is partially visible - position just outside
                                                                this.stickyOffset = visibleRight - taskBarRect.left + 4;
                                                            }

                                                            this.$el.style.transform = '';
                                                            this.$el.style.position = '';
                                                            this.$el.style.zIndex = '';
                                                        }
                                                    };

                                                    // Smooth scroll handler using requestAnimationFrame
                                                    let ticking = false;
                                                    const handleScroll = () => {
                                                        if (!ticking) {
                                                            requestAnimationFrame(() => {
                                                                updatePosition();
                                                                ticking = false;
                                                            });
                                                            ticking = true;
                                                        }
                                                    };

                                                    // Listen for task resize completion events
                                                    const handleTaskResizeComplete = (event) => {
                                                        // Check if this is the task that was resized OR if we should update all tasks
                                                        const taskId = taskBar.getAttribute('wire:key');
                                                        if (event.detail.updateAll || taskId === 'task-' + event.detail.taskId) {
                                                            // Force position update after a short delay to allow DOM to update
                                                            setTimeout(() => {
                                                                updatePosition();
                                                            }, 100);
                                                        }
                                                    };

                                                    // Listen for modal events and Livewire updates
                                                    const handleModalAndUpdates = () => {
                                                        setTimeout(() => {
                                                            updatePosition();
                                                        }, 50);
                                                    };

                                                    container.addEventListener('scroll', handleScroll, { passive: true });
                                                    window.addEventListener('resize', updatePosition);
                                                    window.addEventListener('task-resize-complete', handleTaskResizeComplete);
                                                    window.addEventListener('task-modal-opened', handleModalAndUpdates);
                                                    window.addEventListener('task-modal-closed', handleModalAndUpdates);
                                                    document.addEventListener('livewire:updated', handleModalAndUpdates);

                                                    // Initial check
                                                    setTimeout(updatePosition, 50);

                                                    this.$el._cleanup = () => {
                                                        container.removeEventListener('scroll', handleScroll);
                                                        window.removeEventListener('resize', updatePosition);
                                                        window.removeEventListener('task-resize-complete', handleTaskResizeComplete);
                                                        window.removeEventListener('task-modal-opened', handleModalAndUpdates);
                                                        window.removeEventListener('task-modal-closed', handleModalAndUpdates);
                                                        document.removeEventListener('livewire:updated', handleModalAndUpdates);
                                                    };
                                                }
                                            }"
                                            x-destroy="$el._cleanup && $el._cleanup()"
                                        >
                                            <!-- Content block -->
                                            <div
                                                x-bind:class="{
                                                    'absolute left-0 top-0 z-5': shouldShowOutside,
                                                    'flex flex-col gap-1': !shouldShowOutside
                                                }"
                                                x-bind:style="shouldShowOutside ? 'transform: translateX(' + stickyOffset + 'px); pointer-events: none;' : ''"
                                            >
                                                <!-- Task Title -->
                                                <span class="font-medium leading-tight text-sm whitespace-nowrap">
                                                    {{$task->title}}
                                                </span>

                                                <!-- User / Vendor Row -->
                                                <div class="flex items-center gap-2 min-h-0 h-5">
                                                    @if($task->users->count() > 0)
                                                        <flux:avatar.group>
                                                            @foreach($task->users as $user)
                                                                <flux:avatar size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" />
                                                            @endforeach
                                                        </flux:avatar.group>
                                                    @endif
                                                    @if($task->vendor)
                                                        <flux:avatar size="xs" name="{{ $task->vendor->name }}" color="auto" color:seed="{{ $task->vendor->id }}" class="flex-shrink-0" />
                                                        <flux:text class="text-xs min-w-0 whitespace-nowrap truncate">{{ $task->vendor->name }}</flux:text>
                                                        {{-- @else --}}
                                                        {{-- <div class="h-5"></div> --}}
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </flux:card>
                        </div>

                        <!-- Right resize handle -->
                        @if(!$taskEndDate->isAfter($this->days->last()))
                            <div
                                class="w-2 h-full absolute top-0 right-0 bg-{{ $taskTypeColor }}-500 opacity-30 group-hover:opacity-50 hover:!opacity-100 rounded-r hover:bg-{{ $taskTypeColor }}-700 z-15"
                                x-bind:class="{
                                    'opacity-100 bg-{{ $taskTypeColor }}-700': resizing,
                                    'cursor-ew-resize': !updating,
                                    'cursor-not-allowed opacity-20': updating
                                }"
                                @mousedown="startResize('right', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                @touchstart="startResize('right', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                            ></div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <livewire:tasks.task-create :projects="$this->projects" :employees="$employees" :vendors="$vendors"/>
</div>




