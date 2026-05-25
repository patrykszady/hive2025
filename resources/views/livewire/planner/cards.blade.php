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
                wire:model.live.debounce.400ms="filterProjectIds"
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
                wire:model.live.debounce.400ms="filterStatusCodes"
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
                wire:model.live.debounce.400ms="filterVendorId"
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
                wire:model.live.debounce.400ms="filterUserIds"
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
    <div class="fixed top-3 right-3 z-60 flex items-center gap-1">
        {{-- View Toggle --}}
        <div class="flex bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg overflow-hidden">
            <flux:button
                wire:click="$set('viewMode', 'cards')"
                variant="subtle"
                square
                inset
                icon="view-columns"
                :class="$viewMode === 'cards' ? 'bg-zinc-200/80 dark:bg-zinc-700/80' : ''"
                aria-label="Card view"
            />
            <flux:button
                wire:click="$set('viewMode', 'table')"
                variant="subtle"
                square
                inset
                icon="table-cells"
                :class="$viewMode === 'table' ? 'bg-zinc-200/80 dark:bg-zinc-700/80' : ''"
                aria-label="Table view"
            />
            <flux:button
                wire:click="$set('viewMode', 'gantt')"
                variant="subtle"
                square
                inset
                icon="chart-bar"
                :class="$viewMode === 'gantt' ? 'bg-zinc-200/80 dark:bg-zinc-700/80' : ''"
                aria-label="Gantt view"
            />
        </div>

        @if ($viewMode === 'gantt')
            {{-- Zoom switcher (only visible on gantt) --}}
            <div class="flex bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg overflow-hidden">
                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $zoomKey => $zoomLabel)
                    <flux:button
                        wire:click="$set('ganttZoom', '{{ $zoomKey }}')"
                        variant="subtle"
                        size="sm"
                        inset
                        :class="$ganttZoom === $zoomKey ? 'bg-zinc-200/80 dark:bg-zinc-700/80 font-semibold' : ''"
                    >
                        {{ $zoomLabel }}
                    </flux:button>
                @endforeach
            </div>
        @endif

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
                    wire:model.live.debounce.400ms="filterProjectIds"
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
                    wire:model.live.debounce.400ms="filterStatusCodes"
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
                    wire:model.live.debounce.400ms="filterVendorId"
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
                    wire:model.live.debounce.400ms="filterUserIds"
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
    @if ($viewMode === 'cards')
    <div
        x-data="plannerScroll()"
        x-init="init()"
        class="relative flex-1 min-h-0 flex flex-col bg-zinc-100 dark:bg-zinc-800"
    >
        {{-- Loading indicator (left edge) --}}
        <div
            x-show="isLoadingPrevious"
            x-cloak
            class="absolute left-0 top-0 bottom-0 z-20 flex items-center pl-3 pointer-events-none"
        >
            <div class="bg-white/80 dark:bg-zinc-800/80 backdrop-blur-sm rounded-full p-2 shadow-lg">
                <flux:icon.arrow-path class="size-5 text-zinc-500 animate-spin" />
            </div>
        </div>

        {{-- Loading indicator (right edge) --}}
        <div
            x-show="isLoadingFuture"
            x-cloak
            class="absolute right-0 top-0 bottom-0 z-20 flex items-center pr-3 pointer-events-none"
        >
            <div class="bg-white/80 dark:bg-zinc-800/80 backdrop-blur-sm rounded-full p-2 shadow-lg">
                <flux:icon.arrow-path class="size-5 text-zinc-500 animate-spin" />
            </div>
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
                    class="shrink-0 relative z-20 sticky top-0 {{ $isWeekend ? 'w-52 bg-zinc-200 dark:bg-zinc-700' : 'w-80 bg-zinc-100 dark:bg-zinc-800' }}"
                    style="grid-column: {{ $dayColIndex }}; grid-row: 1;"
                >
                    {{-- Day Header --}}
                    <div class="p-3 mb-3 {{ $isWeekend ? 'opacity-75' : '' }}">
                        <flux:heading size="lg" class="whitespace-nowrap {{ $dayData->isToday ? 'text-indigo-600 dark:text-indigo-400' : '' }}">
                            {{ $dayData->title }}
                        </flux:heading>
                        @if ($dayData->isToday)
                            <flux:badge color="indigo" size="sm" class="mt-1">Today</flux:badge>
                        @elseif ($dayData->isTomorrow && ! $dayData->isWeekend)
                            <flux:badge color="zinc" size="sm" class="mt-1">Tomorrow</flux:badge>
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
                        class="min-w-0 pb-2 relative z-10 group/cell {{ $isWeekend ? 'w-52' : 'w-80' }}"
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
                                                        class="truncate hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                                                    >
                                                        {{ $projectColumn->title }}
                                                    </a>
                                                    @if($latestStatus)
                                                        <flux:badge :color="$latestStatus->badge_color" size="sm" inset="top bottom left right" class="shrink-0">
                                                            {{ $latestStatus->title }}
                                                        </flux:badge>
                                                    @endif
                                                </flux:heading>
                                                <x-slot name="actions">
                                                    <flux:button
                                                        variant="subtle"
                                                        icon="plus"
                                                        size="sm"
                                                        class="shrink-0 opacity-0 group-hover/cell:opacity-100 transition-opacity"
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
                                                @include('livewire.planner._pending-tasks-card', [
                                                    'projectId' => $projectColumn->id,
                                                    'count'     => $projectColumn->undated_tasks_count ?? 0,
                                                    'dayIndex'  => $dayIndex,
                                                ])

                                                @foreach ($projectColumn->cards as $task)
                                                    <livewire:planner.planner-task-card
                                                        :task-id="$task->id"
                                                        :day-format="$dayData->day->format('Y-m-d')"
                                                        :project-id="$projectColumn->id"
                                                        :is-weekend="$isWeekend"
                                                        :is-today="$dayData->isToday"
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
    @endif

    @if ($viewMode === 'table')
    @php
        $weekdayCount = $dayHeaders->filter(fn($h) => !$h->isWeekend)->count();
        $weekendCount = $dayHeaders->filter(fn($h) => $h->isWeekend)->count();
        $tableWidth = 224 + ($weekdayCount * 200) + ($weekendCount * 140);
    @endphp
    <div
        x-data="plannerTableScroll()"
        x-init="init()"
        class="relative flex-1 min-h-0"
    >
        {{-- Loading indicator (left edge) --}}
        <div
            x-show="isLoadingPrevious"
            x-cloak
            class="absolute left-0 top-0 bottom-0 z-30 flex items-center pl-3 pointer-events-none"
            style="left: 224px;"
        >
            <div class="bg-white/80 dark:bg-zinc-800/80 backdrop-blur-sm rounded-full p-2 shadow-lg">
                <flux:icon.arrow-path class="size-5 text-zinc-500 animate-spin" />
            </div>
        </div>

        {{-- Loading indicator (right edge) --}}
        <div
            x-show="isLoadingFuture"
            x-cloak
            class="absolute right-0 top-0 bottom-0 z-30 flex items-center pr-3 pointer-events-none"
        >
            <div class="bg-white/80 dark:bg-zinc-800/80 backdrop-blur-sm rounded-full p-2 shadow-lg">
                <flux:icon.arrow-path class="size-5 text-zinc-500 animate-spin" />
            </div>
        </div>

    <div x-ref="tableScrollContainer" @scroll.passive="onInfiniteScroll()" class="h-full overflow-auto bg-white dark:bg-zinc-900">
        @php require resource_path('views/livewire/planner/_day-classes.php'); @endphp
        <table class="border-collapse table-fixed" style="width: {{ $tableWidth }}px;">
            <colgroup>
                <col style="width: 224px;">
                @foreach ($dayHeaders as $dayHeader)
                    <col style="width: {{ $dayHeader->isWeekend ? '140' : '200' }}px;">
                @endforeach
            </colgroup>
            @php
                // Group day headers into month spans for the super-header row (matches gantt view).
                $monthSpans = collect($dayHeaders)
                    ->groupBy(fn ($dh) => $dh->day->format('Y-m'))
                    ->map(fn ($group) => [
                        'count' => $group->count(),
                        'label' => $group->first()->day->format('M Y'),
                    ])
                    ->values();
            @endphp
            <thead class="sticky top-0 z-20 bg-zinc-50 dark:bg-zinc-800/95">
                {{-- Month super-header row --}}
                <tr>
                    <th
                        rowspan="2"
                        class="sticky left-0 z-30 bg-zinc-50 dark:bg-zinc-800/95 px-3 text-left align-middle border-b {{ $dayBorderClass }}"
                        style="box-shadow: inset -2px 0 0 0 #cbd5e1;"
                    >
                        <flux:heading size="sm" class="text-zinc-700 dark:text-zinc-200">Project</flux:heading>
                    </th>
                    @foreach ($monthSpans as $span)
                        <th
                            colspan="{{ $span['count'] }}"
                            class="h-6 px-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 border-b border-r border-zinc-200/60 dark:border-zinc-700/60"
                            style="overflow: visible;"
                        >
                            <span style="position: sticky; left: 232px; display: inline-block; padding: 0 8px;">
                                {{ $span['label'] }}
                            </span>
                        </th>
                    @endforeach
                </tr>
                {{-- Day cells row --}}
                <tr>
                    @foreach ($dayHeaders as $dayHeader)
                        <th
                            wire:key="table-header-{{ $dayHeader->day->format('Y-m-d') }}"
                            class="px-2 text-center align-middle text-[10px] tabular-nums font-normal border-b border-r {{ $dayBorderClass }}
                                {{ $dayHeader->isWeekend ? $dayWeekendBgClass . ' ' . $dayWeekendTextClass : $dayWeekdayTextClass }}
                                {{ $dayHeader->isToday ? $dayTodayTextClass : '' }}"
                            style="height: 32px;"
                            @if ($dayHeader->isToday) data-today @endif
                        >
                            <div class="flex flex-col items-center leading-tight">
                                <span class="{{ $dayHeader->isToday ? 'font-bold' : '' }}">{{ $dayHeader->day->format('D') }}</span>
                                <div class="flex items-center gap-1">
                                    <span class="font-semibold">{{ $dayHeader->day->format('j') }}</span>
                                    @if ($dayHeader->isToday)
                                        <span class="{{ $dayTodayPillClass }}">Today</span>
                                    @endif
                                </div>
                            </div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($projectRows as $row)
                    @php $laneCount = count($row->laneRows); @endphp
                    @foreach ($row->laneRows as $laneIdx => $laneEntries)
                        <tr wire:key="table-row-{{ $row->id }}-lane-{{ $laneIdx }}" class="group">
                            {{-- Project name (sticky left) — rendered once per project with rowspan covering all lanes --}}
                            @if ($laneIdx === 0)
                                <td rowspan="{{ $laneCount }}" class="sticky left-0 z-10 bg-white dark:bg-zinc-900 px-3 py-2 border-b {{ $dayBorderClass }} align-middle" style="box-shadow: inset -2px 0 0 0 #cbd5e1;">
                                    @include('livewire.planner._project-sidebar', [
                                        'project'      => $row->project,
                                        'projectId'    => $row->id,
                                        'title'        => $row->title,
                                        'undatedCount' => $row->undated_tasks_count ?? 0,
                                    ])
                                </td>
                            @endif

                            {{-- Day cells (lane-aware): segments span via colspan; covered cells are skipped --}}
                            @foreach ($laneEntries as $entry)
                                @if ($entry === 'covered')
                                    {{-- absorbed by an earlier colspan in this lane --}}
                                @elseif ($entry->type === 'segment')
                                    @php $task = $entry->task; @endphp
                                    <td
                                        colspan="{{ $entry->span }}"
                                        wire:key="table-seg-{{ $row->id }}-{{ $laneIdx }}-{{ $task->id }}-{{ $entry->first_day_format }}"
                                        class="px-2 py-1.5 border-b border-r {{ $dayBorderClass }} align-top
                                            {{ $entry->is_weekend ? $dayWeekendBgClass : '' }}
                                            {{ $entry->is_today ? $dayTodayBgClass : '' }}"
                                        style="overflow: visible;"
                                        wire:click.stop
                                    >
                                        {{-- Spanning bar: the kanban-card fills the colspan (the visible bar).
                                             Inside it, an inner wrapper uses position:sticky so the title/time/
                                             avatars stay pinned to the right edge of the sticky project sidebar
                                             as the page scrolls horizontally — until the bar's own right edge
                                             eventually slides past, at which point the text scrolls off with it. --}}
                                        <flux:kanban.card
                                            as="button"
                                            class="min-w-0 w-full p-2! {{ $task->trashed() ? 'opacity-50' : '' }}"
                                            style="overflow: visible;"
                                            wire:click="editTask({{ $task->id }}, '{{ $entry->first_day_format }}', {{ $row->id }})"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="opacity-60 cursor-wait"
                                        >
                                            <div style="position: sticky; left: 232px; display: inline-block; min-width: 0; max-width: 100%; text-align: left;">
                                                @include('components.upcoming-tasks-list-card-content', [
                                                    'task'           => $task,
                                                    'date'           => $entry->first_day_format,
                                                    'isWeekend'      => false,
                                                    'hideDayCounter' => $entry->span > 1,
                                                ])
                                            </div>
                                        </flux:kanban.card>
                                    </td>
                                @else
                                    @php $cell = $entry->cell; @endphp
                                    <td
                                        wire:key="table-cell-{{ $row->id }}-{{ $laneIdx }}-{{ $cell->dayFormat }}"
                                        x-data="{ hover: false }"
                                        x-on:mouseenter="hover = true"
                                        x-on:mouseleave="hover = false"
                                        class="px-2 py-1.5 border-b border-r {{ $dayBorderClass }} align-top cursor-pointer
                                            {{ $cell->isWeekend ? $dayWeekendBgClass : '' }}
                                            {{ $cell->isToday ? $dayTodayBgClass : '' }}"
                                        wire:click="addTask({{ $row->id }}, '{{ $cell->dayFormat }}')"
                                    >
                                        <div
                                            x-show="hover"
                                            x-cloak
                                            x-transition.opacity.duration.150ms
                                            class="flex justify-center pt-1"
                                        >
                                            <flux:icon.plus class="size-4 text-zinc-300 dark:text-zinc-600" />
                                        </div>
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="{{ $dayHeaders->count() + 1 }}" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            No projects found
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </div>
    @endif

    {{-- Gantt View --}}
    @if ($viewMode === 'gantt')
        @include('livewire.planner._gantt', [
            'ganttRows' => $ganttRows,
            'ganttLinks' => $ganttLinks,
            'ganttArrowPaths' => $ganttArrowPaths,
            'pxPerDay' => $pxPerDay,
            'days' => $this->days,
        ])
    @endif

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
Alpine.data('plannerTableScroll', () => ({
    ...window.plannerInfiniteScroll('tableScrollContainer'),

    init() {
        this._initInfiniteScroll();
        this.$nextTick(() => this._scrollToTodayInitial());
    },

    _scrollToTodayInitial() {
        const container = this.$refs.tableScrollContainer;
        if (!container) return;
        const todayCell = container.querySelector('[data-today]');
        if (!todayCell) return;
        // Offset so ~2 prior days are visible to the left of today.
        const cellWidth = todayCell.getBoundingClientRect().width || 0;
        const target = todayCell.offsetLeft - (cellWidth * 2);
        container.scrollLeft = Math.max(0, target);
    },
}));

