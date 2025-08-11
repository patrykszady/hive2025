<div>
    @php
        $dayColumnWidth = 100;
        $taskBarHeight = 60;
        $taskBarMarginY = 4;
    @endphp

    <div
        class="h-screen overflow-auto isolate"
        x-data="ganttChart()"
        x-init="init()"
        >
        <div class="min-w-max relative">
            <!-- Dependency Lines SVG -->
            <svg
                class="absolute inset-0 z-5 w-full h-full pointer-events-none"
                xmlns="http://www.w3.org/2000/svg"
                x-data="dependencyHandler()"
                x-init="initSvg()"
            >
                <!-- Arrow markers -->
                <defs>
                    <marker
                        id="arrowhead"
                        markerWidth="5"
                        markerHeight="5"
                        refX="4"
                        refY="2.5"
                        orient="auto"
                    >
                        <polyline
                            points="1,1 4,2.5 1,4"
                            class="stroke-gray-500 stroke-1 fill-none stroke-linejoin-round"
                        />
                    </marker>
                    <marker
                        id="arrowhead-highlighted"
                        markerWidth="5"
                        markerHeight="5"
                        refX="4"
                        refY="2.5"
                        orient="auto"
                    >
                        <polyline
                            points="1,1 4,2.5 1,4"
                            class="stroke-gray-700 stroke-1 fill-none stroke-linejoin-round"
                        />
                    </marker>
                    <marker
                        id="arrowhead-blocking"
                        markerWidth="5"
                        markerHeight="5"
                        refX="4"
                        refY="2.5"
                        orient="auto"
                    >
                        <polyline
                            points="1,1 4,2.5 1,4"
                            class="stroke-red-500 stroke-1 fill-none stroke-linejoin-round"
                        />
                    </marker>
                    <marker
                        id="arrowhead-blocking-highlighted"
                        markerWidth="5"
                        markerHeight="5"
                        refX="4"
                        refY="2.5"
                        orient="auto"
                    >
                        <polyline
                            points="1,1 4,2.5 1,4"
                            class="stroke-red-700 stroke-1 fill-none stroke-linejoin-round"
                        />
                    </marker>
                </defs>

                <!-- Dependency paths -->
                @foreach($dependencyLines as $line)
                    <g>
                        <!-- Invisible hover area -->
                        <path
                            d="{{ $line['pathData'] }}"
                            class="stroke-transparent stroke-[10px] fill-none cursor-pointer pointer-events-auto"
                            data-predecessor-id="{{ $line['predecessorId'] }}"
                            data-successor-id="{{ $line['successorId'] }}"
                            @if(isset($line['completePath']))
                                data-complete-path="{{ $line['completePath'] }}"
                            @endif
                            @if(isset($line['isTruncated']) && $line['isTruncated'])
                                data-truncated="true"
                                data-truncated-path="{{ $line['truncatedPath'] }}"
                                data-complete-marker="{{ $line['completeMarker'] }}"
                            @endif
                            @mouseenter="highlightDependency($event.target, true)"
                            @mouseleave="highlightDependency($event.target, false)"
                        />

                        <!-- Visible dependency line -->
                        <path
                            d="{{ $line['pathData'] }}"
                            class="dependency-path fill-none pointer-events-none transition-all duration-200 ease-in-out
                                {{ $line['isBlocking'] ? 'stroke-red-500 stroke-2 [stroke-dasharray:5_5] animate-[dash_1.5s_linear_infinite]' : 'stroke-gray-500 stroke-2' }}
                                opacity-80 hover:opacity-100 hover:stroke-[3px]"
                            data-predecessor-id="{{ $line['predecessorId'] }}"
                            data-successor-id="{{ $line['successorId'] }}"
                            @if(isset($line['completePath']))
                                data-complete-path="{{ $line['completePath'] }}"
                            @endif
                            @if(isset($line['isTruncated']) && $line['isTruncated'])
                                data-truncated="true"
                                data-truncated-path="{{ $line['truncatedPath'] }}"
                                data-complete-marker="{{ $line['completeMarker'] }}"
                            @endif
                            @if($line['showArrow'])
                                marker-end="{{ $line['isBlocking'] ? 'url(#arrowhead-blocking)' : 'url(#arrowhead)' }}"
                            @endif
                        />
                    </g>
                @endforeach
            </svg>

            <!-- Date header -->
            <div
                class="sticky top-0 grid bg-white border-b border-gray-200 z-40 h-[49px]"
                style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
            >
                @foreach ($this->days as $day)
                    <div
                        class="p-2 text-left text-xs border-r border-gray-300
                            {{ $day->isToday() ? 'font-bold bg-accent/10 text-accent' :
                                ($day->isWeekend() ? 'bg-gray-100' : 'bg-gray-50') }}"
                        @if($day->isToday()) data-today @endif
                    >
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

                <!-- Project header -->
                <div class="sticky top-[49px] relative bg-gray-200 border-b border-gray-200 z-35">
                    <!-- Background grid -->
                    <div
                        class="absolute inset-0 grid"
                        style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
                    >
                        @foreach ($this->days as $day)
                            <div
                                class="border-r border-gray-200 h-full
                                    {{ $day->isToday() ? 'bg-accent/20' :
                                        ($day->isWeekend() ? 'bg-gray-100' : 'bg-gray-50') }}"
                                @if($day->isToday()) data-today @endif
                            ></div>
                        @endforeach
                    </div>

                    <!-- Project name section -->
                    <div class="relative flex items-center h-[40px]">
                        <div class="sticky left-0 px-2 text-sm flex items-center z-35">
                            <div class="flex items-center gap-x-4 flex-shrink-0">
                                <div class="flex flex-col">
                                    <a
                                        href="{{ route('projects.show', $project->id) }}"
                                        target="_blank"
                                        class="font-semibold text-gray-800 hover:text-accent transition-colors"
                                    >
                                        {{ $project->address }}
                                    </a>
                                    <span class="text-xs italic text-gray-500">{{ $project->client->name }}</span>
                                </div>
                                <flux:button
                                    wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{ $project->id }} })"
                                    variant="filled"
                                    size="sm"
                                    icon="plus"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Unscheduled Tasks -->
                    <div class="relative w-full h-[36px]">
                        <!-- Background grid -->
                        <div
                            class="absolute inset-0 grid"
                            style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
                        >
                            @foreach ($this->days as $day)
                                <div
                                    class="border-r border-gray-200 h-full
                                        {{ $day->isToday() ? 'bg-accent/20' :
                                            ($day->isWeekend() ? 'bg-gray-100' : 'bg-gray-50') }}"
                                ></div>
                            @endforeach
                        </div>

                        @if($projectData['unscheduledTasks']->count() > 0)
                            <div class="relative flex items-center h-full">
                                <div class="sticky left-0 z-35 h-full flex items-center">
                                    <div class="overflow-x-auto flex items-center px-2 py-1 gap-2 h-full scrollbar-w-1.5 scrollbar-h-1.5 scrollbar-track-gray-100 scrollbar-thumb-gray-400 scrollbar-thumb-rounded max-w-full">
                                        @foreach($projectData['unscheduledTasks'] as $unscheduledTask)
                                            <flux:badge
                                                size="sm"
                                                color="{{ $unscheduledTask->type === 'Task' ? 'blue' : 'indigo' }}"
                                                class="flex-shrink-0 cursor-pointer whitespace-nowrap transition-all duration-200 !opacity-100
                                                    hover:ring-2 hover:ring-offset-1
                                                    {{ $unscheduledTask->type === 'Task' ? 'hover:ring-blue-500' : 'hover:ring-indigo-500' }}"
                                                wire:click="editTask({{ $unscheduledTask->id }})"
                                                wire:loading.attr="disabled"
                                                wire:loading.class="opacity-50 cursor-not-allowed pointer-events-none"
                                                wire:target="editTask"
                                            >
                                                {{ $unscheduledTask->title }}
                                            </flux:badge>
                                        @endforeach
                                    </div>
                                </div>
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
                            <div
                                class="border-r border-gray-200 h-full
                                    {{ $day->isToday() ? 'bg-accent/20' :
                                        ($day->isWeekend() ? 'bg-gray-100' : 'bg-gray-50') }}"
                            ></div>
                        @endforeach
                    </div>

                    <!-- Task bars -->
                    @foreach ($projectData['taskRows'] as $rowIndex => $taskRow)
                        @foreach ($taskRow as $taskIndex => $taskData)
                            @php
                                $task = $taskData['task'];
                                $taskStartDate = $taskData['taskStartDate'];
                                $taskEndDate = $taskData['taskEndDate'];
                                $renderStartDayIndex = $taskData['renderStartDayIndex'];
                                $renderEndDayIndex = $taskData['renderEndDayIndex'];
                                $leftPosition = $taskData['leftPosition'];
                                $barWidth = $taskData['barWidth'];
                                $topPosition = $rowIndex * ($taskBarHeight + $taskBarMarginY * 2) + $taskBarMarginY;
                            @endphp

                            <div
                                wire:key="task-{{ $task->id }}"
                                class="task-bar group absolute bg-white/50 border-opacity-30
                                    {{ $task->type === 'Task' ? 'border-accent' : 'border-indigo-500' }}
                                    text-md flex items-center shadow select-none overflow-visible transition-all duration-200
                                    {{ $taskStartDate->isBefore($this->days->first()) ? 'border-r border-t border-b rounded-r' :
                                        ($taskEndDate->isAfter($this->days->last()) ? 'border-l border-t border-b rounded-l' :
                                        ($taskStartDate->isBefore($this->days->first()) && $taskEndDate->isAfter($this->days->last()) ? 'border-t border-b' : 'border rounded')) }}"
                                style="left: {{ $leftPosition + 2 }}px; width: {{ $barWidth - 4 }}px; top: {{ $topPosition }}px; height: {{ $taskBarHeight }}px;"
                                data-task-id="{{ $task->id }}"
                                data-debug-coords="left:{{ $leftPosition + 2 }},top:{{ $topPosition }},bottom:{{ $topPosition + $taskBarHeight }}"
                                x-data="taskResize({{ $task->id }}, {{ $renderStartDayIndex }}, {{ $renderEndDayIndex }})"
                                x-bind:class="{
                                    'shadow-lg z-15 scale-102': resizing,
                                    '!border-opacity-100': resizing,
                                    'cursor-ew-resize': !resizing,
                                    'cursor-grabbing': resizing,
                                    'hover:border-opacity-100 hover:shadow-md': !$wire.__instance.effects.redirect && !$wire.__instance.effects.busy
                                }"
                                wire:loading.class="animate-pulse cursor-not-allowed"
                                wire:target="updateTaskDates"
                                @mouseenter="highlightTask({{ $task->id }}, true)"
                                @mouseleave="highlightTask({{ $task->id }}, false)"
                            >
                                <!-- Weekend exclusion overlay -->
                                <div class="absolute inset-0 pointer-events-none z-5">
                                    <div class="h-full flex">
                                        @foreach($this->getTaskWeekendExclusions($task, $taskStartDate, $taskEndDate, $barWidth) as $dayData)
                                            <div
                                                class="{{ $dayData['isExcludedWeekend'] ? 'bg-gray-400/30' : '' }}"
                                                style="width: {{ $dayData['segmentWidth'] }}px;"
                                            ></div>
                                        @endforeach
                                    </div>
                                </div>

                                <!-- Left resize handle -->
                                @if(!$taskStartDate->isBefore($this->days->first()))
                                    <div
                                        class="resize-handle w-2 h-full absolute top-0 left-0
                                            {{ $task->type === 'Task' ? 'bg-accent' : 'bg-indigo-500' }}
                                            opacity-30 rounded-l z-30 transition-all duration-200"
                                        x-bind:class="{
                                            '{{ $task->type === 'Task' ? 'opacity-100 bg-accent-content' : 'opacity-100 bg-indigo-600' }}': resizing,
                                            'cursor-ew-resize': true,
                                            'group-hover:opacity-50 hover:!opacity-100 {{ $task->type === 'Task' ? 'hover:bg-accent-content' : 'hover:bg-indigo-600' }}': !$wire.__instance.effects.redirect && !$wire.__instance.effects.busy
                                        }"
                                        wire:loading.class="cursor-not-allowed opacity-20 pointer-events-none"
                                        wire:target="updateTaskDates"
                                        @mousedown="startResize('left', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                        @touchstart="startResize('left', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                    ></div>
                                @endif

                                <!-- Task content -->
                                <div class="flex-1 relative mx-2 h-full overflow-hidden z-20">
                                    <div
                                        class="select-none bg-transparent border-0 shadow-none p-1 my-0 h-full
                                            rounded transition-colors duration-200"
                                        wire:click="editTask({{ $task->id }})"
                                        wire:loading.class="animate-pulse opacity-50 pointer-events-none cursor-not-allowed"
                                        wire:target="editTask"
                                        x-bind:class="{
                                            'cursor-ew-resize': resizing && !$wire.__instance.effects.redirect && !$wire.__instance.effects.busy,
                                            'cursor-pointer': !resizing && !$wire.__instance.effects.redirect && !$wire.__instance.effects.busy,
                                            'cursor-not-allowed': $wire.__instance.effects.redirect || $wire.__instance.effects.busy,
                                            'hover:bg-gray-100/20': !$wire.__instance.effects.redirect && !$wire.__instance.effects.busy && !resizing
                                        }"
                                    >
                                        <div class="flex flex-col justify-between h-full min-h-0 pointer-events-none">
                                            <div class="flex items-center justify-between flex-shrink-0">
                                                <div class="flex flex-col gap-1 flex-1 min-w-0">
                                                    <span class="font-medium leading-tight text-sm whitespace-nowrap overflow-hidden text-ellipsis">
                                                        {{ $task->title }}
                                                    </span>

                                                    <div class="flex items-center gap-1 min-h-0 overflow-hidden">
                                                        @if($task->users->count() > 0)
                                                            @if($task->users->count() === 1)
                                                                @foreach($task->users as $user)
                                                                    <flux:avatar
                                                                        size="xs"
                                                                        name="{{ $user->full_name }}"
                                                                        color="auto"
                                                                        color:seed="{{ $user->id }}"
                                                                    />
                                                                @endforeach
                                                            @else
                                                                <flux:avatar.group class="**:ring-zinc-50 dark:**:ring-zinc-800">
                                                                    @foreach($task->users as $user)
                                                                        <flux:avatar
                                                                            size="xs"
                                                                            name="{{ $user->full_name }}"
                                                                            color="auto"
                                                                            color:seed="{{ $user->id }}"
                                                                        />
                                                                    @endforeach
                                                                </flux:avatar.group>
                                                            @endif
                                                        @endif
                                                        @if($task->vendor)
                                                            <flux:avatar
                                                                size="xs"
                                                                name="{{ $task->vendor->name }}"
                                                                color="auto"
                                                                color:seed="{{ $task->vendor->id }}"
                                                            />
                                                            <flux:text size="xs" class="min-w-0 whitespace-nowrap truncate">{{ $task->vendor->name }}</flux:text>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right resize handle -->
                                @if(!$taskEndDate->isAfter($this->days->last()))
                                    <div
                                        class="resize-handle w-2 h-full absolute top-0 right-0
                                            {{ $task->type === 'Task' ? 'bg-accent' : 'bg-indigo-500' }}
                                            opacity-30 rounded-r z-30 transition-all duration-200"
                                        x-bind:class="{
                                            '{{ $task->type === 'Task' ? 'opacity-100 bg-accent-content' : 'opacity-100 bg-indigo-600' }}': resizing,
                                            'cursor-ew-resize': true,
                                            'group-hover:opacity-50 hover:!opacity-100 {{ $task->type === 'Task' ? 'hover:bg-accent-content' : 'hover:bg-indigo-600' }}': !$wire.__instance.effects.redirect && !$wire.__instance.effects.busy
                                        }"
                                        wire:loading.class="cursor-not-allowed opacity-20 pointer-events-none"
                                        wire:target="updateTaskDates"
                                        @mousedown="startResize('right', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                        @touchstart="startResize('right', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                    ></div>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <livewire:tasks.task-create :vendors="$vendors" :employees="$employees" :projects="$this->projects" />

    <script>
        function ganttChart() {
            return {
                updating: false,

                init() {
                    this.$nextTick(() => this.scrollToToday());
                },

                scrollToToday() {
                    const todayElement = document.querySelector('[data-today]');
                    if (todayElement) {
                        const yesterdayElement = todayElement.previousElementSibling;
                        const scrollTarget = yesterdayElement || todayElement;
                        scrollTarget.scrollIntoView({
                            behavior: 'smooth',
                            inline: 'start',
                            block: 'nearest'
                        });
                    }
                }
            }
        }

        function dependencyHandler() {
            return {
                svgHeight: 0,
                resizeObserver: null,
                eventListeners: [],

                initSvg() {
                    this.$nextTick(() => {
                        this.updateSvgHeight();
                        this.setupResizeObserver();
                        this.setupEventListeners();
                    });
                },

                updateSvgHeight() {
                    const container = this.$el.parentElement;
                    this.svgHeight = container.scrollHeight;
                    this.$el.style.height = this.svgHeight + 'px';
                },

                setupResizeObserver() {
                    const container = this.$el.parentElement;
                    this.resizeObserver = new ResizeObserver(() => this.updateSvgHeight());
                    this.resizeObserver.observe(container);
                },

                setupEventListeners() {
                    const events = [
                        { name: 'task-hover', handler: (e) => this.highlightDependencies(e.detail.taskId, true) },
                        { name: 'task-unhover', handler: (e) => this.highlightDependencies(e.detail.taskId, false) },
                        { name: 'dependency-hover', handler: (e) => this.highlightDependencyLine(e.detail.predecessorId, e.detail.successorId, true) },
                        { name: 'dependency-unhover', handler: (e) => this.highlightDependencyLine(e.detail.predecessorId, e.detail.successorId, false) }
                    ];

                    events.forEach(({ name, handler }) => {
                        document.addEventListener(name, handler);
                        this.eventListeners.push({ name, handler });
                    });
                },

                cleanup() {
                    this.eventListeners.forEach(({ name, handler }) => {
                        document.removeEventListener(name, handler);
                    });

                    if (this.resizeObserver) {
                        this.resizeObserver.disconnect();
                    }
                },

                highlightDependency(element, highlight) {
                    const { predecessorId, successorId } = element.dataset;

                    document.dispatchEvent(new CustomEvent(
                        highlight ? 'dependency-hover' : 'dependency-unhover',
                        { detail: { predecessorId, successorId } }
                    ));
                },

                highlightDependencies(taskId, highlight) {
                    const dependencyPaths = this.$el.querySelectorAll('.dependency-path');

                    if (highlight) {
                        dependencyPaths.forEach(path => {  // Fix: Add opening parenthesis here
                            const { predecessorId, successorId } = path.dataset;

                            if (predecessorId == taskId || successorId == taskId) {
                                // Check if this is a successor task being highlighted
                                if (successorId == taskId) {
                                    // When highlighting successor (inspections), show truncated versions
                                    this.highlightPath(path, false); // Don't show complete path
                                } else {
                                    // When highlighting predecessor (framing), show complete versions
                                    this.highlightPath(path, true); // Show complete path
                                }
                            } else {
                                this.dimPath(path);
                            }
                        });

                        const hoveredTaskBar = document.querySelector(`[data-task-id="${taskId}"]`);
                        if (hoveredTaskBar) {
                            this.highlightTaskBar(hoveredTaskBar);
                        }
                    } else {
                        this.resetAllPaths(dependencyPaths);
                        this.resetTaskBar(document.querySelector(`[data-task-id="${taskId}"]`));
                    }
                },

                highlightDependencyLine(predecessorId, successorId, highlight) {
                    const dependencyPaths = this.$el.querySelectorAll('.dependency-path');

                    if (highlight) {
                        dependencyPaths.forEach(path => {
                            const { predecessorId: pathPredId, successorId: pathSuccId } = path.dataset;

                            if (pathPredId == predecessorId && pathSuccId == successorId) {
                                this.highlightPath(path, true);
                            } else {
                                this.dimPath(path);
                            }
                        });

                        [predecessorId, successorId].forEach(taskId => {
                            const taskBar = document.querySelector(`[data-task-id="${taskId}"]`);
                            if (taskBar) this.highlightTaskBar(taskBar);
                        });
                    } else {
                        this.resetAllPaths(dependencyPaths);
                        document.querySelectorAll('.task-bar').forEach(taskBar => this.resetTaskBar(taskBar));
                    }
                },

                highlightTaskBar(taskBar) {
                    taskBar.classList.add('border-opacity-100', 'shadow-lg');
                    taskBar.querySelectorAll('.resize-handle').forEach(handle => {
                        handle.classList.add('opacity-100');
                    });
                },

                resetTaskBar(taskBar) {
                    if (taskBar) {
                        taskBar.classList.remove('border-opacity-100', 'shadow-lg');
                        taskBar.querySelectorAll('.resize-handle').forEach(handle => {
                            handle.classList.remove('opacity-100');
                        });
                    }
                },

                resetAllPaths(paths) {
                    paths.forEach(path => this.resetPath(path));
                },

                highlightPath(path, showComplete) {
                    path.classList.add('opacity-100', 'stroke-[3px]');
                    path.classList.remove('opacity-30');

                    const currentMarker = path.getAttribute('marker-end');
                    const isBlocking = currentMarker?.includes('blocking');

                    // For highlighted paths, prioritize blocking markers over regular ones
                    if (isBlocking) {
                        path.setAttribute('marker-end', 'url(#arrowhead-blocking-highlighted)');
                        // Show complete path for blocking paths only when showComplete is true
                        if (showComplete && path.dataset.completePath) {
                            path.setAttribute('d', path.dataset.completePath);
                        }
                    } else {
                        // Show complete path for non-blocking paths only when showComplete is true
                        if (showComplete && path.dataset.completePath) {
                            path.setAttribute('d', path.dataset.completePath);
                        }

                        // Always show arrow when highlighting, regardless of successor or predecessor
                        path.setAttribute('marker-end', 'url(#arrowhead-highlighted)');
                    }
                },

                dimPath(path) {
                    path.classList.add('opacity-30');
                    path.classList.remove('opacity-100', 'stroke-[3px]');

                    const currentMarker = path.getAttribute('marker-end');
                    const isBlocking = currentMarker?.includes('blocking');

                    if (isBlocking) {
                        // Check if there's a non-blocking path from the same predecessor being highlighted
                        const predecessorId = path.dataset.predecessorId;
                        const successorId = path.dataset.successorId;

                        const hasHighlightedNonBlockingFromSamePred = Array.from(this.$el.querySelectorAll('.dependency-path'))
                            .some(otherPath =>
                                otherPath !== path &&
                                otherPath.dataset.predecessorId === predecessorId &&
                                otherPath.dataset.successorId === successorId &&
                                !otherPath.classList.contains('stroke-red-500') &&
                                otherPath.classList.contains('opacity-100')
                            );

                        if (hasHighlightedNonBlockingFromSamePred) {
                            // When framing is highlighted, completely hide the red path
                            path.style.display = 'none';
                        } else {
                            // Normal dimming behavior
                            path.setAttribute('marker-end', 'url(#arrowhead-blocking)');
                        }
                    } else {
                        // Reset to original path when dimming
                        if (path.dataset.truncatedPath) {
                            path.setAttribute('d', path.dataset.truncatedPath);
                        }

                        // Check if there's a blocking path to the same target before showing arrow
                        const successorId = path.dataset.successorId;
                        const hasBlockingPathToTarget = Array.from(this.$el.querySelectorAll('.dependency-path'))
                            .some(otherPath =>
                                otherPath !== path &&
                                otherPath.dataset.successorId === successorId &&
                                otherPath.classList.contains('stroke-red-500')
                            );

                        // Only show arrow if there's no blocking path to same target
                        if (!hasBlockingPathToTarget) {
                            path.setAttribute('marker-end', 'url(#arrowhead)');
                        } else {
                            path.removeAttribute('marker-end');
                        }
                    }
                },

                resetPath(path) {
                    path.classList.remove('opacity-100', 'stroke-[3px]', 'opacity-30');

                    // Reset display to visible
                    path.style.display = '';

                    // Always reset to original path data first
                    if (path.dataset.completePath) {
                        path.setAttribute('d', path.dataset.completePath);
                    }

                    const currentMarker = path.getAttribute('marker-end');
                    const isBlocking = currentMarker?.includes('blocking');

                    // Reset to original marker
                    if (isBlocking) {
                        path.setAttribute('marker-end', 'url(#arrowhead-blocking)');
                    } else {
                        // Check if truncated to determine arrow visibility
                        if (path.dataset.truncatedPath) {
                            path.setAttribute('d', path.dataset.truncatedPath);
                            path.removeAttribute('marker-end'); // No arrow when truncated
                        } else {
                            // Show arrow when not truncated
                            path.setAttribute('marker-end', 'url(#arrowhead)');
                        }
                    }
                }
            }
        }

        function taskResize(taskId, startIndex, endIndex) {
            return {
                taskId,
                dayWidth: {{ $dayColumnWidth }},
                startIndex,
                endIndex,
                resizing: false,
                eventHandlers: null,

                highlightTask(taskId, highlight) {
                    document.dispatchEvent(new CustomEvent(
                        highlight ? 'task-hover' : 'task-unhover',
                        { detail: { taskId } }
                    ));
                },

                startResize(side, event, taskStartDate, taskEndDate, viewStartDate, viewEndDate) {
                    this.resizing = true;
                    event.stopPropagation();
                    event.preventDefault();

                    this.disableSelection();

                    const taskBar = event.target.parentElement;
                    const container = event.target.closest('.overflow-auto');
                    const actualStartIndex = Math.floor((new Date(taskStartDate) - new Date(viewStartDate)) / (24 * 60 * 60 * 1000));
                    const actualEndIndex = Math.floor((new Date(taskEndDate) - new Date(viewEndDate)) / (24 * 60 * 60 * 1000)) + {{ count($this->days) - 1 }};

                    this.eventHandlers = {
                        move: (e) => this.handleMove(e, side, taskBar, container, actualStartIndex, actualEndIndex),
                        up: (e) => this.handleUp(e, side, actualStartIndex, actualEndIndex)
                    };

                    this.addEventListeners();
                },

                handleMove(e, side, taskBar, container, actualStartIndex, actualEndIndex) {
                    if (!this.resizing) return;

                    const rect = container.getBoundingClientRect();
                    const clientX = e.touches?.[0]?.clientX ?? e.clientX;
                    const x = clientX - rect.left + container.scrollLeft;
                    const newIndex = Math.floor(x / this.dayWidth);

                    if (side === 'left') {
                        const maxIndex = Math.min({{ count($this->days) - 1 }}, actualEndIndex);
                        if (newIndex <= maxIndex) {
                            this.startIndex = newIndex;
                            const displayEndIndex = maxIndex;
                            const newLeft = Math.max(0, this.startIndex) * this.dayWidth + 2;
                            const newWidth = (displayEndIndex - Math.max(0, this.startIndex) + 1) * this.dayWidth - 4;

                            Object.assign(taskBar.style, {
                                left: newLeft + 'px',
                                width: newWidth + 'px'
                            });
                        }
                    } else {
                        if (newIndex >= Math.max(0, actualStartIndex)) {
                            this.endIndex = newIndex;
                            const newWidth = (this.endIndex - this.startIndex + 1) * this.dayWidth - 4;
                            taskBar.style.width = newWidth + 'px';
                        }
                    }
                },

                handleUp(e, side, actualStartIndex, actualEndIndex) {
                    if (this.resizing) {
                        const finalStartIndex = side === 'left' ? this.startIndex : (actualStartIndex < 0 ? actualStartIndex : this.startIndex);
                        const finalEndIndex = side === 'left' ? (actualEndIndex > {{ count($this->days) - 1 }} ? actualEndIndex : this.endIndex) : this.endIndex;

                        this.$wire.call('updateTaskDates', this.taskId, finalStartIndex, finalEndIndex);
                    }
                    this.cleanup();
                },

                disableSelection() {
                    Object.assign(document.body.style, {
                        userSelect: 'none',
                        webkitUserSelect: 'none',
                        mozUserSelect: 'none',
                        msUserSelect: 'none'
                    });

                    window.getSelection?.()?.removeAllRanges?.();
                },

                enableSelection() {
                    Object.assign(document.body.style, {
                        userSelect: '',
                        webkitUserSelect: '',
                        mozUserSelect: '',
                        msUserSelect: ''
                    });
                },

                addEventListeners() {
                    const events = [
                        ['mousemove', this.eventHandlers.move],
                        ['mouseup', this.eventHandlers.up],
                        ['mouseleave', this.eventHandlers.up],
                        ['touchmove', this.eventHandlers.move, { passive: false }],
                        ['touchend', this.eventHandlers.up],
                        ['touchcancel', this.eventHandlers.up]
                    ];

                    events.forEach(([event, handler, options]) => {
                        document.addEventListener(event, handler, options);
                    });
                },

                removeEventListeners() {
                    if (!this.eventHandlers) return;

                    const events = [
                        ['mousemove', this.eventHandlers.move],
                        ['mouseup', this.eventHandlers.up],
                        ['mouseleave', this.eventHandlers.up],
                        ['touchmove', this.eventHandlers.move],
                        ['touchend', this.eventHandlers.up],
                        ['touchcancel', this.eventHandlers.up]
                    ];

                    events.forEach(([event, handler]) => {
                        document.removeEventListener(event, handler);
                    });
                },

                cleanup() {
                    this.enableSelection();
                    this.removeEventListeners();
                    this.resizing = false;
                    this.eventHandlers = null;
                }
            }
        }
    </script>
</div>
