{{-- Keyboard: N new task, T today, ← / → a week back or on, / search
     (plannerKeys; ignored while typing or with a modal open). --}}
<div class="flex flex-col h-full min-h-0" x-data="plannerKeys()" x-on:keydown.window="onKey($event)">
    <div class="flex-1 flex flex-col min-h-0 overflow-hidden bg-zinc-100 dark:bg-zinc-800">
        {{-- Top toolbar: filters (left) + view controls (right) --}}
        <div class="shrink-0 flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700">
        {{-- Desktop filters: search first, then the same four filters on every
             view (List included — they used to hide in its column headers; its
             Due window sits on the list itself),
             each sized to fit its label and clearable on its own, then Mine and
             Clear all. One row at 1440px; it wraps rather than scroll away. --}}
        <div class="hidden lg:flex flex-wrap items-center gap-1.5 flex-1 min-w-0">
            {{-- Fixed wrapper: flux:input's own class lands on the <input>,
                 and its wrapper would otherwise stretch across the row. --}}
            <div class="w-36 shrink-0" title="Search (/)">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    size="sm"
                    icon="magnifying-glass"
                    placeholder="Search…"
                    clearable
                    aria-label="Search the planner"
                />
            </div>

            {{-- Project Filter (Multi-select) --}}
            <flux:select
                wire:model.live.debounce.400ms="filterProjectIds"
                placeholder="All"
                prefix="Project"
                selected-suffix="projects"
                variant="listbox"
                searchable
                multiple
                clearable
                size="sm"
                class="w-32 shrink-0"
                options:class="min-w-72"
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
                placeholder="All"
                prefix="Status"
                selected-suffix="statuses"
                variant="listbox"
                multiple
                clearable
                size="sm"
                class="w-48 shrink-0"
                options:class="min-w-48"
            >
                @foreach($this->statusOptions as $status)
                    <flux:select.option value="{{ $status['code'] }}">
                        <flux:badge size="sm" inset="top bottom" :color="$status['color']">
                            {{ $status['label'] }}
                        </flux:badge>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Team Member Filter (Multi-select with avatars) --}}
            <flux:select
                wire:model.live.debounce.400ms="filterUserIds"
                placeholder="All"
                prefix="Team"
                selected-suffix="people"
                variant="listbox"
                searchable
                multiple
                clearable
                size="sm"
                class="w-28 shrink-0"
                options:class="min-w-64"
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

            {{-- Vendor Filter (with avatars) --}}
            <flux:select
                wire:model.live.debounce.400ms="filterVendorId"
                placeholder="All"
                prefix="Vendor"
                variant="listbox"
                searchable
                clearable
                size="sm"
                class="w-32 shrink-0"
                options:class="min-w-64"
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

            {{-- Only my own tasks (my name is on them) — works in every view. --}}
            <flux:button
                wire:click="$toggle('onlyMine')"
                size="sm"
                icon="user"
                :variant="$onlyMine ? 'primary' : 'outline'"
                aria-pressed="{{ $onlyMine ? 'true' : 'false' }}"
                class="shrink-0"
            >
                Mine
            </flux:button>

            @if($this->hasActiveFilters)
                <flux:tooltip content="Clear all filters" position="bottom">
                    <flux:button
                        wire:click="clearFilters"
                        variant="subtle"
                        size="sm"
                        square
                        icon="x-mark"
                        class="shrink-0"
                        aria-label="Clear all filters"
                    />
                </flux:tooltip>
            @endif
        </div>

        {{-- Mobile date-range segmented control (kept on the toolbar row to save vertical space) --}}
        <div class="flex-1 lg:hidden flex justify-center min-w-0">
            <div class="flex items-center gap-0.5 p-0.5 bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                @foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'] as $rangeKey => $rangeLabel)
                    <button
                        type="button"
                        wire:click="$set('filterDateRange', '{{ $rangeKey }}')"
                        class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $filterDateRange === $rangeKey ? 'bg-indigo-600 text-white shadow-sm' : 'text-zinc-600 dark:text-zinc-300' }}"
                    >
                        {{ $rangeLabel }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- View controls --}}
        <div class="flex items-center gap-2 shrink-0 ml-auto">
        {{-- New task, from anywhere on the page --}}
        <div class="hidden lg:block shrink-0">
            {{-- Words on the widest screens, the + below (one toolbar row at 1440px). --}}
            <flux:tooltip content="New task" kbd="N" position="bottom">
                <flux:button
                    wire:click="$dispatchTo('tasks.task-create', 'addTask', {})"
                    variant="primary"
                    size="sm"
                    icon="plus"
                    class="max-2xl:px-2"
                >
                    <span class="hidden 2xl:inline">New task</span>
                    <span class="sr-only 2xl:hidden">New task</span>
                </flux:button>
            </flux:tooltip>
        </div>

        {{-- View Toggle (desktop only — mobile uses the agenda view). Words,
             not bare icons: five views read the same as icons alone. --}}
        @php
            $plannerViews = [
                'week' => ['label' => 'Week', 'icon' => 'calendar-days', 'hint' => 'This week and next, day by day, with the tasks still to schedule'],
                'table' => ['label' => 'Grid', 'icon' => 'table-cells', 'hint' => 'Projects down the side, days across'],
                'cards' => ['label' => 'Board', 'icon' => 'view-columns', 'hint' => 'One column per day'],
                'list' => ['label' => 'List', 'icon' => 'list-bullet', 'hint' => 'Every task in one sortable list'],
                'gantt' => ['label' => 'Timeline', 'icon' => 'chart-bar', 'hint' => 'Gantt timeline with dependencies'],
            ];
        @endphp
        <div class="hidden lg:flex items-center gap-0.5 p-1 bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg">
            @foreach ($plannerViews as $mode => $view)
                <flux:tooltip :content="$view['hint']" position="bottom">
                    <flux:button
                        wire:click="$set('viewMode', '{{ $mode }}')"
                        variant="subtle"
                        size="sm"
                        :icon="$view['icon']"
                        :class="'max-2xl:px-2 ' . ($viewMode === $mode ? 'bg-zinc-200/80 dark:bg-zinc-700/80 !text-zinc-900 dark:!text-white' : '')"
                        aria-pressed="{{ $viewMode === $mode ? 'true' : 'false' }}"
                    >
                        {{-- Words on the widest screens; below, icons with these
                             tooltips, so search and every filter fit one row. --}}
                        <span class="hidden 2xl:inline">{{ $view['label'] }}</span>
                        <span class="sr-only 2xl:hidden">{{ $view['label'] }}</span>
                    </flux:button>
                </flux:tooltip>
            @endforeach
        </div>


        <flux:button
            wire:click="$toggle('showMobileFilters')"
            variant="subtle"
            square
            inset="right"
            icon="funnel"
            class="lg:hidden bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg"
            aria-label="Filters"
        />
        </div>
    </div>

    {{-- Filters Modal --}}
    <flux:modal wire:model="showMobileFilters" name="mobile-filters" class="max-w-sm">
        <div class="space-y-6">
            <flux:heading size="lg">Filters</flux:heading>

            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                placeholder="Search projects, tasks, vendors…"
                clearable
                aria-label="Search the planner"
            />

            {{-- Time Window Filter (list view only) --}}
            @if($viewMode === 'list')
                <flux:field>
                    <flux:label>Time</flux:label>
                    <flux:select
                        wire:model.live="filterDateRange"
                        variant="listbox"
                    >
                        <flux:select.option value="upcoming">Today &amp; upcoming</flux:select.option>
                        <flux:select.option value="past">Past</flux:select.option>
                        <flux:select.option value="all">All dates</flux:select.option>
                    </flux:select>
                </flux:field>
            @endif

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

            <flux:switch wire:model.live="onlyMine" label="Only my tasks" description="Tasks with your name on them." />

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

    {{-- ============================================================= --}}
    {{-- Desktop views (cards / table / list / gantt) \u2014 lg and up only --}}
    {{-- ============================================================= --}}
    <div class="hidden lg:flex flex-1 flex-col min-h-0">

    @if ($viewMode === 'week')
        @include('livewire.planner._agenda')
    @endif

    <!-- Planner Cards - 14 Day Kanban View -->
    @if ($viewMode === 'cards')
    {{-- Board: a "To schedule" column, then one column per day listing only
         the projects with work that day (they used to repeat in every column,
         greyed out where empty, to keep rows aligned — 2026-10-16). The
         scroll mixin finds days by data-day-index / data-today. --}}
    <div
        x-data="plannerScroll()"
        x-init="init()"
        x-on:planner-today.window="scrollToToday()"
        x-on:planner-step.window="stepWeek($event.detail.direction)"
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
            <div class="flex items-start gap-3 min-w-max p-4">
                {{-- To schedule: tasks with no date yet, and the to-dos the
                     clients' calls and texts asked for, per project. --}}
                @if ($this->boardBacklog->isNotEmpty())
                    {{-- Pinned at the left while the days scroll under it. --}}
                    <div wire:key="board-backlog" x-ref="boardBacklog" class="w-72 shrink-0 self-stretch sticky left-0 z-20 -ml-4 -my-4 pl-4 pr-3 py-4 flex flex-col gap-2 bg-zinc-100 dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">
                        <div class="sticky top-0 z-10 -mx-1 px-1 pb-1 bg-zinc-100 dark:bg-zinc-800">
                            <div class="flex items-center gap-2 pt-1">
                                <flux:heading size="lg" class="whitespace-nowrap">To schedule</flux:heading>
                                <flux:badge color="amber" size="sm">{{ $this->boardBacklog->sum('undated') }}</flux:badge>
                            </div>
                            <flux:text size="sm" class="mt-0.5">Tasks without a date, and asks from calls, texts &amp; email.</flux:text>
                        </div>

                        @foreach ($this->boardBacklog as $row)
                            <flux:kanban.column wire:key="board-backlog-{{ $row->project->id }}" class="!w-full !max-w-full bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <div class="p-2 pb-0">
                                    <div class="px-3 pt-1">
                                        @include('livewire.planner._project-sidebar', [
                                            'project'      => $row->project,
                                            'projectId'    => $row->project->id,
                                            'title'        => $row->project->short_address,
                                            'undatedCount' => $row->undated,
                                            'showPending'  => false,
                                            'showActions'  => false,
                                        ])
                                    </div>
                                </div>
                                <flux:kanban.column.cards>
                                    @include('livewire.planner._pending-tasks-card', ['projectId' => $row->project->id, 'count' => $row->undated])
                                    @include('livewire.planner._action-items-card', ['projectId' => $row->project->id, 'count' => $row->actions])
                                </flux:kanban.column.cards>
                            </flux:kanban.column>
                        @endforeach
                    </div>
                @endif

                @foreach ($kanbanColumns as $dayData)
                    @php
                        $isWeekend = $dayData->isWeekend;
                        $dayFormat = $dayData->day->format('Y-m-d');
                    @endphp
                    <div
                        wire:key="board-day-{{ $dayFormat }}"
                        data-day-index="{{ $loop->index }}"
                        @if($dayData->isToday) data-today="true" @endif
                        class="shrink-0 flex flex-col gap-2 rounded-xl p-2 {{ $isWeekend ? 'w-56 bg-zinc-200/70 dark:bg-zinc-700/50' : 'w-80' }} {{ $dayData->isToday ? 'bg-indigo-100/70 dark:bg-indigo-900/25' : '' }}"
                    >
                        {{-- Day header: stays in view while the column scrolls. --}}
                        <div class="sticky top-0 z-10 -mx-2 -mt-2 rounded-t-xl px-3 pt-2 pb-1.5 backdrop-blur-sm {{ $dayData->isToday ? 'bg-indigo-100/90 dark:bg-indigo-950/80' : ($isWeekend ? 'bg-zinc-200/90 dark:bg-zinc-700/80' : 'bg-zinc-100/90 dark:bg-zinc-800/90') }}">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <flux:heading size="lg" class="whitespace-nowrap {{ $dayData->isToday ? 'text-indigo-600 dark:text-indigo-400' : '' }} {{ $isWeekend ? 'opacity-75' : '' }}">
                                        {{ $dayData->title }}
                                    </flux:heading>
                                    @if ($dayData->isToday)
                                        <flux:badge color="indigo" size="sm">Today</flux:badge>
                                    @elseif ($dayData->isTomorrow && ! $isWeekend)
                                        <flux:badge color="zinc" size="sm">Tomorrow</flux:badge>
                                    @endif
                                </div>
                                @if ($dayData->taskCount > 0)
                                    <flux:text size="sm" class="shrink-0 tabular-nums">{{ $dayData->taskCount }} {{ \Illuminate\Support\Str::plural('task', $dayData->taskCount) }}</flux:text>
                                @endif
                            </div>
                        </div>

                        @forelse ($dayData->columns as $projectColumn)
                            <flux:kanban.column
                                wire:key="board-{{ $projectColumn->id }}-{{ $dayFormat }}"
                                class="group/cell !w-full !max-w-full {{ $dayData->isToday ? 'bg-white/80 dark:bg-zinc-900/80' : 'bg-white dark:bg-zinc-900' }} rounded-lg border border-zinc-200 dark:border-zinc-700"
                            >
                                <div class="p-2 pb-0">
                                    <div class="px-3 pt-1">
                                        @include('livewire.planner._project-sidebar', [
                                            'project'       => $projectColumn->project,
                                            'projectId'     => $projectColumn->id,
                                            'title'         => $projectColumn->title,
                                            'undatedCount'  => $projectColumn->undated_tasks_count ?? 0,
                                            'date'          => $dayFormat,
                                            'revealOnHover' => true,
                                            'showStatus'    => ! $isWeekend,
                                            'showPending'   => false,
                                            'showActions'   => false,
                                        ])
                                    </div>
                                </div>
                                <flux:kanban.column.cards>
                                    @foreach ($projectColumn->cards as $task)
                                        <livewire:planner.planner-task-card
                                            :task-id="$task->id"
                                            :day-format="$dayFormat"
                                            :project-id="$projectColumn->id"
                                            :is-weekend="$isWeekend"
                                            :is-today="$dayData->isToday"
                                            :key="'task-card-' . $task->id . '-' . $dayFormat . '-' . $projectColumn->id"
                                        />
                                    @endforeach
                                </flux:kanban.column.cards>
                            </flux:kanban.column>
                        @empty
                            {{-- An empty day offers to fill itself. --}}
                            <button
                                type="button"
                                wire:key="board-empty-{{ $dayFormat }}"
                                wire:click="$dispatchTo('tasks.task-create', 'addTask', { date: '{{ $dayFormat }}' })"
                                class="group/empty flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-zinc-300 px-3 py-6 text-sm text-zinc-400 transition-colors hover:border-zinc-400 hover:bg-white/60 hover:text-zinc-600 dark:border-zinc-600 dark:text-zinc-500 dark:hover:bg-zinc-900/40 dark:hover:text-zinc-300"
                            >
                                <span class="group-hover/empty:hidden">Nothing scheduled</span>
                                <span class="hidden items-center gap-1 group-hover/empty:inline-flex"><flux:icon.plus variant="micro" class="size-4" /> Add task</span>
                            </button>
                        @endforelse
                    </div>
                @endforeach
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
        x-on:planner-today.window="scrollToToday()"
        x-on:planner-step.window="stepWeek($event.detail.direction)"
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
                            data-grid-day
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
                                    @php
                                        $task = $entry->task;
                                        $segmentHasToday = collect($entry->day_flags)->contains(fn ($f) => $f->is_today);
                                    @endphp
                                    <td
                                        colspan="{{ $entry->span }}"
                                        wire:key="table-seg-{{ $row->id }}-{{ $laneIdx }}-{{ $task->id }}-{{ $entry->first_day_format }}"
                                        class="relative isolate px-2 py-1.5 border-b border-r {{ $dayBorderClass }} align-top"
                                        style="overflow: visible;"
                                        wire:click.stop
                                    >
                                        {{-- Per-day background layer: a single segment td spans multiple
                                             columns via colspan, so we paint the weekend/today background
                                             for each underlying day column ourselves. --}}
                                        <div aria-hidden="true" class="absolute inset-0 -z-10 grid pointer-events-none" style="grid-template-columns: repeat({{ $entry->span }}, minmax(0, 1fr));">
                                            @foreach ($entry->day_flags as $flag)
                                                <div class="{{ $flag->is_weekend ? $dayWeekendBgClass : '' }} {{ $flag->is_today ? $dayTodayBgClass : '' }}"></div>
                                            @endforeach
                                        </div>
                                        {{-- Spanning bar: the kanban-card fills the colspan (the visible bar).
                                             Inside it, an inner wrapper uses position:sticky so the title/time/
                                             avatars stay pinned to the right edge of the sticky project sidebar
                                             as the page scrolls horizontally — until the bar's own right edge
                                             eventually slides past, at which point the text scrolls off with it. --}}
                                        <flux:kanban.card
                                            as="button"
                                            class="min-w-0 w-full p-2! {{ $task->trashed() ? 'opacity-50' : '' }} {{ $segmentHasToday ? 'bg-white/70! dark:bg-zinc-700/70!' : '' }}"
                                            style="overflow: visible;"
                                            wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="opacity-60 cursor-wait"
                                        >
                                            <div style="position: sticky; left: 232px; display: inline-block; min-width: 0; max-width: 100%; text-align: left;">
                                                @include('components.upcoming-tasks-list-card-content', [
                                                    'task'           => $task,
                                                    'date'           => $entry->first_day_format,
                                                    'isWeekend'      => false,
                                                    'hideDayCounter' => $entry->span > 1,
                                                    'compact'        => true,
                                                ])
                                            </div>
                                        </flux:kanban.card>
                                    </td>
                                @else
                                    @php $cell = $entry->cell; @endphp
                                    {{-- Hover affordance is pure CSS (group-hover): an Alpine
                                         component per empty cell meant ~1,700 of them on this grid. --}}
                                    <td
                                        wire:key="table-cell-{{ $row->id }}-{{ $laneIdx }}-{{ $cell->dayFormat }}"
                                        class="group px-2 py-1.5 border-b border-r {{ $dayBorderClass }} align-top cursor-pointer
                                            {{ $cell->isWeekend ? $dayWeekendBgClass : '' }}
                                            {{ $cell->isToday ? $dayTodayBgClass : '' }}"
                                        wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{ $row->id }}, date: '{{ $cell->dayFormat }}' })"
                                    >
                                        <div class="flex justify-center pt-1 opacity-0 transition-opacity duration-150 group-hover:opacity-100">
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
                            {{ $quietProjectCount > 0 ? 'Nothing scheduled on these days.' : 'No projects found' }}
                        </td>
                    </tr>
                @endforelse
                {{-- Projects with nothing booked here and nothing to book fold away. --}}
                @if ($quietProjectCount > 0)
                    <tr wire:key="table-quiet-projects">
                        <td class="sticky left-0 z-10 bg-white dark:bg-zinc-900 px-3 py-3 border-b {{ $dayBorderClass }}" style="box-shadow: inset -2px 0 0 0 #cbd5e1;">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="$toggle('showQuietProjects')"
                                :icon="$showQuietProjects ? 'chevron-up' : 'chevron-down'"
                            >
                                {{ $showQuietProjects ? 'Hide' : 'Show' }} {{ $quietProjectCount }} quiet {{ Str::plural('project', $quietProjectCount) }}
                            </flux:button>
                        </td>
                        <td colspan="{{ $dayHeaders->count() }}" class="border-b {{ $dayBorderClass }} px-3 text-xs text-zinc-400">
                            Nothing scheduled here and nothing waiting to be scheduled.
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
    </div>
    @endif

    {{-- List View (flat task table, similar to Microsoft Planner "My Tasks") --}}
    @if ($viewMode === 'list')
    @php
        $listToday = browser_today()->format('Y-m-d');
        $vline = 'border-r border-zinc-200 dark:border-zinc-700';
    @endphp
    {{-- The list's own window (the other views page by date), on the list
         itself rather than in the shared toolbar. --}}
    <div class="shrink-0 hidden lg:flex items-center justify-between gap-3 px-4 py-2 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700">
        <flux:radio.group wire:model.live="filterDateRange" variant="segmented" size="sm">
            <flux:radio value="upcoming" label="Today &amp; upcoming" />
            <flux:radio value="past" label="Past" />
            <flux:radio value="all" label="All dates" />
        </flux:radio.group>
        <flux:text size="sm" class="tabular-nums">{{ number_format($this->taskList->count()) }} {{ \Illuminate\Support\Str::plural('task', $this->taskList->count()) }}</flux:text>
    </div>
    <div class="flex-1 min-h-0 overflow-auto bg-white dark:bg-zinc-900">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="{{ $vline }} align-top">Task</flux:table.column>

                {{-- Plain headers: the filters live in the toolbar for every
                     view now, and the selects' minimum widths here pushed the
                     table wider than the screen. --}}
                <flux:table.column class="{{ $vline }} align-top">Project</flux:table.column>
                <flux:table.column class="{{ $vline }} align-top">Status</flux:table.column>
                <flux:table.column class="{{ $vline }} align-top">Due</flux:table.column>
                <flux:table.column class="{{ $vline }} align-top">Type</flux:table.column>
                <flux:table.column class="{{ $vline }} align-top">Assigned</flux:table.column>
                <flux:table.column class="{{ $vline }} align-top">Vendor</flux:table.column>

                <flux:table.column class="align-top">Task Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->taskList as $row)
                    @php
                        $task = $row->task;
                        $isOverdue = $row->last_date && $row->last_date < $listToday && (int) ($task->progress ?? 0) < 100;
                    @endphp
                    <flux:table.row
                        wire:key="list-task-{{ $task->id }}"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                        wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                    >
                        {{-- Task name --}}
                        <flux:table.cell variant="strong" class="whitespace-normal {{ $vline }}">
                            <div class="flex flex-col items-start gap-0.5">
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $task->title }}</span>
                                <x-task-preferred-indicator :task="$task" />
                            </div>
                        </flux:table.cell>

                        {{-- Project (address) --}}
                        <flux:table.cell class="whitespace-normal {{ $vline }}">
                            <a
                                href="{{ route('projects.show', $row->project) }}"
                                x-on:click.stop
                                class="truncate text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white hover:underline underline-offset-2"
                            >{{ $row->project->short_address }}</a>
                        </flux:table.cell>

                        {{-- Status (project status) --}}
                        <flux:table.cell class="{{ $vline }}">
                            @if($row->project->latestStatus)
                                <flux:badge size="sm" :color="$row->project->latestStatus->badge_color" inset="top bottom">
                                    {{ $row->project->latestStatus->title }}
                                </flux:badge>
                            @else
                                <span class="text-sm text-zinc-400">&mdash;</span>
                            @endif
                        </flux:table.cell>

                        {{-- Due date --}}
                        <flux:table.cell class="{{ $vline }}">
                            @if ($row->first_date)
                                @php
                                    $start = \Carbon\Carbon::parse($row->first_date);
                                    $end = $row->last_date ? \Carbon\Carbon::parse($row->last_date) : $start;
                                @endphp
                                {{-- "Oct 15 – 16": the year only when it isn't this one. --}}
                                <span class="tabular-nums whitespace-nowrap {{ $isOverdue ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-zinc-600 dark:text-zinc-400' }}">
                                    {{ $start->format($start->isCurrentYear() ? 'M j' : 'M j, Y') }}@if (! $start->isSameDay($end)) <span class="text-zinc-400">&ndash;</span> {{ $end->format($start->isSameMonth($end) ? 'j' : 'M j') }}{{ $end->isCurrentYear() ? '' : $end->format(', Y') }}@endif
                                </span>
                            @else
                                <flux:badge size="sm" color="zinc" inset="top bottom">No date</flux:badge>
                            @endif
                        </flux:table.cell>

                        {{-- Type --}}
                        <flux:table.cell class="{{ $vline }}">
                            <flux:badge size="sm" :color="$task->type_ui['flux'] ?? 'zinc'" inset="top bottom">
                                {{ $task->type }}
                            </flux:badge>
                        </flux:table.cell>

                        {{-- Assigned --}}
                        <flux:table.cell class="{{ $vline }}">
                            @if ($row->users->isNotEmpty())
                                <flux:avatar.group>
                                    @foreach ($row->users as $user)
                                        <flux:avatar
                                            size="xs"
                                            name="{{ $user->full_name }}"
                                            color="auto"
                                            color:seed="{{ $user->id }}"
                                            tooltip
                                        />
                                    @endforeach
                                </flux:avatar.group>
                            @else
                                <span class="text-sm text-zinc-400">&mdash;</span>
                            @endif
                        </flux:table.cell>

                        {{-- Vendor --}}
                        <flux:table.cell class="whitespace-normal {{ $vline }}">
                            @if ($task->vendor)
                                <div class="flex items-center gap-2">
                                    <flux:avatar size="xs" name="{{ $task->vendor->name }}" color="auto" color:seed="{{ $task->vendor->id }}" />
                                    <span class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $task->vendor->name }}</span>
                                </div>
                            @else
                                <span class="text-sm text-zinc-400">&mdash;</span>
                            @endif
                        </flux:table.cell>

                        {{-- Task status (vendor status) --}}
                        <flux:table.cell>
                            @if ($task->vendor_status)
                                <x-task-status-badge :status="$task->vendor_status" />
                            @elseif ((int) ($task->progress ?? 0) >= 100)
                                <flux:badge size="sm" color="green" icon="check-circle" inset="top bottom">Completed</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" inset="top bottom">Not started</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center text-sm text-zinc-500 dark:text-zinc-400 py-8">
                            No tasks found
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    @endif

    {{-- Gantt View. Its own island: a scroll load, a drag or a new link made
         inside it re-renders this block alone, not the toolbar, the agenda
         and the modals with it. always: true so a filter change from the
         toolbar (outside the island) still refreshes it. An island rendered
         on its own sees the component's properties and computeds, not
         render()'s view data, hence the $this-> reads. --}}
    @if ($viewMode === 'gantt')
        @island(name: 'gantt', always: true)
            @include('livewire.planner._gantt', [
                'ganttRows' => $this->ganttRows,
                'ganttArrowPaths' => $this->ganttArrowPaths,
                'pxPerDay' => $this->ganttPxPerDay,
                'days' => $this->days,
            ])
        @endisland
    @endif

    </div>
    {{-- /Desktop views --}}

    {{-- ============================================================= --}}
    {{-- Mobile agenda view (below lg) \u2014 vertical, day-grouped list --}}
    {{-- ============================================================= --}}
    {{-- Lazy island: on a desktop this block is display:none and never
         intersects, so it is no longer computed and shipped on every page
         load and every gantt scroll or drag. always: true keeps it current
         after a filter change on a phone, where it is the view. --}}
    <div class="lg:hidden flex flex-1 flex-col min-h-0 bg-zinc-50 dark:bg-zinc-900 overflow-y-auto">
        <div class="p-3">
            @island(name: 'mobile-agenda', lazy: island_lazy(), always: true)
                @placeholder
                    <div class="flex items-center justify-center gap-2 py-10 text-sm text-zinc-500 dark:text-zinc-400">
                        <flux:icon.arrow-path class="size-4 animate-spin" />
                        Loading tasks…
                    </div>
                @endplaceholder
                <x-upcoming-tasks-list
                    :grouped-tasks="$this->mobileGroupedTasks"
                    :unscheduled-tasks="$this->mobileUnscheduledTasks"
                    :task-count="$this->mobileTaskCount"
                    :show-avatars="true"
                    :clickable="true"
                    :show-project-info="true"
                    :show-vendor-info="true"
                    :show-notifications="false"
                    :pending-tasks-expanded="false"
                    title="Tasks"
                    empty-message="No tasks found for the selected filters."
                />
            @endisland
        </div>
    </div>
    {{-- /Mobile agenda --}}

    {{-- Task Create Modal --}}
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>

    <div
        x-data="plannerUndatedTasksModal()"
        x-on:open-undated-tasks.window="open($event.detail.projectId)"
    >
        <flux:modal name="planner_undated_tasks_modal" class="min-w-[22rem]">
            <div class="space-y-1">
                <flux:heading size="lg">Pending tasks</flux:heading>
                <flux:subheading class="truncate" x-show="title" x-text="title"></flux:subheading>
            </div>

            <flux:separator variant="subtle" class="my-3" />

            {{-- Its own island so a gantt scroll or drag does not re-render
                 every pending card; always: true so filter changes still do. --}}
            @island(name: 'pending-tasks', always: true)
            @foreach ($this->undatedTasksByProject as $projectId => $entry)
                <div
                    data-undated-project="{{ $projectId }}"
                    data-project-title="{{ $entry['title'] }}"
                    x-show="selectedProjectId === {{ (int) $projectId }}"
                    style="display: none;"
                    class="space-y-2"
                >
                    @foreach ($entry['tasks'] as $task)
                        <flux:kanban.card
                            as="button"
                            class="min-w-0 w-full"
                            wire:key="undated-task-{{ $task->id }}"
                            x-on:click="edit({{ (int) $task->id }})"
                        >
                            @include('components.upcoming-tasks-list-card-content', [
                                'task' => $task,
                                'date' => null,
                                'taskUsers' => $task->users,
                                'arrivalTimeLabel' => null,
                                'previousArrivalTimeLabel' => null,
                                'isWeekend' => false,
                                'compact' => true,
                            ])
                        </flux:kanban.card>
                    @endforeach
                </div>
            @endforeach
            @endisland
        </flux:modal>
    </div>

    {{-- From calls, texts & email: the open action items a project's calls,
         texts and email asked for (ProjectActionItem), to book, tick off or
         dismiss. --}}
    <div
        x-data="plannerActionItemsModal()"
        x-on:open-action-items.window="open($event.detail.projectId)"
    >
        <flux:modal name="planner_action_items_modal" class="min-w-[22rem] md:min-w-[36rem]">
            <div class="space-y-1">
                <flux:heading size="lg">From calls, texts &amp; email</flux:heading>
                <flux:subheading class="truncate" x-show="title" x-text="title"></flux:subheading>
            </div>

            <flux:separator variant="subtle" class="my-3" />

            @island(name: 'action-items', always: true)
            @php $listCutoff = now()->subDays((int) config('action_items.list_days', 14)); @endphp
            @foreach ($this->actionItemsByProject as $projectId => $entry)
                <div
                    data-action-project="{{ $projectId }}"
                    data-project-title="{{ $entry['title'] }}"
                    x-show="selectedProjectId === {{ (int) $projectId }}"
                    x-data="{ showOlder: false }"
                    style="display: none;"
                    class="space-y-4"
                >
                    @foreach ($entry['groups'] as $owner => $items)
                        <div class="space-y-1.5" wire:key="action-owner-{{ $projectId }}-{{ Str::slug($owner) }}">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                <span>{{ $owner }}</span>
                                <span class="text-zinc-400 dark:text-zinc-500">{{ $items->count() }}</span>
                            </div>
                            @foreach ($items as $item)
                                @php $isOlder = ! $item->last_mentioned_at || $item->last_mentioned_at->lt($listCutoff); @endphp
                                <div
                                    wire:key="action-item-{{ $item->id }}"
                                    @if ($isOlder) x-show="showOlder" x-cloak @endif
                                    class="flex items-start gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 {{ $isOlder ? 'opacity-80' : '' }}"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm text-zinc-900 dark:text-zinc-100">{{ $item->title }}</div>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            @if ($item->due_on)
                                                <span class="{{ $item->due_on->isPast() ? 'text-amber-600 dark:text-amber-400' : '' }}">due {{ $item->due_on->format('D M j') }}</span>
                                            @endif
                                            @if ($item->mentions > 1)
                                                <span class="rounded bg-zinc-100 px-1 dark:bg-zinc-800" title="Came up {{ $item->mentions }} times">×{{ $item->mentions }}</span>
                                            @endif
                                            @foreach (collect($item->sources ?? [])->reverse()->take(3) as $source)
                                                <a
                                                    href="{{ \App\Models\ProjectActionItem::sourceUrl($source, $item->project_id) }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center gap-0.5 underline decoration-dotted underline-offset-2 hover:text-indigo-600 dark:hover:text-indigo-400"
                                                    title="{{ $source['excerpt'] ?? '' }}"
                                                >
                                                    @if (($source['kind'] ?? null) === 'email')
                                                        <flux:icon.envelope variant="micro" class="size-3" />
                                                    @endif
                                                    {{ \App\Models\ProjectActionItem::sourceLabel($source) }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-0.5">
                                        <flux:button size="xs" variant="subtle" icon="plus" x-on:click="toTask({{ (int) $item->id }})" title="Make it a task" aria-label="Make it a task" />
                                        <flux:button size="xs" variant="subtle" icon="check" wire:click="actionItemDone({{ (int) $item->id }})" title="Done" aria-label="Done" />
                                        <flux:button size="xs" variant="subtle" icon="x-mark" wire:click="actionItemDismiss({{ (int) $item->id }})" title="Dismiss" aria-label="Dismiss" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                    @if ($entry['older'] > 0)
                        <button
                            type="button"
                            x-on:click="showOlder = ! showOlder"
                            class="text-xs font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
                            x-text="showOlder ? 'Hide the {{ $entry['older'] }} older than {{ (int) config('action_items.list_days', 14) }} days' : 'Show {{ $entry['older'] }} older than {{ (int) config('action_items.list_days', 14) }} days'"
                        ></button>
                    @endif
                </div>
            @endforeach
            <p
                x-show="selectedProjectId !== null && ! $root.querySelector(`[data-action-project='${selectedProjectId}']`)"
                class="text-sm text-zinc-500 dark:text-zinc-400"
            >Nothing open from calls, texts or email.</p>
            @endisland
        </flux:modal>
    </div>
    </div>
</div>

@script
<script>
// Week view: drag a "To schedule" task onto a day, or pick a date for it.
Alpine.data('plannerKeys', () => ({
    onKey(event) {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || event.repeat) return;

        const target = event.target;
        const search = document.querySelector('input[aria-label="Search the planner"]');

        // Typing anywhere (search, a select, the task modal) is never a shortcut.
        if (target.closest?.('input, textarea, select, [contenteditable="true"], ui-select, ui-combobox, [role="listbox"], [role="menu"], [role="dialog"]')) {
            if (event.key === 'Escape' && target === search) search.blur();
            return;
        }
        if (document.querySelector('dialog[open]')) return;

        const view = this.$wire.viewMode;

        switch (event.key) {
            case 'n':
            case 'N':
                event.preventDefault();
                this.$wire.$dispatchTo('tasks.task-create', 'addTask', {});
                break;
            case 't':
            case 'T':
                event.preventDefault();
                if (view === 'week') this.$wire.agendaThisWeek();
                else window.dispatchEvent(new CustomEvent('planner-today'));
                break;
            case 'ArrowLeft':
            case 'ArrowRight': {
                const direction = event.key === 'ArrowRight' ? 1 : -1;
                if (view === 'week') {
                    event.preventDefault();
                    direction > 0 ? this.$wire.agendaNextWeek() : this.$wire.agendaPreviousWeek();
                } else if (view !== 'list') {
                    event.preventDefault();
                    window.dispatchEvent(new CustomEvent('planner-step', { detail: { direction } }));
                }
                break;
            }
            case '/':
                if (search && search.offsetParent) {
                    event.preventDefault();
                    search.focus();
                }
                break;
        }
    },
}));

Alpine.data('plannerAgenda', () => ({
    dragging: null,
    // The day a scheduled card is dragged FROM; null while dragging from the tray.
    moveFrom: null,
    over: null,
    pickFor: null,
    pickTitle: '',

    start(event, taskId) {
        this.dragging = taskId;
        this.moveFrom = null;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(taskId));
    },

    startMove(event, taskId, fromDate) {
        this.dragging = taskId;
        this.moveFrom = fromDate;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(taskId));
    },

    end() {
        this.dragging = null;
        this.moveFrom = null;
        this.over = null;
    },

    drop(event, date) {
        const id = parseInt(event.dataTransfer.getData('text/plain') || this.dragging, 10);
        const from = this.moveFrom;
        this.end();
        if (! id) {
            return;
        }
        if (from) {
            if (from !== date) this.$wire.moveTaskDay(id, from, date);
        } else {
            this.$wire.scheduleTask(id, date);
        }
    },

    pickDate(taskId, title) {
        this.pickFor = taskId;
        this.pickTitle = title;
        this.$flux.modal('planner-schedule-date').show();
    },

    choose(date) {
        if (! this.pickFor || ! /^\d{4}-\d{2}-\d{2}$/.test(date || '')) {
            return;
        }
        this.$wire.scheduleTask(this.pickFor, date);
        this.pickFor = null;
        this.$flux.modal('planner-schedule-date').close();
    },
}));

