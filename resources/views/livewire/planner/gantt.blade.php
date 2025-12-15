<div>
    <div
        class="h-screen overflow-auto isolate transition-all duration-500"
        x-data="ganttChart()"
        x-init="init()"
        x-bind:class="loaded ? 'opacity-100 blur-none' : 'opacity-0 blur-sm'"
        style="scroll-left: {{ max(0, $this->days->search(fn($d) => $d->isSameDay($this->today)) * 100) }}px;"
        >
        
    <div class="min-w-max relative">
            <!-- Date header -->
            <div
                class="sticky top-0 grid bg-white border-b border-zinc-200 z-40 h-[49px]"
                style="grid-template-columns: repeat({{ count($this->days) }}, 100px);"
            >
                @foreach ($this->days as $day)
                    <div
                        class="p-2 text-left text-xs border-r border-zinc-300
                            {{ $day->isWeekend() ? 'bg-zinc-200' : 'bg-zinc-100' }}
                            {{ $day->isSameDay($this->today) ? 'font-bold text-accent relative before:content-[\'\'] before:absolute before:inset-0 before:bg-accent/20 before:pointer-events-none' : '' }}"
                        @if($day->isSameDay($this->today)) data-today @endif
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
                @endphp

                <!-- Project header -->
                <div class="sticky top-[49px] relative bg-zinc-200 border-b border-zinc-200 z-35" wire:key="project-{{ $project->id }}-header">
                    <!-- Background grid -->
                    <div
                        class="absolute inset-0 grid bg-white"
                        style="grid-template-columns: repeat({{ count($this->days) }}, 100px);"
                    >
                        @foreach ($this->days as $day)
                            <div
                                class="border-r border-zinc-50 h-full
                                    {{ $day->isWeekend() ? 'bg-zinc-200' : 'bg-zinc-100' }}
                                    {{ $day->isSameDay($this->today) ? 'relative before:content-[\'\'] before:absolute before:inset-0 before:bg-accent/20 before:pointer-events-none' : '' }}"
                                @if($day->isSameDay($this->today)) data-today @endif
                            ></div>
                        @endforeach
                    </div>

                    <!-- Project name section -->
                    <div class="relative flex items-center border-t border-zinc-300 mb-1">
                        <div class="sticky left-0 px-2 text-sm flex items-center z-35">
                            <div class="flex items-center gap-x-4 flex-shrink-0">
                                <div class="flex flex-col">
                                    <div class="flex items-center justify-center gap-2">
                                        <a
                                            href="{{ route('projects.show', $project->id) }}"
                                            target="_blank"
                                            class="font-semibold text-zinc-800 hover:text-accent transition-colors"
                                        >
                                            {{ $project->name }}
                                        </a>
                                        <div class="flex items-center mt-2 gap-2">
                                            <flux:badge 
                                                size="xs" 
                                                color="{{ $project->latestStatus->badge_color }}"
                                                > 
                                                {{ $project->latestStatus->title }}
                                            </flux:badge>
                                            
                                            @if($projectData['lastTaskInfo'])
                                                <flux:badge
                                                    size="xs"
                                                    color="orange"
                                                    icon="chevron-left"
                                                >        
                                                    @if($projectData['lastTaskInfo']['was_future'])
                                                        {{-- {{ $projectData['lastTaskInfo']['days_ago'] }} days until last task --}}
                                                    @else
                                                        {{ $projectData['lastTaskInfo']['days_ago'] }} days since last task
                                                    @endif
                                                </flux:badge>
                                            @endif                                            
                                            
                                            <flux:button
                                                wire:click="openAddTask({{ $project->id }})"
                                                variant="filled"
                                                size="sm"
                                                icon="plus"
                                                square
                                            />

                                            <!-- Unscheduled Tasks -->
                                            @if($projectData['unscheduledTasks']->count() > 0)
                                                @foreach($projectData['unscheduledTasks'] as $unscheduledTask)
                                                    <flux:badge
                                                        as="button"
                                                        size="xs"
                                                        :color="data_get($unscheduledTask->type_ui, 'flux')"
                                                        wire:click="editTask({{ $unscheduledTask->id }})"
                                                        wire:loading.class="opacity-50 pointer-events-none"
                                                        wire:loading.attr="disabled"
                                                        wire:target="editTask({{ $unscheduledTask->id }})"
                                                    >
                                                        {{ $unscheduledTask->title }}
                                                    </flux:badge>
                                                @endforeach
                                            @endif
                                        </div>
                                      
                                    </div>
                                    <span class="text-xs italic text-zinc-500 -mt-2">{{ $project->client->name }}</span>
                                </div>
                            </div>
                        </div>                        
                    </div>
                </div>

                <!-- Tasks timeline -->
                @if(count($projectData['taskRows']) > 0)
                <div
                    class="relative border-b border-zinc-200 py-1"
                    wire:key="project-{{ $project->id }}-timeline"
                >
                    <!-- Single container-level grid overlay (keeps grid continuous with padding) -->
                    <div
                        class="absolute inset-0 grid pointer-events-none select-none z-0"
                        style="grid-template-columns: repeat({{ count($this->days) }}, 100px);"
                        aria-hidden="true"
                    >
                        @foreach ($this->days as $day)
                            <div
                                class="border-r border-zinc-200 h-full
                                    {{ $day->isWeekend() ? 'bg-zinc-100' : 'bg-zinc-50' }}
                                    {{ $day->isSameDay($this->today) ? 'relative before:content-[\'\'] before:absolute before:inset-0 before:bg-accent/20 before:pointer-events-none' : '' }}"
                                @if($day->isSameDay($this->today)) data-today @endif
                            ></div>
                        @endforeach
                    </div>

                    <!-- Actual rows stack -->
                    <div class="relative z-10 flex flex-col divide-y divide-zinc-200">
                    @foreach ($projectData['taskRows'] as $rowIndex => $taskRow)
                        <div class="relative z-10 h-[60px]" wire:key="project-{{ $project->id }}-row-{{ $rowIndex }}">

                            <!-- Task bars for this row -->
                            @foreach ($taskRow as $taskIndex => $taskData)
                                @php
                                    $task = $taskData['task'];
                                    $taskStartDate = $taskData['taskStartDate'];
                                    $taskEndDate = $taskData['taskEndDate'];
                                    $renderStartDayIndex = $taskData['renderStartDayIndex'];
                                    $renderEndDayIndex = $taskData['renderEndDayIndex'];
                                    $leftPosition = $taskData['leftPosition'];
                                    $barWidth = $taskData['barWidth'];
                                @endphp

                                @php
                                    $isTruncatedLeft = $taskStartDate->isBefore($this->days->first());
                                    $taskEndPosition = $leftPosition + $barWidth;
                                    $isTruncatedRight = $taskEndDate->isAfter($this->days->last());
                                @endphp

                                <div
                                    wire:key="task-{{ $task->id }}"
                                    class="task-bar group absolute inset-y-0.5 bg-white/50 border-opacity-30 cursor-default
                                        {{ data_get($task->type_ui, 'border') }}
                                        text-md flex items-center shadow select-none overflow-visible transition-all duration-200
                                        {{ $isTruncatedLeft ? 'border-r border-t border-b rounded-r' : '' }}
                                        {{ !$isTruncatedLeft && $taskEndDate->isAfter($this->days->last()) ? 'border-l border-t border-b rounded-l' : '' }}
                                        {{ !$isTruncatedLeft && !$taskEndDate->isAfter($this->days->last()) ? 'border rounded' : '' }}"
                                    {{-- @php $leftBorderPx = $isTruncatedLeft ? 0 : 0; @endphp --}}
                                    style="left: {{ $leftPosition + 2 }}px; width: {{ $barWidth - 4 }}px; --bar-left: {{ $leftPosition + 2 }}px; --bar-grid-left: {{ $leftPosition }}px; --left-border: 0px;"
                                    data-task-id="{{ $task->id }}"
                                    data-debug-coords="left:{{ $leftPosition + 2 }},insetY:4px"
                                    x-data="taskResize({{ $task->id }}, {{ $renderStartDayIndex }}, {{ $renderEndDayIndex }})"
                                    x-bind:class="{
                                            'shadow-lg z-15': resizing,
                                            '!border-opacity-100': resizing,
                                            'is-resizing': resizing,
                                            'is-updating': updating,
                                            'cursor-grabbing': resizing,
                                            'hover:border-opacity-100 hover:shadow-md': !resizing && !updating
                                    }"
                                >
                                    <!-- Weekend overlay aligned to global grid via gradient -->
                                    <div class="weekend-overlay absolute inset-0 pointer-events-none z-5 overflow-hidden"
                                        x-bind:class="{ 'hidden': resizing || updating }">
                                        @for ($i = $renderStartDayIndex; $i <= $renderEndDayIndex; $i++)
                                            @php
                                                $day = $this->days[$i];
                                                $isSat = $day->isSaturday();
                                                $isSun = $day->isSunday();
                                                $show = ($isSat && !($task->options->saturday ?? false)) || ($isSun && !($task->options->sunday ?? false));
                                                $left = (($i - $renderStartDayIndex) * 100) - 3;
                                            @endphp
                                            @if($show)
                                                <div class="absolute top-0 h-full bg-zinc-400/20"
                                                    style="left: {{ $left }}px; width: 100px;"></div>
                                            @endif
                                        @endfor
                                    </div>

                                    <!-- Left resize handle -->
                                    @if(!$taskStartDate->isBefore($this->days->first()))
                                        <div
                                            class="resize-handle w-2 touch:w-4 h-full absolute top-0 left-0
                                                {{ data_get($task->type_ui, 'bg') }}
                                                opacity-30 rounded-l z-30 transition-all duration-200
                                                before:content-[''] before:absolute before:inset-y-0 before:-left-2 before:w-6 before:touch:w-8 before:bg-transparent"
                                            x-bind:class="{
                                                '{{ 'opacity-100 ' . data_get($task->type_ui, 'bg_strong') }}': resizing,
                                                'cursor-ew-resize': true,
                                                'cursor-not-allowed opacity-20 pointer-events-none': updating,
                                                    'group-hover:opacity-50 hover:!opacity-100 {{ data_get($task->type_ui, 'hover_bg_strong') }}': !updating
                                            }"
                                            @mousedown="startResize('left', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                            @touchstart="startResize('left', $event, '{{ $taskStartDate->format('Y-m-d') }}', '{{ $taskEndDate->format('Y-m-d') }}', '{{ $this->days->first()->format('Y-m-d') }}', '{{ $this->days->last()->format('Y-m-d') }}')"
                                        ></div>
                                    @endif

                                    <!-- Task content -->
                                    <div class="flex-1 relative mx-2 h-full overflow-visible z-20">
                                        <div
                                            class="select-none bg-transparent border-0 shadow-none px-2 py-0.5 my-0 h-full
                                                rounded transition-colors duration-200 min-w-0"
                                            style="{{ $isTruncatedLeft ? 'margin-left: -' . ($leftPosition + 4) . 'px; max-width: ' . min(200, $taskEndPosition) . 'px;' : 'max-width: ' . min(200, $barWidth - 8) . 'px;' }}"
                                            wire:loading.class="opacity-50 pointer-events-none"
                                            wire:loading.attr="disabled"
                                            wire:target="editTask({{ $task->id }})"
                                            x-data="{ 
                                                shouldStickRight: false,
                                                isInitialized: false,
                                                observer: null
                                            }"
                                            x-init="
                                                const scrollContainer = $el.closest('.overflow-auto');
                                                const taskBar = $el.closest('.task-bar');
                                                
                                                const checkAndApplyPosition = () => {
                                                    if (!taskBar || !scrollContainer) return;
                                                    
                                                    const scrollContainerRect = scrollContainer.getBoundingClientRect();
                                                    const taskBarRect = taskBar.getBoundingClientRect();
                                                    
                                                    // Calculate how much of the task is actually visible
                                                    const visibleLeft = Math.max(taskBarRect.left, scrollContainerRect.left);
                                                    const visibleRight = Math.min(taskBarRect.right, scrollContainerRect.right);
                                                    const visibleWidth = Math.max(0, visibleRight - visibleLeft);
                                                    
                                                    // Check if this is a single-day task (~100px width)
                                                    const taskWidth = taskBarRect.width;
                                                    const isSingleDay = taskWidth <= 100;
                                                    // Truncation and full-visibility checks with small tolerance
                                                    const tol = 1; // px
                                                    const isRightTruncated = taskBarRect.right > scrollContainerRect.right + tol;
                                                    const isLeftTruncated = taskBarRect.left < scrollContainerRect.left - tol;
                                                    const isFullyVisible = !isLeftTruncated && !isRightTruncated;
                                                    
                                                    // For single-day tasks: right-align if on first rendered day (left edge <= 2px) AND not right truncated
                                                    let nextStickRight;
                                                    if (isSingleDay) {
                                                        const taskLeft = taskBarRect.left - scrollContainerRect.left;
                                                        nextStickRight = taskLeft <= 2 && !isRightTruncated;
                                                        
                                                        // Apply extra padding to parent container for single-day first-day tasks
                                                        const parentContainer = $el.closest('.flex-1.relative.mx-2');
                                                        if (parentContainer && nextStickRight) {
                                                            parentContainer.style.setProperty('padding', '0 4px 0 2px', 'important');
                                                        } else if (parentContainer) {
                                                            parentContainer.style.removeProperty('padding');
                                                        }
                                                    } else {
                                                        // For multi-day tasks
                                                        if (isFullyVisible) {
                                                            // Keep left aligned when fully visible (e.g., width ~200px fully in view)
                                                            nextStickRight = false;
                                                        } else {
                                                            // If the visible portion is narrow and not right truncated, prefer right alignment
                                                            nextStickRight = visibleWidth < 200 && visibleWidth > 0 && !isRightTruncated;
                                                        }
                                                    }
                                                    
                                                    // If alignment is changing, perform FLIP animation
                                                    const changing = nextStickRight !== shouldStickRight;
                                                    let firstRect;
                                                    if (changing) {
                                                        firstRect = $el.getBoundingClientRect();
                                                    }

                                                    // Apply positioning via existing classes
                                                    if (nextStickRight) {
                                                        $el.classList.remove('sticky', 'left-0');
                                                        $el.classList.add('absolute', 'right-0');
                                                        $el.style.setProperty('margin', '0 8px 0 0.5rem', 'important');
                                                    } else {
                                                        $el.classList.remove('absolute', 'right-0');
                                                        $el.classList.add('sticky', 'left-0');
                                                        $el.style.removeProperty('margin');
                                                    }

                                                    if (changing) {
                                                        const lastRect = $el.getBoundingClientRect();
                                                        const dx = firstRect.left - lastRect.left;
                                                        // Do FLIP: move from old to new smoothly
                                                        $el.style.transition = 'none';
                                                        $el.style.transform = `translateX(${dx}px)`;
                                                        // Force reflow
                                                        void $el.offsetWidth;
                                                        $el.style.transition = 'transform 100ms ease';
                                                        $el.style.transform = 'translateX(0)';
                                                        const cleanup = () => {
                                                            $el.style.transition = '';
                                                            $el.removeEventListener('transitionend', cleanup);
                                                        };
                                                        $el.addEventListener('transitionend', cleanup);
                                                    }

                                                    shouldStickRight = nextStickRight;
                                                    
                                                    isInitialized = true;
                                                };
                                                
                                                // Create MutationObserver to watch for class changes
                                                observer = new MutationObserver(() => {
                                                    // Re-apply positioning if classes were reset
                                                    if (isInitialized) {
                                                        const hasCorrectClasses = shouldStickRight ? 
                                                            $el.classList.contains('absolute') && $el.classList.contains('right-0') :
                                                            $el.classList.contains('sticky') && $el.classList.contains('left-0');
                                                        
                                                        if (!hasCorrectClasses) {
                                                            if (shouldStickRight) {
                                                                $el.classList.remove('sticky', 'left-0');
                                                                $el.classList.add('absolute', 'right-0');
                                                                $el.style.setProperty('margin', '0 8px 0 0.5rem', 'important');
                                                                
                                                                // Check if this is a single-day task on first day for extra parent padding
                                                                const taskBarRect = taskBar.getBoundingClientRect();
                                                                const scrollContainerRect = scrollContainer.getBoundingClientRect();
                                                                const taskWidth = taskBarRect.width;
                                                                const taskLeft = taskBarRect.left - scrollContainerRect.left;
                                                                const isSingleDay = taskWidth <= 100;
                                                                const isRightTruncated = taskBarRect.right > scrollContainerRect.right;
                                                                const parentContainer = $el.closest('.flex-1.relative.mx-2');
                                                                if (parentContainer && isSingleDay && taskLeft <= 2 && !isRightTruncated) {
                                                                    parentContainer.style.setProperty('padding', '0 4px 0 2px', 'important');
                                                                } else if (parentContainer) {
                                                                    parentContainer.style.removeProperty('padding');
                                                                }
                                                            } else {
                                                                $el.classList.remove('absolute', 'right-0');
                                                                $el.classList.add('sticky', 'left-0');
                                                                $el.style.removeProperty('margin');
                                                                
                                                                // Remove parent padding when not right-aligned
                                                                const parentContainer = $el.closest('.flex-1.relative.mx-2');
                                                                if (parentContainer) {
                                                                    parentContainer.style.removeProperty('padding');
                                                                }
                                                            }
                                                        }
                                                    }
                                                });
                                                
                                                // Start observing
                                                observer.observe($el, { attributes: true, attributeFilter: ['class'] });
                                                
                                                // Listen for global position update events
                                                $el.addEventListener('updatePosition', checkAndApplyPosition);
                                                
                                                // Also listen for scroll events directly for immediate response
                                                scrollContainer.addEventListener('scroll', checkAndApplyPosition);
                                                
                                                // Initial setup
                                                checkAndApplyPosition();
                                                $nextTick(() => checkAndApplyPosition());
                                                
                                                $el._cleanup = () => {
                                                    if (observer) observer.disconnect();
                                                    $el.removeEventListener('updatePosition', checkAndApplyPosition);
                                                    scrollContainer.removeEventListener('scroll', checkAndApplyPosition);
                                                };
                                            "
                                            x-destroy="if ($el._cleanup) $el._cleanup()"
                                        >
                                            <div class="flex flex-col gap-0.5 h-full min-w-0">
                                                <div class="flex items-center min-w-0">
                                                    <div class="flex flex-col flex-1 min-w-0">
                                                        <div class="min-w-0">
                                                            <flux:heading
                                                                class="leading-tight min-w-0 whitespace-nowrap"
                                                                title="{{ $task->title }}"
                                                                x-bind:class="{ 'text-right': shouldStickRight }"
                                                                x-bind:style="shouldStickRight ? 'direction: rtl; text-align: right;' : ''"
                                                            >
                                                                <span 
                                                                    class="cursor-pointer block min-w-0 whitespace-nowrap"
                                                                    wire:click="editTask({{ $task->id }})"
                                                                >
                                                                    {{ $task->title }}
                                                                </span>
                                                            </flux:heading>
                                                        </div>

                                                        <div class="flex items-center gap-1 min-h-[18px] min-w-0 whitespace-nowrap"
                                                             x-bind:class="{ 'flex-row-reverse': shouldStickRight }">
                                                            @if($task->users->count() > 0)
                                                                @if($task->users->count() === 1)
                                                                    @foreach($task->users as $user)
                                                                        <div class="border-2 border-white dark:border-zinc-800 rounded-sm shrink-0">
                                                                            <flux:avatar
                                                                                size="xs"
                                                                                name="{{ $user->full_name }}"
                                                                                color="auto"
                                                                                color:seed="{{ $user->id }}"
                                                                                title="{{ $user->full_name }}"
                                                                            />
                                                                        </div>
                                                                    @endforeach
                                                                @else
                                                                    <flux:avatar.group class="border-2 border-white dark:border-zinc-800 rounded-sm shrink-0">
                                                                        @foreach($task->users as $user)
                                                                            <flux:avatar
                                                                                size="xs"
                                                                                name="{{ $user->full_name }}"
                                                                                color="auto"
                                                                                color:seed="{{ $user->id }}"
                                                                                title="{{ $user->full_name }}"
                                                                            />
                                                                        @endforeach
                                                                    </flux:avatar.group>
                                                                @endif
                                                            @endif
                                                            @if($task->vendor)
                                                                <div class="border-2 border-white dark:border-zinc-800 rounded-sm shrink-0" title="{{ $task->vendor->name }}">
                                                                    <flux:avatar
                                                                        size="xs"
                                                                        name="{{ $task->vendor->name }}"
                                                                        color="auto"
                                                                        color:seed="{{ $task->vendor->id }}"
                                                                    />
                                                                </div>
                                                                <div class="flex-1 min-w-0">
                                                                    <flux:text size="xs" class="block min-w-0 whitespace-nowrap" 
                                                                               title="{{ $task->vendor->name }}"
                                                                               x-bind:class="{ 'text-right': shouldStickRight }"
                                                                               x-bind:style="shouldStickRight ? 'direction: rtl; text-align: right;' : ''">
                                                                        {{ $task->vendor->name }}
                                                                    </flux:text>
                                                                </div>
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
                                            class="resize-handle w-2 touch:w-4 h-full absolute top-0 right-0
                                                {{ data_get($task->type_ui, 'bg') }}
                                                opacity-30 rounded-r z-30 transition-all duration-200
                                                before:content-[''] before:absolute before:inset-y-0 before:-right-2 before:w-6 before:touch:w-8 before:bg-transparent"
                                            x-bind:class="{
                                                '{{ 'opacity-100 ' . data_get($task->type_ui, 'bg_strong') }}': resizing,
                                                'cursor-ew-resize': true,
                                                'cursor-not-allowed opacity-20 pointer-events-none': updating,
                                                    'group-hover:opacity-50 hover:!opacity-100 {{ data_get($task->type_ui, 'hover_bg_strong') }}': !updating
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
                </div>
                @endif
            @endforeach
        </div>
    </div>

    <livewire:tasks.task-create :projects="$this->projects" />

    <script>
    // Force-hide weekend overlay while resizing to avoid any flicker or misalignment
        const styleEl = document.createElement('style');
        styleEl.textContent = `
        .task-bar.is-resizing .weekend-overlay{display:none !important;}
        
        /* Mobile touch scrolling improvements */
        @media (max-width: 640px) {
            .overflow-auto {
                -webkit-overflow-scrolling: touch;
                scroll-behavior: auto;
                touch-action: auto; /* Allow native scrolling by default */
                overscroll-behavior: contain;
            }
        }
        `;
        document.head.appendChild(styleEl);

        function ganttChart() {
            return {
                // Fade-in state
                loaded: false,
                // Axis-locked scrolling state
                scrollContainer: null,
                isScrolling: false,
                scrollDirection: null, // 'horizontal', 'vertical', or null
                startX: 0,
                startY: 0,
                lastX: 0,
                lastY: 0,
                lastScrollLeft: 0,
                lastScrollTop: 0,
                threshold: 15, // pixels to move before determining direction
                
                // Removed infinite scroll state
                todayPosition: 0,

                init() {
                    this.$nextTick(() => {
                        // Ensure we start scrolled to today
                        this.scrollToToday();
                        
                        this.setupAxisLocking();
                        this.setupGlobalPositionUpdate();
                        // Infinite scrolling removed
                        
                        // Calculate initial today position for tracking
                        this.calculateTodayPosition();
                        
                        // Mark as loaded on next frame to allow fade-in after layout settles
                        requestAnimationFrame(() => {
                            this.loaded = true;
                            // One more pass to align task content after fade-in
                            window.updateAllTaskPositions?.();
                        });
                        // Infinite scrolling removed
                    });
                },


                calculateTodayPosition() {
                    const todayElement = document.querySelector('[data-today]');
                    if (todayElement) {
                        const parentGrid = todayElement.closest('.grid');
                        if (parentGrid) {
                            const todayIndex = Array.from(parentGrid.children).indexOf(todayElement);
                            this.todayPosition = todayIndex * 100; // 100px per day
                        }
                    }
                },



                setupGlobalPositionUpdate() {
                    // Create global function to update all task positions
                    window.updateAllTaskPositions = () => {
                        const taskContents = document.querySelectorAll('[x-data*="shouldStickRight"]');
                        taskContents.forEach(el => {
                            const event = new CustomEvent('updatePosition');
                            el.dispatchEvent(event);
                        });
                    };

                    // Trigger position updates on various events
                    window.addEventListener('resize', window.updateAllTaskPositions);
                    
                    // Add debounced scroll update for global coordination
                    let scrollTimeout;
                    this.scrollContainer = this.$el; // The scroll container is this.$el itself
                    if (this.scrollContainer) {
                        this.scrollContainer.addEventListener('scroll', () => {
                            // Immediate update for all tasks during scroll
                            window.updateAllTaskPositions();
                            
                            // Also debounced for any cleanup
                            clearTimeout(scrollTimeout);
                            scrollTimeout = setTimeout(window.updateAllTaskPositions, 100);
                        });
                    }
                },

                setupAxisLocking() {
                    this.scrollContainer = this.$el; // The scroll container is this.$el itself
                    if (!this.scrollContainer) return;

                    // Only add event listeners on mobile
                    if ('ontouchstart' in window) {
                        this.scrollContainer.addEventListener('touchstart', this.handleTouchStart.bind(this), { passive: true });
                        this.scrollContainer.addEventListener('touchmove', this.handleTouchMove.bind(this), { passive: false });
                        this.scrollContainer.addEventListener('touchend', this.handleTouchEnd.bind(this), { passive: true });
                        this.scrollContainer.addEventListener('touchcancel', this.handleTouchEnd.bind(this), { passive: true });
                    }
                },

                handleTouchStart(e) {
                    if (e.touches.length !== 1) return;
                    
                    const touch = e.touches[0];
                    this.startX = touch.clientX;
                    this.startY = touch.clientY;
                    this.lastX = touch.clientX;
                    this.lastY = touch.clientY;
                    this.lastScrollLeft = this.scrollContainer.scrollLeft;
                    this.lastScrollTop = this.scrollContainer.scrollTop;
                    this.isScrolling = false;
                    this.scrollDirection = null;
                },

                handleTouchMove(e) {
                    if (e.touches.length !== 1) return;

                    const touch = e.touches[0];
                    const deltaX = Math.abs(touch.clientX - this.startX);
                    const deltaY = Math.abs(touch.clientY - this.startY);

                    // Determine scroll direction if not already set
                    if (!this.isScrolling && (deltaX > this.threshold || deltaY > this.threshold)) {
                        this.isScrolling = true;
                        
                        // Determine primary direction
                        if (deltaX > deltaY) {
                            this.scrollDirection = 'horizontal';
                        } else {
                            this.scrollDirection = 'vertical';
                        }
                    }

                    // Once direction is determined, take full control
                    if (this.isScrolling && this.scrollDirection) {
                        e.preventDefault();

                        const moveX = touch.clientX - this.lastX;
                        const moveY = touch.clientY - this.lastY;

                        if (this.scrollDirection === 'horizontal') {
                            // Only allow horizontal scrolling
                            this.scrollContainer.scrollLeft = this.lastScrollLeft - (touch.clientX - this.startX);
                            // Lock vertical position
                            this.scrollContainer.scrollTop = this.lastScrollTop;
                        } else if (this.scrollDirection === 'vertical') {
                            // Only allow vertical scrolling  
                            this.scrollContainer.scrollTop = this.lastScrollTop - (touch.clientY - this.startY);
                            // Lock horizontal position
                            this.scrollContainer.scrollLeft = this.lastScrollLeft;
                        }

                        this.lastX = touch.clientX;
                        this.lastY = touch.clientY;
                    }
                },

                handleTouchEnd(e) {
                    this.isScrolling = false;
                    this.scrollDirection = null;
                    this.startX = 0;
                    this.startY = 0;
                    this.lastX = 0;
                    this.lastY = 0;
                    this.lastScrollLeft = 0;
                    this.lastScrollTop = 0;
                },

                scrollToToday() {
                    // Prefer the date header grid for accurate day index
                    const headerGrid = this.$el.querySelector('.sticky.top-0.grid');
                    let todayElement = headerGrid?.querySelector('[data-today]');
                    if (!todayElement) {
                        // Fallback to first [data-today] anywhere inside the component
                        todayElement = this.$el.querySelector('[data-today]');
                    }
                    if (!todayElement) { return; }

                    const dayWidth = 100;
                    const parent = todayElement.parentElement;
                    const children = parent ? Array.from(parent.children) : [];
                    const todayIndex = children.indexOf(todayElement);
                    if (todayIndex < 0) { return; }

                    // Target scroll to show today as first column (leftmost)
                    let targetScroll = todayIndex * dayWidth;

                    // Ensure scrollContainer is set
                    if (!this.scrollContainer) { this.scrollContainer = this.$el; }
                    const sc = this.scrollContainer;
                    if (!sc) { return; }

                    // Clamp within scrollable range
                    const maxScroll = Math.max(0, sc.scrollWidth - sc.clientWidth);
                    targetScroll = Math.min(maxScroll, Math.max(0, targetScroll));

                    // Apply scroll with double rAF to ensure layout is ready
                    sc.scrollLeft = targetScroll;
                    requestAnimationFrame(() => {
                        sc.scrollLeft = targetScroll;
                        requestAnimationFrame(() => {
                            sc.scrollLeft = targetScroll;
                        });
                    });

                    this.calculateTodayPosition();
                }
            }
        } 

        function taskResize(taskId, startIndex, endIndex) {
            return {
                taskId,
                dayWidth: 100,
                startIndex,
                endIndex,
                resizing: false,
                updating: false,
                moved: false,
                eventHandlers: null,
                taskBarEl: null,
                overlayEl: null,
                _updatePollId: null,

                // No-op task hover: dependency highlighting removed

                startResize(side, event, taskStartDate, taskEndDate, viewStartDate, viewEndDate) {
                    this.resizing = true;
                    this.moved = false;
                    this._prevStartIndex = this.startIndex;
                    this._prevEndIndex = this.endIndex;
                    event.stopPropagation();
                    event.preventDefault();

                    this.disableSelection();

                    const taskBar = event.target.parentElement;
                    this.taskBarEl = taskBar;
                    this.overlayEl = taskBar.querySelector('.weekend-overlay');
                    if (this.overlayEl) {
                        // Hide immediately and keep it hidden until Livewire re-renders
                        this.overlayEl.style.display = 'none';
                    }
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
                            if (this.startIndex !== newIndex) {
                                this.moved = true;
                                this.startIndex = newIndex;
                            }
                            const displayEndIndex = maxIndex;
                            const newLeft = Math.max(0, this.startIndex) * this.dayWidth + 2;
                            const newWidth = (displayEndIndex - Math.max(0, this.startIndex) + 1) * this.dayWidth - 4;
                            const newGridLeft = Math.max(0, this.startIndex) * this.dayWidth; // without the 2px visual offset

                            Object.assign(taskBar.style, {
                                left: newLeft + 'px',
                                width: newWidth + 'px'
                            });
                            taskBar.style.setProperty('--bar-left', newLeft + 'px');
                            taskBar.style.setProperty('--bar-grid-left', newGridLeft + 'px');

                            // Force overlay alignment update to avoid any var propagation delay
                            const overlay = taskBar.querySelector('.weekend-overlay');
                            if (overlay) {
                                const containerEl = taskBar.closest('.overflow-auto');
                                const startDowAttr = parseInt(containerEl?.dataset?.startDow || '0', 10);
                                const dw = this.dayWidth;
                                // Compute pixel offsets for Sat(6) and Sun(0) from the first visible day
                                const satOffsetPx = ((6 - startDowAttr + 7) % 7) * dw;
                                const sunOffsetPx = ((0 - startDowAttr + 7) % 7) * dw;
                                const leftBorder = parseInt(getComputedStyle(taskBar).getPropertyValue('--left-border') || '0', 10) || 0;
                                const satPos = `calc(${satOffsetPx}px - ${newGridLeft + leftBorder}px) 0px`;
                                const sunPos = `calc(${sunOffsetPx}px - ${newGridLeft + leftBorder}px) 0px`;
                                overlay.style.backgroundPosition = `${satPos}, ${sunPos}`;
                            }
                        }
                    } else {
                        if (newIndex >= Math.max(0, actualStartIndex)) {
                            if (this.endIndex !== newIndex) {
                                this.moved = true;
                                this.endIndex = newIndex;
                            }
                            const newWidth = (this.endIndex - this.startIndex + 1) * this.dayWidth - 4;
                            taskBar.style.width = newWidth + 'px';
                        }
                    }
                },

                handleUp(e, side, actualStartIndex, actualEndIndex) {
                    if (this.resizing) {
                        const finalStartIndex = side === 'left' ? this.startIndex : (actualStartIndex < 0 ? actualStartIndex : this.startIndex);
                        const finalEndIndex = side === 'left' ? (actualEndIndex > {{ count($this->days) - 1 }} ? actualEndIndex : this.endIndex) : this.endIndex;

                        // If nothing moved, restore overlay and skip update
                        if (!this.moved) {
                            if (this.overlayEl) this.overlayEl.style.display = '';
                            this.cleanup();
                            return;
                        }

                        // If final indices are the same as original, skip update
                        if (finalStartIndex === this._prevStartIndex && finalEndIndex === this._prevEndIndex) {
                            if (this.overlayEl) this.overlayEl.style.display = '';
                            this.cleanup();
                            return;
                        }

                        // Mark only this task as updating and wait for Livewire to complete
                        this.updating = true;
                        // Fallback poll: clear updating once Livewire is not busy
                        if (!this._updatePollId) {
                            this._updatePollId = setInterval(() => {
                                const busy = this.$wire?.__instance?.effects?.busy;
                                if (!busy) {
                                    this.updating = false;
                                    clearInterval(this._updatePollId);
                                    this._updatePollId = null;
                                    // Update all task positions after resize complete
                                    if (window.updateAllTaskPositions) {
                                        window.updateAllTaskPositions();
                                    }
                                }
                            }, 100);
                        }
                        const promise = this.$wire.updateTaskDates(this.taskId, finalStartIndex, finalEndIndex);
                        if (promise && typeof promise.finally === 'function') {
                            promise.finally(() => {
                                this.updating = false;
                                if (this.overlayEl) this.overlayEl.style.display = '';
                                if (this._updatePollId) { clearInterval(this._updatePollId); this._updatePollId = null; }
                                // Update all task positions after resize complete
                                if (window.updateAllTaskPositions) {
                                    window.updateAllTaskPositions();
                                }
                            });
                        } else {
                            // Very old Livewire fallback
                            setTimeout(() => {
                                this.updating = false;
                                if (this.overlayEl) this.overlayEl.style.display = '';
                                if (this._updatePollId) { clearInterval(this._updatePollId); this._updatePollId = null; }
                                // Update all task positions after resize complete
                                if (window.updateAllTaskPositions) {
                                    window.updateAllTaskPositions();
                                }
                            }, 500);
                        }
                    }
                    // Defer cleanup a tick so `resizing` remains true until Livewire marks busy,
                    // preventing a brief window where overlay could reappear.
                    const finish = () => this.cleanup();
                    if (window.requestAnimationFrame) {
                        requestAnimationFrame(() => requestAnimationFrame(finish));
                    } else {
                        setTimeout(finish, 0);
                    }
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

                    // Prevent scrolling during touch resize
                    document.body.style.overflow = 'hidden';
                    document.body.style.touchAction = 'none';
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
                    // No imperative show/hide; Alpine + Livewire loading handle overlay visibility.
                    this.resizing = false;
                    this.eventHandlers = null;
                    this.taskBarEl = null;
                    this.overlayEl = null;
                    
                    // Restore scrolling after resize
                    document.body.style.overflow = '';
                    document.body.style.touchAction = '';
                }
            }
        }
    </script>
</div>