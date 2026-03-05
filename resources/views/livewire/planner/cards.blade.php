@php
    $firstDayIsWeekend = (bool) data_get($kanbanColumns->first(), 'isWeekend', false);
@endphp

<div class="flex flex-col h-full min-h-0">
    <div class="flex-1 flex flex-col min-h-0 overflow-hidden bg-zinc-100 dark:bg-zinc-800">
        {{-- Filter Bar - Desktop (horizontal) --}}
        <div class="hidden shrink-0 px-4 py-3 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center gap-3">
            {{-- Project Filter (Multi-select) --}}
            <flux:select
                wire:model.live="filterProjectIds"
                placeholder="All Projects"
                variant="listbox"
                searchable
                multiple
                size="sm"
                class="min-w-48"
            >
                @foreach($projects as $project)
                    <flux:select.option value="{{ $project->id }}">
                        <div class="flex items-center gap-2 w-full">
                            <span class="flex-1 min-w-0 truncate">{{ $project->short_address }}</span>
                            @if($project->latestStatus)
                                <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom" class="shrink-0">
                                    {{ $project->latestStatus->title }}
                                </flux:badge>
                            @endif
                        </div>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Status Filter (Multi-select with badges) --}}
            <flux:select
                wire:model.live="filterStatusCodes"
                placeholder="All Statuses"
                variant="listbox"
                multiple
                size="sm"
                class="min-w-40"
            >
                @foreach($this->statusOptions as $status)
                    <flux:select.option value="{{ $status['code'] }}">
                        <flux:badge size="sm" inset="top bottom" :color="$status['color']">
                            {{ $status['label'] }}
                        </flux:badge>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Vendor Filter (with avatars) --}}
            <flux:select
                wire:model.live="filterVendorId"
                placeholder="All Vendors"
                variant="listbox"
                searchable
                clearable
                size="sm"
                class="min-w-48"
            >
                @foreach($vendors as $vendor)
                    <flux:select.option value="{{ $vendor->id }}">
                        <div class="flex items-center gap-2 whitespace-nowrap">
                            <flux:avatar size="xs" name="{{ $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}" />
                            {{ $vendor->name }}
                        </div>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Team Member Filter (Multi-select with avatars) --}}
            <flux:select
                wire:model.live="filterUserIds"
                placeholder="All Team Members"
                variant="listbox"
                searchable
                multiple
                size="sm"
                class="min-w-48"
            >
                @foreach($employees as $employee)
                    <flux:select.option value="{{ $employee->id }}">
                        <div class="flex items-center gap-2 whitespace-nowrap">
                            <flux:avatar size="xs" name="{{ $employee->full_name }}" color="auto" color:seed="{{ $employee->id }}" />
                            {{ $employee->full_name }}
                        </div>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Clear Filters Button --}}
            @if($this->hasActiveFilters)
                <flux:button
                    wire:click="clearFilters"
                    variant="subtle"
                    size="sm"
                    icon="x-mark"
                >
                    Clear Filters
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Floating Filter Button --}}
    <div class="fixed top-3 right-3 z-60">
        <flux:button
            wire:click="$toggle('showMobileFilters')"
            variant="subtle"
            square
            inset="right"
            icon="funnel"
            class="bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg"
            aria-label="Filters"
        />
    </div>

    {{-- Filters Modal --}}
    <flux:modal wire:model="showMobileFilters" name="mobile-filters" class="max-w-sm">
        <div class="space-y-6">
            <flux:heading size="lg">Filters</flux:heading>

            {{-- Project Filter --}}
            <flux:field>
                <flux:label>Projects</flux:label>
                <flux:select
                    wire:model.live="filterProjectIds"
                    placeholder="All Projects"
                    variant="listbox"
                    searchable
                    multiple
                >
                    @foreach($projects as $project)
                        <flux:select.option value="{{ $project->id }}">
                            <div class="flex items-center gap-2 w-full">
                                <span class="flex-1 min-w-0 truncate">{{ $project->short_address }}</span>
                                @if($project->latestStatus)
                                    <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom" class="shrink-0">
                                        {{ $project->latestStatus->title }}
                                    </flux:badge>
                                @endif
                            </div>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Status Filter --}}
            <flux:field>
                <flux:label>Status</flux:label>
                <flux:select
                    wire:model.live="filterStatusCodes"
                    placeholder="All Statuses"
                    variant="listbox"
                    multiple
                >
                    @foreach($this->statusOptions as $status)
                        <flux:select.option value="{{ $status['code'] }}">
                            <flux:badge size="sm" inset="top bottom" :color="$status['color']">
                                {{ $status['label'] }}
                            </flux:badge>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Vendor Filter --}}
            <flux:field>
                <flux:label>Vendor</flux:label>
                <flux:select
                    wire:model.live="filterVendorId"
                    placeholder="All Vendors"
                    variant="listbox"
                    searchable
                    clearable
                >
                    @foreach($vendors as $vendor)
                        <flux:select.option value="{{ $vendor->id }}">
                            <div class="flex items-center gap-2 whitespace-nowrap">
                                <flux:avatar size="xs" name="{{ $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}" />
                                {{ $vendor->name }}
                            </div>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Team Members Filter --}}
            <flux:field>
                <flux:label>Team Members</flux:label>
                <flux:select
                    wire:model.live="filterUserIds"
                    placeholder="All Team Members"
                    variant="listbox"
                    searchable
                    multiple
                >
                    @foreach($employees as $employee)
                        <flux:select.option value="{{ $employee->id }}">
                            <div class="flex items-center gap-2 whitespace-nowrap">
                                <flux:avatar size="xs" name="{{ $employee->full_name }}" color="auto" color:seed="{{ $employee->id }}" />
                                {{ $employee->full_name }}
                            </div>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <div class="flex gap-2 pt-2">
                @if($this->hasActiveFilters)
                    <flux:button wire:click="clearFilters" variant="subtle" class="flex-1">
                        Clear All
                    </flux:button>
                @endif
                <flux:button wire:click="$set('showMobileFilters', false)" variant="primary" class="flex-1">
                    Done
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Planner Cards - 14 Day Kanban View -->
    <div
        x-data="plannerScroll()"
        x-init="init()"
        class="relative flex-1 min-h-0 flex flex-col bg-zinc-100 dark:bg-zinc-800"
    >
        {{-- Load Previous Days Button (left edge overlay) --}}
        <div
            x-show="atLeftEdge"
            x-cloak
            class="absolute left-0 top-0 bottom-0 z-20 flex items-center pl-2 pointer-events-none"
        >
            <flux:button
                x-on:click="prepareForLoad('start'); $wire.loadPreviousDays()"
                wire:loading.attr="disabled"
                wire:target="loadPreviousDays"
                variant="filled"
                size="sm"
                icon="chevron-left"
                class="shadow-lg pointer-events-auto"
            >
                Previous
            </flux:button>
        </div>

        {{-- Load Future Days Button (right edge overlay) --}}
        <div
            x-show="atRightEdge"
            x-cloak
            class="absolute right-0 top-0 bottom-0 z-20 flex items-center pr-2 pointer-events-none"
        >
            <flux:button
                x-on:click="prepareForLoad('end'); $wire.loadFutureDays()"
                wire:loading.attr="disabled"
                wire:target="loadFutureDays"
                variant="filled"
                size="sm"
                icon-trailing="chevron-right"
                class="shadow-lg pointer-events-auto"
            >
                Next
            </flux:button>
        </div>

        {{-- Main scrollable area - x-cloak hides via CSS before Alpine loads, x-show + transition reveals after opacity classes are applied --}}
        <div
            x-ref="scrollContainer"
            @scroll.passive="onScroll($event)"
            x-cloak
            x-show="ready"
            x-transition:enter="transition-opacity duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="flex-1 min-h-0 overflow-x-scroll overflow-y-auto"
        >
            @php
                $projectCount = $kanbanColumns->first()?->columns->count() ?? 0;
            @endphp
            @php
                // Build a grid that can have a 16px spacer between most days,
                // but *no* spacer between Saturday and Sunday.
                $dayColumnIndices = [];
                $spacerAfterDayColumnIndices = [];
                $gridTemplateColumns = 'auto ';

                $currentGridCol = 2; // col 1 is the left spacer
                $totalDays = $kanbanColumns->count();

                foreach ($kanbanColumns as $index => $dayData) {
                    $dayColumnIndices[$index] = $currentGridCol;
                    $gridTemplateColumns .= 'auto ';
                    $currentGridCol++;

                    $isLast = ($index === $totalDays - 1);
                    // Always add a spacer after each day (except the last) so Sat/Sun have spacing,
                    // while weekend background can still span across the spacer.
                    $addSpacerAfterThisDay = ! $isLast;

                    if ($addSpacerAfterThisDay) {
                        $spacerAfterDayColumnIndices[$index] = $currentGridCol;
                        $gridTemplateColumns .= '1rem '; // matches Tailwind w-4
                        $currentGridCol++;
                    }
                }

                $gridTemplateColumns .= 'auto'; // right spacer

                // Build contiguous weekend runs (typically Sat+Sun)
                $weekendRuns = [];
                $runStart = null;
                foreach ($kanbanColumns as $index => $dayData) {
                    if ($dayData->isWeekend) {
                        if ($runStart === null) {
                            $runStart = $index;
                        }
                    } else {
                        if ($runStart !== null) {
                            $weekendRuns[] = [$runStart, $index - 1];
                            $runStart = null;
                        }
                    }
                }
                if ($runStart !== null) {
                    $weekendRuns[] = [$runStart, $totalDays - 1];
                }
            @endphp

            <div class="grid min-w-max" style="grid-template-columns: {{ $gridTemplateColumns }}; grid-template-rows: auto repeat({{ $projectCount }}, auto);">
            {{-- Left spacer --}}
            <div class="w-4 shrink-0 {{ $firstDayIsWeekend ? 'bg-zinc-200 dark:bg-zinc-700' : '' }}" style="grid-column: 1; grid-row: 1 / -1;"></div>

            {{-- Weekend background blocks (full height, behind content) --}}
            @foreach ($weekendRuns as [$startIndex, $endIndex])
                @php
                    $startCol = $dayColumnIndices[$startIndex];
                    $endColExclusive = $dayColumnIndices[$endIndex] + 1;

                    // If weekend starts at first day, extend background to include left spacer (col 1)
                    if ($startIndex === 0) {
                        $startCol = 1;
                    }

                    // Optional half-width extensions into adjacent spacer columns,
                    // to match the "bleed" effect from the original layout.
                    $leftSpacerCol = null;
                    if ($startIndex > 0 && array_key_exists($startIndex - 1, $spacerAfterDayColumnIndices)) {
                        $leftSpacerCol = $spacerAfterDayColumnIndices[$startIndex - 1];
                    }
                    $rightSpacerCol = null;
                    if (array_key_exists($endIndex, $spacerAfterDayColumnIndices)) {
                        $rightSpacerCol = $spacerAfterDayColumnIndices[$endIndex];
                    }
                @endphp

                @if ($leftSpacerCol)
                    <div
                        aria-hidden="true"
                        class="pointer-events-none z-0 relative"
                        style="grid-column: {{ $leftSpacerCol }}; grid-row: 1 / -1;"
                    >
                        <div class="absolute inset-y-0 right-0 w-1/2 bg-zinc-200 dark:bg-zinc-700"></div>
                    </div>
                @endif

                <div
                    aria-hidden="true"
                    class="bg-zinc-200 dark:bg-zinc-700 pointer-events-none z-0"
                    style="grid-column: {{ $startCol }} / {{ $endColExclusive }}; grid-row: 1 / -1;"
                ></div>

                @if ($rightSpacerCol)
                    <div
                        aria-hidden="true"
                        class="pointer-events-none z-0 relative"
                        style="grid-column: {{ $rightSpacerCol }}; grid-row: 1 / -1;"
                    >
                        <div class="absolute inset-y-0 left-0 w-1/2 bg-zinc-200 dark:bg-zinc-700"></div>
                    </div>
                @endif
            @endforeach

            @foreach ($kanbanColumns as $dayData)
                @php
                    $dayColIndex = $dayColumnIndices[$loop->index];
                    $isWeekend = $dayData->isWeekend;
                @endphp
                <div
                    wire:key="day-header-{{ $dayData->day->format('Y-m-d') }}-idx{{ $loop->index }}"
                    data-day-index="{{ $loop->index }}"
                    @if($dayData->isToday) data-today="true" @endif
                    class="shrink-0 relative z-20 sticky top-0 {{ $isWeekend ? 'w-40 bg-zinc-200 dark:bg-zinc-700' : 'w-80 bg-zinc-100 dark:bg-zinc-800' }}"
                    style="grid-column: {{ $dayColIndex }}; grid-row: 1;"
                >
                    {{-- Day Header --}}
                    <div class="flex items-center justify-between p-3 mb-3 {{ $isWeekend ? 'opacity-75' : '' }}">
                        <flux:heading size="lg" class="{{ $dayData->isToday ? 'text-indigo-600 dark:text-indigo-400' : '' }}">
                            {{ $dayData->title }}
                        </flux:heading>
                        @if ($dayData->isToday)
                            <flux:badge color="indigo" size="sm">Today</flux:badge>
                        @elseif ($dayData->isTomorrow && ! $dayData->isWeekend)
                            <flux:badge color="zinc" size="sm">Tomorrow</flux:badge>
                        @endif
                    </div>
                </div>
                
                {{-- Project Cells for this day --}}
                @foreach ($dayData->columns as $projectColumn)
                    @php
                        $dayIndex = $dayData->dayIndex;
                        // +2 because row 1 is header
                        $projectRowIndex = $loop->index + 2;
                        $hasTasks = $projectColumn->cards->count() > 0;
                        $hasUndatedTasks = ($projectColumn->undated_tasks_count ?? 0) > 0;
                        $isWeekend = $dayData->isWeekend;
                    @endphp
                    <div
                        wire:key="project-{{ $projectColumn->id }}-{{ $dayData->day->format('Y-m-d') }}"
                        class="min-w-0 pb-2 relative z-10 {{ $isWeekend ? 'w-40' : 'w-80' }}"
                        style="grid-column: {{ $dayColIndex }}; grid-row: {{ $projectRowIndex }};"
                    >
                        @php
                            $latestStatus = $dayData->isWeekend ? null : $projectColumn->project->latestStatus;
                        @endphp

                        <flux:kanban
                            class="w-full [&>div]:w-full [&>div]:min-w-0 [&>div]:flex-1"
                            x-bind:class="getOpacityClass({{ $isWeekend ? 'true' : 'false' }}, {{ $hasTasks ? 'true' : 'false' }}, {{ $hasUndatedTasks ? 'true' : 'false' }}, {{ $dayIndex }})"
                        >
                            <flux:kanban.column class="!w-full !max-w-full bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                            <flux:kanban.column.header
                                                class="min-w-0 w-full [&>div:first-child>div:first-child]:!min-w-0 [&>div:first-child>div:first-child]:!flex-1 [&>div:first-child>div:first-child]:truncate [&>div:first-child>div:last-child]:!shrink-0 [&_[data-flux-subheading]]:!min-w-0 [&_[data-flux-subheading]]:truncate"
                                            >
                                                <flux:heading class="min-w-0 truncate flex items-center gap-2">
                                                    <a
                                                        href="{{ route('projects.show', $projectColumn->project) }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="truncate hover:underline underline-offset-2"
                                                    >
                                                        {{ $projectColumn->title }}
                                                    </a>
                                                    @if($latestStatus)
                                                        <flux:tooltip content="{{ $latestStatus->title }}">
                                                            <flux:badge :color="$latestStatus->badge_color" size="sm" class="!px-0 !size-2 !min-w-0 rounded-full shrink-0" />
                                                        </flux:tooltip>
                                                    @endif
                                                </flux:heading>
                                                <x-slot name="actions">
                                                    <flux:button
                                                        variant="subtle"
                                                        icon="plus"
                                                        size="sm"
                                                        class="shrink-0"
                                                        wire:key="add-task-{{ $projectColumn->id }}-{{ $dayData->day->format('Y-m-d') }}"
                                                        wire:click="addTask({{ $projectColumn->id }}, '{{ $dayData->day->format('Y-m-d') }}')"
                                                        wire:target="addTask({{ $projectColumn->id }}, '{{ $dayData->day->format('Y-m-d') }}')"
                                                    />
                                                </x-slot>

                                                <x-slot name="subheading">
                                                    <span class="block min-w-0 truncate">
                                                        {{ $projectColumn->project->client->last_names }} | {{ $projectColumn->project->project_name }}
                                                    </span>
                                                    {{-- Task gap info (next/last task) - shown in subheading to preserve alignment, hidden on weekends --}}
                                                    @if ($projectColumn->task_gap_info && !$dayData->isWeekend)
                                                        <div
                                                            wire:key="task-gap-{{ $projectColumn->id }}-idx{{ $dayIndex }}"
                                                            x-show="firstVisibleDayIndex === {{ $dayIndex }}"
                                                            x-cloak
                                                            class="text-xs italic mt-0.5"
                                                        >
                                                            @if ($projectColumn->task_gap_info->type === 'both')
                                                                <span class="text-amber-600 dark:text-amber-400">{{ $projectColumn->task_gap_info->last->label }}</span>
                                                                <span class="mx-1 text-zinc-400">·</span>
                                                                <span class="text-indigo-600 dark:text-indigo-400">{{ $projectColumn->task_gap_info->next->label }}</span>
                                                            @elseif ($projectColumn->task_gap_info->type === 'next')
                                                                <span class="text-indigo-600 dark:text-indigo-400">{{ $projectColumn->task_gap_info->label }}</span>
                                                            @else
                                                                <span class="text-amber-600 dark:text-amber-400">{{ $projectColumn->task_gap_info->label }}</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </x-slot>
                                            </flux:kanban.column.header>
                                            <flux:kanban.column.cards>
                                                @if (($projectColumn->undated_tasks_count ?? 0) > 0)
                                                    <flux:kanban.card
                                                        x-show="firstVisibleDayIndex === {{ $dayIndex }}"
                                                        x-cloak
                                                        as="button"
                                                        class="min-w-0 w-full"
                                                        wire:key="undated-tasks-{{ $projectColumn->id }}-idx{{ $dayIndex }}"
                                                        wire:click="openUndatedTasksModal({{ $projectColumn->id }})"
                                                        wire:target="openUndatedTasksModal({{ $projectColumn->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:loading.class="opacity-60 cursor-wait"
                                                    >
                                                        <div class="flex items-center justify-between gap-2 min-w-0">
                                                            <flux:heading size="sm" class="min-w-0 truncate text-orange-600 dark:text-orange-400">
                                                                Pending Tasks
                                                            </flux:heading>
                                                            <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                                                {{ $projectColumn->undated_tasks_count }}
                                                            </span>
                                                        </div>
                                                    </flux:kanban.card>
                                                @endif

                                                @foreach ($projectColumn->cards as $task)
                                                    <livewire:planner.planner-task-card
                                                        :task-id="$task->id"
                                                        :day-format="$dayData->day->format('Y-m-d')"
                                                        :project-id="$projectColumn->id"
                                                        :key="'task-card-' . $task->id . '-' . $dayData->day->format('Y-m-d') . '-' . $projectColumn->id"
                                                    />
                                                @endforeach
                                            </flux:kanban.column.cards>
                                        </flux:kanban.column>
                                    </flux:kanban>
                    </div>
                @endforeach
            @endforeach
            {{-- Right spacer --}}
            <div class="w-4 shrink-0" style="grid-column: -1; grid-row: 1 / -1;"></div>
            </div>
        </div>
    </div>

    {{-- Task Create Modal --}}
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>

    <flux:modal name="planner_undated_tasks_modal" class="space-y-4 min-w-[22rem]">
        <div class="space-y-1">
            <flux:heading size="lg">Pending tasks</flux:heading>
            @if ($undatedTasksModalProjectTitle)
                <flux:subheading class="truncate">{{ $undatedTasksModalProjectTitle }}</flux:subheading>
            @endif
        </div>

        <flux:separator variant="subtle" />

        @if (!empty($undatedTasksModalTasks))
            <div class="space-y-2">
                @foreach ($undatedTasksModalTasks as $task)
                    <flux:kanban.card
                        as="button"
                        class="min-w-0 w-full"
                        wire:key="undated-task-{{ $task['id'] }}"
                        wire:click="editUndatedTask({{ $task['id'] }})"
                        wire:target="editUndatedTask({{ $task['id'] }})"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                    >
                        <div class="flex items-start justify-between gap-2 min-w-0">
                            <flux:heading size="sm" class="min-w-0 truncate {{ $task['type_text_class'] ?? '' }}">
                                {{ $task['title'] }}
                            </flux:heading>
                        </div>

                        @if (($task['users_count'] ?? 0) > 0 || !empty($task['vendor']))
                            <div class="flex items-center gap-2 mt-2 min-w-0">
                                @if (($task['users_count'] ?? 0) > 0)
                                    <flux:avatar.group>
                                        @foreach(($task['users'] ?? []) as $user)
                                            <flux:avatar
                                                circle
                                                size="xs"
                                                name="{{ $user['full_name'] }}"
                                                color="auto"
                                                color:seed="{{ $user['id'] }}"
                                                title="{{ $user['full_name'] }}"
                                            />
                                        @endforeach
                                        @if(($task['users_count'] ?? 0) > 3)
                                            <flux:avatar circle size="xs">{{ ($task['users_count'] ?? 0) - 3 }}+</flux:avatar>
                                        @endif
                                    </flux:avatar.group>
                                @endif

                                @if (!empty($task['vendor']))
                                    <flux:avatar
                                        circle
                                        size="xs"
                                        name="{{ $task['vendor']['name'] }}"
                                        color="auto"
                                        color:seed="{{ $task['vendor']['id'] }}"
                                        title="{{ $task['vendor']['name'] }}"
                                    />
                                    <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $task['vendor']['name'] }}</span>
                                    
                                    @if (!empty($task['vendor_status']))
                                        @php 
                                            $statusUi = \App\Models\Task::VENDOR_STATUS_UI[$task['vendor_status']] ?? null;
                                        @endphp
                                        @if($statusUi)
                                            <flux:badge 
                                                size="sm" 
                                                :color="$statusUi['flux'] ?? 'zinc'"
                                                :icon="$statusUi['icon'] ?? null"
                                            >
                                                {{ $statusUi['label'] ?? ucfirst($task['vendor_status']) }}
                                            </flux:badge>
                                        @endif
                                    @endif
                                @endif
                            </div>
                        @endif
                    </flux:kanban.card>
                @endforeach
            </div>
        @endif
    </flux:modal>
    </div>