Alpine.data('plannerUndatedTasksModal', () => ({
    title: '',
    selectedProjectId: null,

    open(projectId) {
        const id = parseInt(projectId, 10);
        const section = this.$root.querySelector(`[data-undated-project="${id}"]`);
        if (! section) return;
        this.title = section.dataset.projectTitle || '';
        this.selectedProjectId = id;
        if (typeof Flux !== 'undefined' && Flux.modal) {
            Flux.modal('planner_undated_tasks_modal').show();
        }
    },

    edit(taskId) {
        if (typeof Flux !== 'undefined' && Flux.modal) {
            Flux.modal('planner_undated_tasks_modal').close();
        }
        if (typeof Livewire !== 'undefined') {
            Livewire.dispatchTo('tasks.task-create', 'editTask', { task: taskId });
        }
    },
}));

Alpine.data('plannerActionItemsModal', () => ({
    title: '',
    selectedProjectId: null,

    open(projectId) {
        const id = parseInt(projectId, 10);
        const section = this.$root.querySelector(`[data-action-project="${id}"]`);
        this.title = section ? (section.dataset.projectTitle || '') : '';
        this.selectedProjectId = id;
        if (typeof Flux !== 'undefined' && Flux.modal) {
            Flux.modal('planner_action_items_modal').show();
        }
    },

    toTask(itemId) {
        if (typeof Flux !== 'undefined' && Flux.modal) {
            Flux.modal('planner_action_items_modal').close();
        }
        this.$wire.actionItemToTask(itemId);
    },
}));

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

    scrollToToday() {
        const container = this.$refs.tableScrollContainer;
        const todayCell = container?.querySelector('[data-today]');
        if (!todayCell) return;
        const cellWidth = todayCell.getBoundingClientRect().width || 0;
        container.scrollTo({ left: Math.max(0, todayCell.offsetLeft - (cellWidth * 2)), behavior: 'smooth' });
    },

    // A week back or on: the day seven columns from the first one in view
    // lands at the left edge, beside the pinned project column.
    stepWeek(direction) {
        const container = this.$refs.tableScrollContainer;
        const days = [...(container?.querySelectorAll('[data-grid-day]') || [])];
        if (days.length === 0) return;
        const origin = days[0].offsetLeft;
        let first = days.findIndex((day) => day.offsetLeft - origin >= container.scrollLeft - 1);
        if (first < 0) first = days.length - 1;
        const target = days[Math.min(days.length - 1, Math.max(0, first + 7 * direction))];
        container.scrollTo({ left: Math.max(0, target.offsetLeft - origin), behavior: 'smooth' });
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
        // Shown first, then scrolled: a hidden (x-show) container has no
        // layout, so the scroll to today used to land on 0 every time.
        this.ready = true;
        this.$nextTick(() => {
            this.setupAxisLocking();
            this._scrollToTodayInitial();
        });
    },

    // Today right after the pinned "To schedule" column (or two days in,
    // without one), so the board opens on what's next.
    todayScrollLeft() {
        const container = this.$refs.scrollContainer;
        const todayCol = container?.querySelector('[data-today]');
        if (!todayCol) return null;
        const backlog = this.$refs.boardBacklog;
        const offset = backlog
            ? backlog.getBoundingClientRect().width + 12
            : (todayCol.getBoundingClientRect().width || 0) * 2;
        return Math.max(0, todayCol.offsetLeft - offset);
    },

    _scrollToTodayInitial() {
        const container = this.$refs.scrollContainer;
        const target = this.todayScrollLeft();
        if (container && target !== null) container.scrollLeft = target;
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
        const target = this.todayScrollLeft();
        if (container && target !== null) container.scrollTo({ left: target, behavior: 'smooth' });
    },

    // A week back or on: the day seven columns from the first one in view
    // lands right after the pinned "To schedule" column.
    stepWeek(direction) {
        const container = this.$refs.scrollContainer;
        const days = [...(container?.querySelectorAll('[data-day-index]') || [])];
        if (days.length === 0) return;
        const backlog = this.$refs.boardBacklog;
        const inset = backlog ? backlog.getBoundingClientRect().width + 12 : 16;
        let first = days.findIndex((day) => day.offsetLeft - inset >= container.scrollLeft - 1);
        if (first < 0) first = days.length - 1;
        const target = days[Math.min(days.length - 1, Math.max(0, first + 7 * direction))];
        container.scrollTo({ left: Math.max(0, target.offsetLeft - inset), behavior: 'smooth' });
    },
    
    onScroll(e) {
        this.updateFirstVisible();
        this.updateEdgeState();
        this.onInfiniteScroll();
    },
    
}));
</script>
@endscript