Alpine.data('plannerScroll', () => ({
    ...window.plannerInfiniteScroll('scrollContainer'),

    firstVisibleDayIndex: 0,
    atLeftEdge: true,
    atRightEdge: false,
    ready: false,

    // Axis locking state for touch scrolling
    touchStartX: null,
    touchStartY: null,
    scrollStartX: null,
    scrollStartY: null,
    lockedAxis: null, // 'x', 'y', or null
    axisLockThreshold: 10, // pixels to determine direction
    
    init() {
        this._initInfiniteScroll();
        this.$nextTick(() => {
            this.updateFirstVisible();
            this.updateEdgeState();
            this.setupAxisLocking();
            this._scrollToTodayInitial();
            this.ready = true;
        });
    },

    _scrollToTodayInitial() {
        const container = this.$refs.scrollContainer;
        if (!container) return;
        const todayCol = container.querySelector('[data-today]');
        if (!todayCol) return;
        const colWidth = todayCol.getBoundingClientRect().width || 0;
        const target = todayCol.offsetLeft - (colWidth * 2);
        container.scrollLeft = Math.max(0, target);
        this.updateFirstVisible();
        this.updateEdgeState();
    },

    // Called by the shared infinite-scroll mixin after a load completes.
    onInfiniteLoad() {
        this.updateFirstVisible();
        this.updateEdgeState();
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
        this.onInfiniteScroll();
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