</div>

@script
<script>
Alpine.data('plannerScroll', () => ({
    firstVisibleDayIndex: 0,
    atLeftEdge: true,
    atRightEdge: false,
    ready: false,
    pendingScroll: null,
    isAnimating: false,
    
    // Axis locking state for touch scrolling
    touchStartX: null,
    touchStartY: null,
    scrollStartX: null,
    scrollStartY: null,
    lockedAxis: null, // 'x', 'y', or null
    axisLockThreshold: 10, // pixels to determine direction
    
    init() {
        this.$nextTick(() => {
            this.updateFirstVisible();
            this.updateEdgeState();
            this.setupAxisLocking();
            this.ready = true;
        });
        
        // Listen for scroll events from Livewire
        Livewire.on('planner-scroll-to', (data) => {
            this.handleScrollAfterLoad(data.direction);
        });
    },
    
    setupAxisLocking() {
        const container = this.$refs.scrollContainer;
        if (!container || !('ontouchstart' in window)) return;
        
        container.addEventListener('touchstart', this.handleTouchStart.bind(this), { passive: true });
        container.addEventListener('touchmove', this.handleTouchMove.bind(this), { passive: false });
        container.addEventListener('touchend', this.handleTouchEnd.bind(this), { passive: true });
        container.addEventListener('touchcancel', this.handleTouchEnd.bind(this), { passive: true });
    },
    
    handleTouchStart(e) {
        if (e.touches.length !== 1) return;
        
        const touch = e.touches[0];
        const container = this.$refs.scrollContainer;
        
        this.touchStartX = touch.clientX;
        this.touchStartY = touch.clientY;
        this.scrollStartX = container.scrollLeft;
        this.scrollStartY = container.scrollTop;
        this.lockedAxis = null;
    },
    
    handleTouchMove(e) {
        if (e.touches.length !== 1 || this.touchStartX === null) return;
        
        const touch = e.touches[0];
        const deltaX = touch.clientX - this.touchStartX;
        const deltaY = touch.clientY - this.touchStartY;
        const container = this.$refs.scrollContainer;
        
        // Determine the axis to lock to if not already locked
        if (this.lockedAxis === null) {
            const absDeltaX = Math.abs(deltaX);
            const absDeltaY = Math.abs(deltaY);
            
            if (absDeltaX > this.axisLockThreshold || absDeltaY > this.axisLockThreshold) {
                this.lockedAxis = absDeltaX > absDeltaY ? 'x' : 'y';
            }
        }
        
        // If axis is locked, prevent default and manually scroll only in the locked direction
        if (this.lockedAxis) {
            e.preventDefault();
            
            if (this.lockedAxis === 'x') {
                container.scrollLeft = this.scrollStartX - deltaX;
            } else {
                container.scrollTop = this.scrollStartY - deltaY;
            }
        }
    },
    
    handleTouchEnd(e) {
        this.touchStartX = null;
        this.touchStartY = null;
        this.scrollStartX = null;
        this.scrollStartY = null;
        this.lockedAxis = null;
    },
    
    handleScrollAfterLoad(direction) {
        // Wait for Livewire to finish DOM update
        this.$nextTick(() => {
            setTimeout(() => {
                const container = this.$refs.scrollContainer;
                if (!container || !this.pendingScroll) return;
                
                const oldScrollWidth = this.pendingScroll.scrollWidth;
                const newScrollWidth = container.scrollWidth;
                const deltaWidth = newScrollWidth - oldScrollWidth;
                
                if (direction === 'start' && deltaWidth > 0) {
                    // For "Previous": new columns added at start
                    // First, instantly position so user sees same content
                    container.scrollLeft = deltaWidth;
                    
                    // Then animate scroll to 0
                    this.animateScroll(container, deltaWidth, 0, 100);
                } else if (direction === 'end') {
                    // For "Next": new columns added at end
                    const targetScroll = newScrollWidth - container.clientWidth;
                    const startScroll = container.scrollLeft;
                    
                    this.animateScroll(container, startScroll, targetScroll, 100);
                }
                
                this.pendingScroll = null;
            }, 100);
        });
    },
    
    animateScroll(container, from, to, duration) {
        if (this.isAnimating) return;
        this.isAnimating = true;
        
        const startTime = performance.now();
        const distance = to - from;
        
        const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);
        
        const step = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easedProgress = easeOutCubic(progress);
            
            container.scrollLeft = from + (distance * easedProgress);
            
            // Update first visible during animation too
            this.updateFirstVisible();
            
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                this.isAnimating = false;
                this.updateFirstVisible();
                this.updateEdgeState();
            }
        };
        
        requestAnimationFrame(step);
    },

    prepareForLoad(direction) {
        const container = this.$refs.scrollContainer;
        if (!container) {
            this.pendingScroll = null;
            return;
        }

        this.pendingScroll = {
            direction,
            scrollLeft: container.scrollLeft,
            scrollWidth: container.scrollWidth,
        };
    },
    
    updateFirstVisible() {
        const container = this.$refs.scrollContainer;
        if (!container) return;
        const dayColumns = container.querySelectorAll('[data-day-index]');
        let firstIdx = 0;
        const containerRect = container.getBoundingClientRect();
        
        // Find the first column that has at least 50% visible
        for (const col of dayColumns) {
            const colRect = col.getBoundingClientRect();
            const colWidth = colRect.width || 1;
            
            // Calculate how much of the column is visible within the container
            const visibleLeft = Math.max(colRect.left, containerRect.left);
            const visibleRight = Math.min(colRect.right, containerRect.right);
            const visibleWidth = Math.max(0, visibleRight - visibleLeft);
            const visibleRatio = visibleWidth / colWidth;
            
            // Column is "first visible" if at least 50% is still in view
            if (visibleRatio >= 0.5) {
                firstIdx = parseInt(col.dataset.dayIndex, 10);
                break;
            }
        }
        this.firstVisibleDayIndex = firstIdx;
    },
    
    updateEdgeState() {
        const container = this.$refs.scrollContainer;
        if (!container) return;
        const threshold = 50;
        const maxScroll = container.scrollWidth - container.clientWidth;
        this.atLeftEdge = container.scrollLeft <= threshold;
        this.atRightEdge = container.scrollLeft >= maxScroll - threshold;
    },
    
    scrollToDay(index) {
        const container = this.$refs.scrollContainer;
        if (!container) return;
        const dayCol = container.querySelector('[data-day-index="' + index + '"]');
        if (dayCol) {
            dayCol.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
        }
    },
    
    scrollToToday() {
        const container = this.$refs.scrollContainer;
        if (!container) return;
        const todayCol = container.querySelector('[data-today]');
        if (todayCol) {
            todayCol.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
        }
    },
    
    onScroll(e) {
        this.updateFirstVisible();
        this.updateEdgeState();
    },
    
    getOpacityClass(isWeekend, hasTasks, hasUndatedTasks, dayIndex) {
        const isFirstVisible = this.firstVisibleDayIndex === dayIndex;
        const showTasks = hasTasks || (hasUndatedTasks && isFirstVisible);
        
        if (isWeekend && !showTasks) return 'opacity-30 hover:opacity-60';
        if (isWeekend) return 'opacity-75';
        if (!showTasks) return 'opacity-40 hover:opacity-70';
        return '';
    }
}));
</script>
@endscript