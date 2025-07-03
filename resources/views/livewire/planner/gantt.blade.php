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
                class="absolute inset-0 z-10 w-full h-full pointer-events-none"
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
                            @if(isset($line['isTruncated']) && $line['isTruncated'])
                                data-truncated="true"
                                data-truncated-path="{{ $line['truncatedPath'] }}"
                                data-complete-path="{{ $line['completePath'] }}"
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
                            @if(isset($line['isTruncated']) && $line['isTruncated'])
                                data-truncated="true"
                                data-truncated-path="{{ $line['truncatedPath'] }}"
                                data-complete-path="{{ $line['completePath'] }}"
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
                class="sticky top-0 grid bg-white border-b border-gray-200 z-30 h-[49px]"
                style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
            >
                @foreach ($this->days as $day)
                    <div
                        class="p-2 text-left text-xs border-r border-gray-300
                            {{ $day->isToday() && $day->isWeekend() ? 'font-bold bg-gradient-to-br from-blue-400/30 to-blue-500/30' :
                                ($day->isToday() ? 'font-bold bg-blue-50' :
                                ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(156_163_175/0.15)_25%,rgb(156_163_175/0.15)_50%,transparent_50%,transparent_75%,rgb(156_163_175/0.15)_75%)] bg-[length:8px_8px] bg-gray-50/30' : '')) }}"
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
                <div class="sticky top-[49px] relative bg-gray-200 border-b border-gray-200 z-20">
                    <!-- Background grid -->
                    <div
                        class="absolute inset-0 grid"
                        style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
                    >
                        @foreach ($this->days as $day)
                            <div
                                class="border-r border-gray-200 h-full
                                    {{ $day->isToday() && $day->isWeekend() ? 'bg-gradient-to-br from-blue-400/30 to-blue-500/30' :
                                        ($day->isToday() ? 'bg-blue-200/80' :
                                        ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(156_163_175/0.15)_25%,rgb(156_163_175/0.15)_50%,transparent_50%,transparent_75%,rgb(156_163_175/0.15)_75%)] bg-[length:8px_8px] bg-gray-50/30' : '')) }}"
                            ></div>
                        @endforeach
                    </div>

                    <!-- Project name section -->
                    <div class="relative flex items-center h-[40px]">
                        <div class="sticky left-0 px-2 text-sm flex items-center z-30">
                            <div class="flex items-center gap-x-4 flex-shrink-0">
                                <div class="flex flex-col">
                                    <a
                                        href="{{ route('projects.show', $project->id) }}"
                                        target="_blank"
                                        class="font-semibold text-gray-800 hover:text-blue-600 transition-colors"
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
                        <div
                            class="absolute inset-0 grid"
                            style="grid-template-columns: repeat({{ count($this->days) }}, {{ $dayColumnWidth }}px);"
                        >
                            @foreach ($this->days as $day)
                                <div
                                    class="border-r border-gray-200 h-full
                                        {{ $day->isToday() && $day->isWeekend() ? 'bg-gradient-to-br from-blue-400/30 to-blue-500/30' :
                                            ($day->isToday() ? 'bg-blue-200/80' :
                                            ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(156_163_175/0.15)_25%,rgb(156_163_175/0.15)_50%,transparent_50%,transparent_75%,rgb(156_163_175/0.15)_75%)] bg-[length:8px_8px] bg-gray-50/30' : '')) }}"
                                ></div>
                            @endforeach
                        </div>

                        @if($projectData['unscheduledTasks']->count() > 0)
                            <div class="relative flex items-center h-full">
                                <div class="sticky left-0 z-30 h-full flex items-center">
                                    <div class="overflow-x-auto flex items-center px-2 py-1 gap-2 h-full scrollbar-w-1.5 scrollbar-h-1.5 scrollbar-track-gray-100 scrollbar-thumb-gray-400 scrollbar-thumb-rounded max-w-full">
                                        @foreach($projectData['unscheduledTasks'] as $unscheduledTask)
                                            @php
                                                $taskTypeColor = $unscheduledTask->type === 'Task' ? 'blue' : ($unscheduledTask->type === 'Milestone' ? 'indigo' : 'blue');
                                            @endphp
                                            <flux:badge
                                                size="sm"
                                                variant="outline"
                                                color="{{ $taskTypeColor }}"
                                                class="flex-shrink-0 cursor-pointer hover:border-{{ $taskTypeColor }}-500 whitespace-nowrap transition-colors"
                                                wire:click="editTask({{ $unscheduledTask->id }})"
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
                                    {{ $day->isToday() && $day->isWeekend() ? 'bg-gradient-to-br from-blue-400/30 to-blue-500/30' :
                                        ($day->isToday() ? 'bg-blue-200/80' :
                                        ($day->isWeekend() ? 'bg-[conic-gradient(from_45deg,transparent_25%,rgb(156_163_175/0.15)_25%,rgb(156_163_175/0.15)_50%,transparent_50%,transparent_75%,rgb(156_163_175/0.15)_75%)] bg-[length:8px_8px] bg-gray-50/30' : '')) }}"
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
                                $taskTypeColor = $task->type === 'Task' ? 'blue' : ($task->type === 'Milestone' ? 'indigo' : 'blue');
                            @endphp

                            <div
                                wire:key="task-{{ $task->id }}"
                                class="task-bar group absolute bg-white/50 border-{{ $taskTypeColor }}-500 border-opacity-30
                                    text-md flex items-center shadow select-none overflow-visible transition-all duration-200
                                    {{ $taskStartDate->isBefore($this->days->first()) ? 'border-r border-t border-b rounded-r' :
                                        ($taskEndDate->isAfter($this->days->last()) ? 'border-l border-t border-b rounded-l' :
                                        ($taskStartDate->isBefore($this->days->first()) && $taskEndDate->isAfter($this->days->last()) ? 'border-t border-b' : 'border rounded')) }}
                                    hover:border-opacity-50 hover:shadow-md"
                                style="left: {{ $leftPosition + 2 }}px; width: {{ $barWidth - 4 }}px; top: {{ $topPosition }}px; height: {{ $taskBarHeight }}px;"
                                data-task-id="{{ $task->id }}"
                                x-data="taskResize({{ $task->id }}, {{ $renderStartDayIndex }}, {{ $renderEndDayIndex }})"
                                x-bind:class="{
                                    'shadow-lg z-15 scale-102': resizing,
                                    '!border-opacity-100': resizing,
                                    'animate-pulse': updating,
                                    'cursor-ew-resize': !updating && !resizing,
                                    'cursor-not-allowed': updating,
                                    'cursor-grabbing': resizing
                                }"
                                @mouseenter="highlightTask({{ $task->id }}, true)"
                                @mouseleave="highlightTask({{ $task->id }}, false)"
                            >
                                <!-- Loading indicator -->
                                <div
                                    x-show="updating"
                                    class="absolute inset-0 bg-{{ $taskTypeColor }}-100/30 rounded flex items-center justify-center z-40"
                                >
                                    <div class="w-4 h-4 border-2 border-{{ $taskTypeColor }}-500 border-t-transparent rounded-full animate-spin"></div>
                                </div>

                                <!-- Weekend exclusion overlay -->
                                <div class="absolute inset-0 pointer-events-none">
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
                                        class="resize-handle w-2 h-full absolute top-0 left-0 bg-{{ $taskTypeColor }}-500
                                            opacity-30 rounded-l z-15 transition-all duration-200
                                            group-hover:opacity-50 hover:!opacity-100 hover:bg-{{ $taskTypeColor }}-700"
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
                                <div class="flex-1 relative mx-2 h-full overflow-hidden">
                                    <div
                                        class="select-none bg-transparent border-0 shadow-none p-1 my-0 h-full
                                            cursor-pointer rounded transition-colors duration-200
                                            hover:bg-gray-100/20"
                                        x-on:click="handleTaskEdit({{ $task->id }})"
                                        x-bind:class="{
                                            'cursor-pointer': !updating,
                                            'cursor-not-allowed pointer-events-none': updating
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
                                                            <flux:avatar.group>
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
                                        class="resize-handle w-2 h-full absolute top-0 right-0 bg-{{ $taskTypeColor }}-500
                                            opacity-30 rounded-r z-15 transition-all duration-200
                                            group-hover:opacity-50 hover:!opacity-100 hover:bg-{{ $taskTypeColor }}-700"
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
                },

                handleTaskEdit(taskId) {
                    this.updating = true;
                    this.$wire.editTask(taskId)
                        .finally(() => this.updating = false);
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
                        dependencyPaths.forEach(path => {
                            const { predecessorId, successorId } = path.dataset;

                            if (predecessorId == taskId || successorId == taskId) {
                                this.highlightPath(path, true);
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

                    path.setAttribute('marker-end',
                        isBlocking ? 'url(#arrowhead-blocking-highlighted)' : 'url(#arrowhead-highlighted)'
                    );

                    if (showComplete && path.dataset.truncated === 'true' && !isBlocking && path.dataset.completePath) {
                        path.setAttribute('d', path.dataset.completePath);
                        path.setAttribute('marker-end', 'url(#arrowhead-highlighted)');
                    }
                },

                dimPath(path) {
                    path.classList.add('opacity-30');
                    path.classList.remove('opacity-100', 'stroke-[3px]');

                    const currentMarker = path.getAttribute('marker-end');
                    const isBlocking = currentMarker?.includes('blocking');

                    path.setAttribute('marker-end',
                        isBlocking ? 'url(#arrowhead-blocking)' : 'url(#arrowhead)'
                    );
                },

                resetPath(path) {
                    path.classList.remove('opacity-100', 'stroke-[3px]', 'opacity-30');

                    const currentMarker = path.getAttribute('marker-end');
                    const isBlocking = currentMarker?.includes('blocking');

                    path.setAttribute('marker-end',
                        isBlocking ? 'url(#arrowhead-blocking)' : 'url(#arrowhead)'
                    );

                    if (path.dataset.truncated === 'true' && !isBlocking && path.dataset.truncatedPath) {
                        const currentPath = path.getAttribute('d');
                        const completePath = path.dataset.completePath;

                        if (currentPath === completePath) {
                            path.setAttribute('d', path.dataset.truncatedPath);
                            path.removeAttribute('marker-end');
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
                updating: false,
                eventHandlers: null,

                highlightTask(taskId, highlight) {
                    document.dispatchEvent(new CustomEvent(
                        highlight ? 'task-hover' : 'task-unhover',
                        { detail: { taskId } }
                    ));
                },

                handleTaskEdit(taskId) {
                    this.updating = true;
                    this.$wire.editTask(taskId)
                        .finally(() => this.updating = false);
                },

                startResize(side, event, taskStartDate, taskEndDate, viewStartDate, viewEndDate) {
                    if (this.updating) return;

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
                        this.updating = true;

                        const finalStartIndex = side === 'left' ? this.startIndex : (actualStartIndex < 0 ? actualStartIndex : this.startIndex);
                        const finalEndIndex = side === 'left' ? (actualEndIndex > {{ count($this->days) - 1 }} ? actualEndIndex : this.endIndex) : this.endIndex;

                        this.$wire.call('updateTaskDates', this.taskId, finalStartIndex, finalEndIndex)
                            .then(() => this.updateComplete())
                            .catch(() => this.updateComplete());
                    }
                    this.cleanup();
                },

                updateComplete() {
                    this.updating = false;
                    window.dispatchEvent(new CustomEvent('task-resize-complete', {
                        detail: { taskId: this.taskId, updateAll: true }
                    }));
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
