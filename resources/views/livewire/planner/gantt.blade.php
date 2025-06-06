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
                                    })
                                    .catch(() => {
                                        this.updating = false;
                                    });
                            } else { // right
                                const finalStartIndex = actualStartIndex < 0 ? actualStartIndex : this.startIndex;

                                $wire.updateTaskDates(this.taskId, finalStartIndex, this.endIndex)
                                    .then(() => {
                                        this.updating = false;
                                    })
                                    .catch(() => {
                                        this.updating = false;
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
            class="sticky top-0 grid bg-white border-b border-gray-200 z-10 h-[49px]"
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
                class="sticky top-[49px] relative bg-gray-200 border-b border-gray-200 z-20 h-[40px]"
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

                <!-- Project name -->
                <div class="relative flex items-center h-full">
                    <div class="sticky left-0 px-2 font-semibold text-sm">
                        {{ $project->address }}
                    </div>
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
                    @endphp

                    <div
                        wire:key="task-{{ $task->id }}"
                        class="group absolute bg-white/50 border-blue-500 border-opacity-30 group-hover:border-opacity-50 text-md flex items-center shadow select-none {{ $taskStartDate->isBefore($this->days->first()) ? 'border-r border-t border-b rounded-r' : ($taskEndDate->isAfter($this->days->last()) ? 'border-l border-t border-b rounded-l' : ($taskStartDate->isBefore($this->days->first()) && $taskEndDate->isAfter($this->days->last()) ? 'border-t border-b' : 'border rounded')) }}"
                        style="left: {{ $leftPosition + 2 }}px; width: {{ $barWidth - 4 }}px; top: {{ $topPosition }}px; height: {{ $taskBarHeight }}px;"
                        title="{{ $task->title }} ({{ $taskStartDate->format('M j') }} - {{ $taskEndDate->format('M j') }})"
                        x-data="taskResize()"
                        x-init="init({{ $task->id }}, {{ $renderStartDayIndex }}, {{ $renderEndDayIndex }})"
                        x-bind:class="{
                            'shadow-lg z-30': resizing,
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
                            class="absolute inset-0 bg-blue-100/30 rounded flex items-center justify-center z-40"
                        >
                            <div class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
                        </div>

                        <!-- Left resize handle -->
                        @if(!$taskStartDate->isBefore($this->days->first()))
                            <div
                                class="w-2 h-full absolute top-0 left-0 bg-blue-500 opacity-30 group-hover:opacity-50 hover:!opacity-100 rounded-l hover:bg-blue-700"
                                x-bind:class="{
                                    'opacity-100 bg-blue-700': resizing,
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
                                <div class="flex flex-col justify-between h-full gap-1 min-h-0">
                                    <!-- Row 1: Task Title with Users floating right -->
                                    <div class="flex items-center justify-between flex-shrink-0">
                                        <!-- Task Title and Vendor - these will stick together -->
                                        <div
                                            class="flex flex-col gap-1 flex-1 min-w-0"
                                            x-data="{
                                                isSticky: false,
                                                stickyMode: 'normal', // 'normal', 'viewport-left', 'task-right'
                                                init() {
                                                    this.setupSticky();
                                                },
                                                setupSticky() {
                                                    const container = this.$el.closest('.h-full.overflow-auto');
                                                    const taskBar = this.$el.parentElement.parentElement.parentElement.parentElement.parentElement;

                                                    if (!container || !taskBar) return;

                                                    let lastMode = 'normal';
                                                    let debounceTimeout;
                                                    let isTransitioning = false;

                                                    const handleScroll = () => {
                                                        // Prevent calculations during transitions
                                                        if (isTransitioning) return;

                                                        // Clear any pending debounce
                                                        if (debounceTimeout) clearTimeout(debounceTimeout);

                                                        const taskBarRect = taskBar.getBoundingClientRect();
                                                        const containerRect = container.getBoundingClientRect();
                                                        const contentWidth = this.$el.scrollWidth;

                                                        // Calculate the visible width of the task bar
                                                        const visibleLeft = Math.max(taskBarRect.left, containerRect.left);
                                                        const visibleRight = Math.min(taskBarRect.right, containerRect.right);
                                                        const visibleTaskWidth = Math.max(0, visibleRight - visibleLeft);

                                                        // Larger thresholds to prevent flickering
                                                        const scrollThreshold = 15; // Increased from 5px
                                                        const contentBuffer = 20; // Increased from 10px

                                                        const isScrolledOffLeft = taskBarRect.left < (containerRect.left - scrollThreshold);
                                                        const isTaskTooNarrow = visibleTaskWidth < (contentWidth + contentBuffer) && visibleTaskWidth > 0;

                                                        // Determine new mode
                                                        let newMode = 'normal';
                                                        if (isScrolledOffLeft && !isTaskTooNarrow) {
                                                            newMode = 'viewport-left';
                                                        } else if (isTaskTooNarrow) {
                                                            newMode = 'task-right';
                                                        }

                                                        // Only update if mode has changed and is stable
                                                        if (newMode !== lastMode) {
                                                            // Debounce the mode change
                                                            debounceTimeout = setTimeout(() => {
                                                                // Double-check the mode is still the same after debounce
                                                                const recheckTaskBarRect = taskBar.getBoundingClientRect();
                                                                const recheckContainerRect = container.getBoundingClientRect();
                                                                const recheckVisibleLeft = Math.max(recheckTaskBarRect.left, recheckContainerRect.left);
                                                                const recheckVisibleRight = Math.min(recheckTaskBarRect.right, recheckContainerRect.right);
                                                                const recheckVisibleTaskWidth = Math.max(0, recheckVisibleRight - recheckVisibleLeft);

                                                                const recheckIsScrolledOffLeft = recheckTaskBarRect.left < (recheckContainerRect.left - scrollThreshold);
                                                                const recheckIsTaskTooNarrow = recheckVisibleTaskWidth < (contentWidth + contentBuffer) && recheckVisibleTaskWidth > 0;

                                                                let recheckMode = 'normal';
                                                                if (recheckIsScrolledOffLeft && !recheckIsTaskTooNarrow) {
                                                                    recheckMode = 'viewport-left';
                                                                } else if (recheckIsTaskTooNarrow) {
                                                                    recheckMode = 'task-right';
                                                                }

                                                                // Only apply if mode is still the same after recheck
                                                                if (recheckMode === newMode && recheckMode !== this.stickyMode) {
                                                                    isTransitioning = true;
                                                                    this.stickyMode = recheckMode;
                                                                    lastMode = recheckMode;

                                                                    if (recheckMode === 'viewport-left') {
                                                                        this.isSticky = true;
                                                                        this.$el.style.position = 'sticky';
                                                                        this.$el.style.left = '0px';
                                                                        this.$el.style.right = 'auto';
                                                                        this.$el.style.top = 'auto';
                                                                        this.$el.style.transform = '';
                                                                        this.$el.style.zIndex = '25';
                                                                        this.$el.style.maxWidth = '200px';
                                                                        this.$el.style.minWidth = 'max-content';
                                                                    } else if (recheckMode === 'task-right') {
                                                                        this.isSticky = true;
                                                                        const leftOffset = recheckVisibleRight - recheckTaskBarRect.left;
                                                                        this.$el.style.position = 'absolute';
                                                                        this.$el.style.left = leftOffset + 'px';
                                                                        this.$el.style.right = 'auto';
                                                                        this.$el.style.top = '50%';
                                                                        this.$el.style.transform = 'translateY(-50%)';
                                                                        this.$el.style.zIndex = '40';
                                                                        this.$el.style.maxWidth = '180px';
                                                                        this.$el.style.minWidth = 'max-content';
                                                                        this.$el.style.textAlign = 'left';
                                                                    } else {
                                                                        // Normal mode
                                                                        this.isSticky = false;
                                                                        this.$el.style.position = '';
                                                                        this.$el.style.right = '';
                                                                        this.$el.style.left = '';
                                                                        this.$el.style.top = '';
                                                                        this.$el.style.transform = '';
                                                                        this.$el.style.zIndex = '';
                                                                        this.$el.style.maxWidth = '';
                                                                        this.$el.style.minWidth = '';
                                                                        this.$el.style.textAlign = '';
                                                                        this.$el.style.padding = '';
                                                                        this.$el.style.margin = '';
                                                                        this.$el.style.backgroundColor = '';
                                                                        this.$el.style.border = '';
                                                                        this.$el.style.borderRadius = '';
                                                                        this.$el.style.boxShadow = '';
                                                                    }
                                                                }

                                                                // Allow new transitions after a brief delay
                                                                setTimeout(() => {
                                                                    isTransitioning = false;
                                                                }, 50);
                                                            }, 100); // Increased debounce delay
                                                        }
                                                    };

                                                    // Throttle scroll events more aggressively
                                                    let ticking = false;
                                                    const throttledScroll = () => {
                                                        if (!ticking && !isTransitioning) {
                                                            requestAnimationFrame(() => {
                                                                handleScroll();
                                                                ticking = false;
                                                            });
                                                            ticking = true;
                                                        }
                                                    };

                                                    container.addEventListener('scroll', throttledScroll, { passive: true });
                                                    window.addEventListener('resize', handleScroll);

                                                    // Run immediately and after DOM is ready
                                                    setTimeout(handleScroll, 50);
                                                    setTimeout(handleScroll, 200);

                                                    this.$el._cleanup = () => {
                                                        if (debounceTimeout) clearTimeout(debounceTimeout);
                                                        container.removeEventListener('scroll', throttledScroll);
                                                        window.removeEventListener('resize', handleScroll);
                                                    };
                                                }
                                            }"
                                            x-destroy="$el._cleanup && $el._cleanup()"
                                            x-bind:class="{
                                                'text-left': true
                                            }"
                                            >
                                            <!-- Task Title -->
                                            <span class="font-medium leading-tight text-sm truncate">
                                                {{$task->title}}
                                            </span>

                                            <!-- Row 2: Vendor -->
                                            <div class="flex items-center gap-2 min-h-0 h-5">
                                                @if($task->vendor)
                                                    <flux:avatar size="xs" name="{{ $task->vendor->name }}" color="auto" color:seed="{{ $task->vendor->id }}" class="flex-shrink-0" />
                                                    <flux:text class="text-xs min-w-0 truncate">{{ $task->vendor->name }}</flux:text>
                                                @else
                                                    <!-- Empty space to maintain consistent height -->
                                                    <div class="h-5"></div>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Users floating on top right - these stay fixed -->
                                        {{-- @if($task->users->count() > 0)
                                            <flux:avatar.group class="ml-auto flex-shrink-0">
                                                @foreach($task->users as $user)
                                                    <flux:avatar size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" />
                                                @endforeach
                                            </flux:avatar.group>
                                        @endif --}}
                                    </div>
                                </div>
                            </flux:card>
                        </div>

                        <!-- Right resize handle -->
                        @if(!$taskEndDate->isAfter($this->days->last()))
                            <div
                                class="w-2 h-full absolute top-0 right-0 bg-blue-500 opacity-30 group-hover:opacity-50 hover:!opacity-100 rounded-r hover:bg-blue-700"
                                x-bind:class="{
                                    'opacity-100 bg-blue-700': resizing,
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
