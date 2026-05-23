{{--
    Gantt View — single source of truth for layout math is $pxPerDay (server-driven).
    Alpine handles: scroll-to-today, bar drag/resize, dependency arrow drawing.
--}}
@php
    $rowHeight = 74;              // bar (64) + 10px gap; arrows route through the gap
    $rowPadding = 6;              // vertical padding inside each project row
    $projectColumnWidth = 280;    // px sticky left column
    $headerHeight = 56;           // px sticky top header
    $totalDays = $days->count();
    $timelineWidth = $totalDays * $pxPerDay;
    $today = browser_today();
    $firstDay = $days->first();
    $todayOffsetPx = (int) $firstDay->diffInDays($today) * $pxPerDay;
@endphp

<div
    x-data="plannerGantt({
        pxPerDay: {{ $pxPerDay }},
        firstDay: @js($firstDay->format('Y-m-d')),
        lastDay: @js($days->last()->format('Y-m-d')),
        projectColumnWidth: {{ $projectColumnWidth }},
        headerHeight: {{ $headerHeight }},
        rowHeight: {{ $rowHeight }},
        rowPadding: {{ $rowPadding }},
    })"
    @gantt-link-start="startLinkDrag($event.detail)"
    @gantt-bar-hover="hoveredTaskId = $event.detail.taskId"
    class="flex-1 min-h-0 flex flex-col bg-white dark:bg-zinc-900"
    wire:key="gantt-{{ $ganttZoom }}-{{ $pxPerDay }}"
>
    <div
        x-ref="scroller"
        @scroll.passive="onInfiniteScroll()"
        class="flex-1 overflow-auto relative"
        style="overscroll-behavior-x: contain; scroll-behavior: auto;"
    >
        {{-- Loading indicators (left/right edges) — matches table view --}}
        <div
            x-show="isLoadingPrevious"
            x-cloak
            class="absolute left-0 top-0 bottom-0 z-40 flex items-center pl-3 pointer-events-none"
        >
            <div class="bg-white/80 dark:bg-zinc-800/80 backdrop-blur-sm rounded-full p-2 shadow-lg">
                <flux:icon.arrow-path class="size-5 text-zinc-500 animate-spin" />
            </div>
        </div>
        <div
            x-show="isLoadingFuture"
            x-cloak
            class="absolute right-0 top-0 bottom-0 z-40 flex items-center pr-3 pointer-events-none"
        >
            <div class="bg-white/80 dark:bg-zinc-800/80 backdrop-blur-sm rounded-full p-2 shadow-lg">
                <flux:icon.arrow-path class="size-5 text-zinc-500 animate-spin" />
            </div>
        </div>

        <div
            class="relative"
            :style="`width: {{ $projectColumnWidth + $timelineWidth }}px;`"
            x-ref="grid"
        >
            {{-- Sticky top header: day labels --}}
            <div
                class="sticky top-0 z-30 flex bg-zinc-50 dark:bg-zinc-800/95 backdrop-blur border-b border-zinc-200 dark:border-zinc-700"
                style="height: {{ $headerHeight }}px;"
            >
                <div
                    class="sticky left-0 z-40 bg-zinc-50 dark:bg-zinc-800/95 border-r border-zinc-200 dark:border-zinc-700 flex items-center px-3"
                    style="width: {{ $projectColumnWidth }}px; min-width: {{ $projectColumnWidth }}px;"
                >
                    <flux:heading size="sm" class="text-zinc-700 dark:text-zinc-200">Project</flux:heading>
                </div>
                <div class="relative" style="width: {{ $timelineWidth }}px;">
                    {{-- Month/Week super-header --}}
                    <div class="flex h-6 border-b border-zinc-200/60 dark:border-zinc-700/60">
                        @php
                            // Group day cells into month spans for the super-header.
                            $monthSpans = collect($days)->groupBy(fn($d) => $d->format('Y-m'))->map(fn($d) => $d->count());
                        @endphp
                        @foreach ($monthSpans as $key => $count)
                            @php
                                [$year, $month] = explode('-', $key);
                                $label = \Carbon\Carbon::create((int) $year, (int) $month, 1)->format('M Y');
                            @endphp
                            <div
                                class="flex items-center px-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400 border-r border-zinc-200/60 dark:border-zinc-700/60 overflow-hidden"
                                style="width: {{ $count * $pxPerDay }}px;"
                            >
                                {{ $label }}
                            </div>
                        @endforeach
                    </div>
                    {{-- Day cells --}}
                    <div class="flex h-[30px]">
                        @foreach ($days as $i => $day)
                            @php
                                $isWeekend = $day->isWeekend();
                                $isToday = $day->isSameDay($today);
                            @endphp
                            <div
                                class="flex flex-col items-center justify-center border-r text-[10px] tabular-nums select-none
                                    {{ $isWeekend ? 'bg-zinc-50 dark:bg-zinc-800 text-zinc-400 dark:text-zinc-500' : 'text-zinc-600 dark:text-zinc-300' }}
                                    {{ $isToday ? '!text-indigo-600 dark:!text-indigo-400 font-bold' : '' }}
                                    border-zinc-200/60 dark:border-zinc-700/60"
                                style="width: {{ $pxPerDay }}px; min-width: {{ $pxPerDay }}px;"
                                data-date="{{ $day->format('Y-m-d') }}"
                                @if ($isToday) data-today @endif
                            >
                                @if ($pxPerDay >= 60)
                                    <span>{{ $day->format('D') }}</span>
                                    <span class="font-semibold">{{ $day->format('j') }}</span>
                                @elseif ($pxPerDay >= 24)
                                    <span class="font-semibold">{{ $day->format('j') }}</span>
                                @else
                                    @if ($day->isMonday() || $i === 0)
                                        <span class="font-semibold">{{ $day->format('j') }}</span>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Body: project rows + bars --}}
            <div class="relative">
                {{-- Background grid layer: day vertical lines + weekend + today column shading.
                     Sits behind project rows; rows get z-10 to render on top. --}}
                <div
                    class="absolute top-0 bottom-0 z-0 pointer-events-none"
                    style="left: {{ $projectColumnWidth }}px; width: {{ $timelineWidth }}px;"
                >
                    @foreach ($days as $i => $day)
                        @php
                            $isWeekend = $day->isWeekend();
                            $isToday = $day->isSameDay($today);
                        @endphp
                        <div
                            class="absolute top-0 bottom-0 border-r border-zinc-200/60 dark:border-zinc-700/60
                                {{ $isWeekend ? 'bg-zinc-50 dark:bg-zinc-800' : '' }}
                                {{ $isToday ? 'bg-indigo-50/50 dark:bg-indigo-900/10' : '' }}"
                            style="left: {{ $i * $pxPerDay }}px; width: {{ $pxPerDay }}px;"
                        ></div>
                    @endforeach
                </div>

                {{-- Today vertical line (extends across entire body) --}}
                @if ($todayOffsetPx >= 0 && $todayOffsetPx <= $timelineWidth)
                    <div
                        class="absolute top-0 bottom-0 w-px bg-indigo-500/60 dark:bg-indigo-400/60 pointer-events-none z-20"
                        style="left: {{ $projectColumnWidth + $todayOffsetPx }}px;"
                    ></div>
                @endif

                @forelse ($ganttRows as $projectRow)
                    @php
                        $rowsCount = max(1, $projectRow->rowCount);
                        $projectHeight = ($rowsCount * $rowHeight) + (2 * $rowPadding);
                    @endphp
                    <div
                        class="relative z-10 flex border-b border-zinc-200/60 dark:border-zinc-700/60 group"
                        style="height: {{ $projectHeight }}px;"
                        wire:key="gantt-project-{{ $projectRow->id }}"
                    >
                        {{-- Sticky project sidebar (shared partial — matches table view) --}}
                        <div
                            class="sticky left-0 z-10 bg-white dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700 flex flex-col justify-center gap-1 px-3 py-2 group/cell"
                            style="width: {{ $projectColumnWidth }}px; min-width: {{ $projectColumnWidth }}px;"
                        >
                            @php
                                $project = $projectRow->project;
                                $unscheduledCount = $projectRow->unscheduled->count();
                            @endphp
                            @include('livewire.planner._project-sidebar', [
                                'project'      => $project,
                                'projectId'    => $project->id,
                                'title'        => $project->short_address ?? $project->title,
                                'undatedCount' => $unscheduledCount,
                            ])
                        </div>

                        {{-- Timeline lane --}}
                        <div
                            class="relative"
                            style="width: {{ $timelineWidth }}px; padding-top: {{ $rowPadding }}px; padding-bottom: {{ $rowPadding }}px;"
                            data-project-lane="{{ $projectRow->id }}"
                        >
                            {{-- Bars --}}
                            @foreach ($projectRow->rows as $rowIndex => $row)
                                @foreach ($row as $bar)
                                    @php
                                        $task = $bar['task'];
                                        $ui = $task->type_ui;
                                        $vendor = $task->vendor;
                                        $barTop = $rowPadding + ($rowIndex * $rowHeight);
                                        $barHeight = $rowHeight - 10;
                                        $avatarCount = $task->users->count() + ($vendor ? 1 : 0);
                                        // Inset bar from the day grid edges so cards don't touch column borders.
                                        $barInsetLeft = ($bar['truncated_left'] ?? false) ? 0 : 4;
                                        $barInsetRight = ($bar['truncated_right'] ?? false) ? 0 : 4;
                                        $barLeftPx = $bar['left_px'] + $barInsetLeft;
                                        $barWidthPx = max(8, $bar['width_px'] - $barInsetLeft - $barInsetRight);
                                    @endphp
                                    <div
                                        x-data="plannerGanttBar()"
                                        wire:key="gantt-bar-{{ $task->id }}-{{ $bar['segment_index'] ?? 0 }}"
                                        style="display: contents;"
                                    >
                                        {{-- Actual bar (stays in place during drag; dims for visual feedback) --}}
                                        <div
                                            x-ref="bar"
                                            @mouseenter="hovered = true; $dispatch('gantt-bar-hover', { taskId: {{ $task->id }} })"
                                            @mouseleave="hovered = false; $dispatch('gantt-bar-hover', { taskId: null })"
                                            data-gantt-bar-id="{{ $task->id }}"
                                            data-task-id="{{ $task->id }}"
                                            data-start-date="{{ $bar['start_date'] }}"
                                            data-end-date="{{ $bar['end_date'] }}"
                                            data-segment-start="{{ $bar['start_date'] }}"
                                            data-segment-end="{{ $bar['end_date'] }}"
                                            class="group/bar absolute rounded-md overflow-hidden bg-white/80 dark:bg-zinc-800/80 ring-1 ring-zinc-200 dark:ring-zinc-700 shadow-sm select-none cursor-grab active:cursor-grabbing hover:bg-white dark:hover:bg-zinc-800 hover:shadow-md hover:ring-zinc-300 dark:hover:ring-zinc-600 {{ $task->trashed() ? 'opacity-50' : '' }}"
                                            :class="{
                                                'opacity-30': dragging,
                                                'opacity-60 cursor-wait pointer-events-none': saving,
                                                'transition-[left,width] duration-150 ease-out': !dragging,
                                            }"
                                            style="
                                                top: {{ $barTop }}px;
                                                height: {{ $barHeight }}px;
                                                left: {{ $barLeftPx }}px;
                                                width: {{ $barWidthPx }}px;
                                                touch-action: none;
                                            "
                                            @pointerdown.self="startDrag($event)"
                                            @click="if (!justDragged) $wire.editTask({{ $task->id }})"
                                            title="{{ $task->title }}{{ $vendor ? ' · ' . $vendor->name : '' }} · {{ $bar['start_date'] }} → {{ $bar['end_date'] }}"
                                        >
                                            {{-- Colored left rail (type color) --}}
                                            <div class="absolute left-0 top-0 bottom-0 w-1 {{ $ui['bg'] }} pointer-events-none"></div>

                                            {{-- Left resize handle --}}
                                            <div
                                                @pointerdown.stop="startResize($event, 'left')"
                                                class="absolute left-0 top-0 bottom-0 w-2 cursor-ew-resize opacity-0 group-hover/bar:opacity-100 {{ $ui['bg_strong'] }} z-10"
                                                style="touch-action: none;"
                                            ></div>

                                            {{-- Card content placeholder INSIDE the bar — kept for layout/click target,
                                                 but the actual visible text lives in the sibling sticky overlay below
                                                 (the bar uses overflow-hidden which traps position:sticky inside it). --}}
                                            <div class="h-full pl-3 pr-2 py-1 pointer-events-none"></div>

                                            {{-- Right resize handle --}}
                                            <div
                                                @pointerdown.stop="startResize($event, 'right')"
                                                class="absolute right-0 top-0 bottom-0 w-2 cursor-ew-resize opacity-0 group-hover/bar:opacity-100 {{ $ui['bg_strong'] }} z-10"
                                                style="touch-action: none;"
                                            ></div>
                                        </div>

                                        {{-- Sticky text overlay: same absolute box as the bar, but overflow-visible
                                             so the inner sticky div can pin the title/time/avatars at the right edge
                                             of the project sidebar while the bar's body scrolls horizontally past. --}}
                                        <div
                                            class="absolute flex flex-col justify-center pointer-events-none"
                                            style="
                                                top: {{ $barTop }}px;
                                                height: {{ $barHeight }}px;
                                                left: {{ $barLeftPx }}px;
                                                width: {{ $barWidthPx }}px;
                                                padding-left: 12px;
                                                padding-right: 8px;
                                                z-index: 5;
                                            "
                                        >
                                            <div style="position: sticky; left: {{ $projectColumnWidth + 8 }}px; display: inline-block; max-width: 100%; min-width: 0;">
                                                @include('components.upcoming-tasks-list-card-content', [
                                                    'task' => $task,
                                                    'date' => $bar['start_date'],
                                                    'isWeekend' => false,
                                                    'showVendorInfo' => false,
                                                    'hideArrivalTime' => $bar['width_px'] < 160,
                                                    'hideDayCounter' => $bar['width_px'] < 160,
                                                ])
                                            </div>
                                        </div>

                                        {{-- Dependency link handles — rendered as SIBLINGS of the bar so they
                                             aren't clipped by the bar's overflow-hidden. Positioned at the bar's
                                             left/right edges in lane coordinates. --}}
                                        <div
                                            @mouseenter="hovered = true"
                                            @mouseleave="hovered = false"
                                            @pointerdown.stop.prevent="$dispatch('gantt-link-start', { taskId: {{ $task->id }}, edge: 'start', event: $event })"
                                            data-link-target="{{ $task->id }}"
                                            data-link-target-edge="start"
                                            class="gantt-link-handle absolute w-3 h-3 rounded-full bg-indigo-500 ring-2 ring-white dark:ring-zinc-900 hover:scale-125 transition cursor-crosshair z-30"
                                            :class="hovered ? 'opacity-100' : 'opacity-0'"
                                            style="
                                                left: {{ $barLeftPx - 6 }}px;
                                                top: {{ $barTop + ($barHeight / 2) - 6 }}px;
                                                touch-action: none;
                                            "
                                            title="Drag to link"
                                        ></div>
                                        <div
                                            @mouseenter="hovered = true"
                                            @mouseleave="hovered = false"
                                            @pointerdown.stop.prevent="$dispatch('gantt-link-start', { taskId: {{ $task->id }}, edge: 'finish', event: $event })"
                                            data-link-target="{{ $task->id }}"
                                            data-link-target-edge="finish"
                                            class="gantt-link-handle absolute w-3 h-3 rounded-full bg-indigo-500 ring-2 ring-white dark:ring-zinc-900 hover:scale-125 transition cursor-crosshair z-30"
                                            :class="hovered ? 'opacity-100' : 'opacity-0'"
                                            style="
                                                left: {{ $barLeftPx + $barWidthPx - 6 }}px;
                                                top: {{ $barTop + ($barHeight / 2) - 6 }}px;
                                                touch-action: none;
                                            "
                                            title="Drag to link"
                                        ></div>



                                        {{-- Ghost preview (shown while dragging/resizing) --}}
                                        <div
                                            x-ref="ghost"
                                            x-show="dragging"
                                            x-cloak
                                            class="absolute rounded-md border-2 border-dashed {{ $ui['border'] }} bg-white/40 dark:bg-zinc-900/40 pointer-events-none z-40 flex items-center justify-center"
                                            style="
                                                display: none;
                                                top: {{ $barTop }}px;
                                                height: {{ $barHeight }}px;
                                                left: {{ $barLeftPx }}px;
                                                width: {{ $barWidthPx }}px;
                                            "
                                        >
                                            <span class="text-[11px] font-semibold {{ $ui['text'] }} bg-white/90 dark:bg-zinc-900/90 px-2 py-0.5 rounded shadow-sm whitespace-nowrap" x-text="ghostLabel"></span>
                                        </div>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="p-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        No scheduled tasks for the current filters.
                    </div>
                @endforelse
            </div>

            {{-- SVG arrow overlay. Paths are rendered SERVER-SIDE from $ganttArrowPaths
                 so they morph atomically with the bars — no JS measurement, no flicker,
                 no race with rapid updates. The Alpine layer only handles live drag preview
                 and the hover-to-highlight effect. --}}
            <svg
                x-ref="arrows"
                class="absolute top-0 left-0 z-20"
                :width="totalWidth"
                :height="totalHeight"
                style="overflow: visible; pointer-events: none;"
            >
                <defs>
                    <marker id="gantt-arrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                        <path d="M0,0 L8,4 L0,8 Z" fill="rgba(120,120,120,0.7)" />
                    </marker>
                    <marker id="gantt-arrow-critical" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                        <path d="M0,0 L8,4 L0,8 Z" fill="#ef4444" />
                    </marker>
                    <marker id="gantt-arrow-highlight" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                        <path d="M0,0 L8,4 L0,8 Z" fill="#6366f1" />
                    </marker>
                    <marker id="gantt-arrow-preview" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                        <path d="M0,0 L8,4 L0,8 Z" fill="#6366f1" />
                    </marker>
                </defs>
                @foreach ($ganttArrowPaths as $arrow)
                    @php
                        $mineExpr   = "(hoveredArrowKey === '{$arrow['key']}' || hoveredTaskId === {$arrow['predecessor_id']} || hoveredTaskId === {$arrow['successor_id']})";
                        $dimmedExpr = "((hoveredArrowKey || hoveredTaskId) && !{$mineExpr})";
                        $baseStroke = $arrow['is_violated'] ? '#ef4444' : 'rgb(120,120,120)';
                        $baseMarker = $arrow['is_violated'] ? 'gantt-arrow-critical' : 'gantt-arrow';
                        $baseOpacity = $arrow['is_violated'] ? '0.55' : '0.3';
                        $baseWidth   = $arrow['is_violated'] ? '1.75' : '1.25';
                    @endphp
                    {{-- Visible stroke + transparent wider hit target on top so hover is forgiving.
                         The path participates in TWO highlight modes:
                           - Arrow hover: shows just this arrow.
                           - Task-bar hover: shows every arrow connected to that task.
                         When neither is active, lines render at a quiet default opacity so
                         overlapping lanes don't compound into visual noise. --}}
                    <g wire:key="gantt-arrow-{{ $arrow['key'] }}" style="pointer-events: none;">
                        <path
                            d="{{ $arrow['d'] }}"
                            fill="none"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                            stroke="{{ $baseStroke }}"
                            marker-end="url(#{{ $baseMarker }})"
                            :opacity="{{ $mineExpr }} ? 1 : ({{ $dimmedExpr }} ? 0.08 : {{ $baseOpacity }})"
                            :stroke-width="{{ $mineExpr }} ? 2.5 : {{ $baseWidth }}"
                            style="transition: opacity 120ms ease, stroke 120ms ease, stroke-width 120ms ease;"
                        />
                        <path
                            d="{{ $arrow['d'] }}"
                            fill="none"
                            stroke="transparent"
                            stroke-width="14"
                            style="pointer-events: stroke; cursor: pointer;"
                            @mouseenter="hoveredArrowKey = '{{ $arrow['key'] }}'"
                            @mouseleave="hoveredArrowKey = null"
                        />
                    </g>
                @endforeach
            </svg>
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('plannerGantt', (config) => ({
        ...window.plannerInfiniteScroll('scroller'),

        pxPerDay: config.pxPerDay,
        firstDay: config.firstDay,           // 'YYYY-MM-DD'
        lastDay: config.lastDay,
        projectColumnWidth: config.projectColumnWidth,
        headerHeight: config.headerHeight,
        rowHeight: config.rowHeight,
        rowPadding: config.rowPadding,
        totalWidth: 0,
        totalHeight: 0,
        _saveScrollTimer: 0,
        hoveredArrowKey: null,
        hoveredTaskId: null,

        // sessionStorage key for the horizontal scroll position. Persisting per-zoom
        // ensures the viewport returns to where the user was — even after a full
        // Livewire morph re-instantiates this Alpine component.
        _scrollKey() {
            return `gantt-scroll-left:${this.pxPerDay}`;
        },

        init() {
            this._initInfiniteScroll();
            this.$nextTick(() => {
                this.measure();
                this.updateArrowClip();

                // Restore prior scroll position if we have one; otherwise center on today.
                const saved = sessionStorage.getItem(this._scrollKey());
                if (saved !== null && this.$refs.scroller) {
                    this.$refs.scroller.scrollLeft = parseInt(saved, 10) || 0;
                } else {
                    this.scrollToToday();
                }
            });

            // After every server commit, re-measure (bars may have moved) and
            // re-apply the sidebar clip. Scroll preservation for infinite-load
            // prepends is now handled by the ResizeObserver in the mixin —
            // there's nothing to do here for that.
            this._commitHook = window.Livewire?.hook?.('commit', ({ component, succeed }) => {
                if (!this.$wire || component.id !== this.$wire.$id) return;
                succeed(() => {
                    this.measure();
                    this.updateArrowClip();
                });
            });
        },

        destroy() {
            this._destroyInfiniteScroll?.();
            if (typeof this._commitHook === 'function') this._commitHook();
            if (this._saveScrollTimer) clearTimeout(this._saveScrollTimer);
        },

        // Called by the shared infinite-scroll mixin after a load completes.
        onInfiniteLoad() {
            this._syncRangeFromDom();
            this.measure();
            this.updateArrowClip();
        },

        // Read the actual first/last day cells so g.firstDay stays in sync with the
        // server-rendered range after loadPreviousDays / loadFutureDays.
        _syncRangeFromDom() {
            const cells = this.$refs.scroller?.querySelectorAll('[data-date]');
            if (!cells || cells.length === 0) return;
            this.firstDay = cells[0].dataset.date;
            this.lastDay  = cells[cells.length - 1].dataset.date;
        },

        measure() {
            if (!this.$refs.grid) return;
            this.totalWidth = this.$refs.grid.scrollWidth;
            this.totalHeight = this.$refs.grid.scrollHeight;
        },

        // Persist scroll position + keep the sidebar clip in sync as the user scrolls.
        // Runs at most once per animation frame (mixin coalesces scroll events via rAF),
        // and the sessionStorage write is debounced so momentum scroll doesn't thrash it.
        onInfiniteScrollFrame() {
            this.updateArrowClip();
            if (this._saveScrollTimer) clearTimeout(this._saveScrollTimer);
            this._saveScrollTimer = setTimeout(() => {
                this._saveScrollTimer = 0;
                if (this.$refs.scroller) {
                    sessionStorage.setItem(this._scrollKey(), String(this.$refs.scroller.scrollLeft));
                }
            }, 150);
        },

        // Clip the SVG arrow layer so it never paints under the sticky project sidebar.
        updateArrowClip() {
            if (!this.$refs.arrows || !this.$refs.scroller) return;
            const cutoff = this.$refs.scroller.scrollLeft + this.projectColumnWidth;
            this.$refs.arrows.style.clipPath = `inset(0 0 0 ${cutoff}px)`;
        },

        scrollToToday() {
            const el = this.$refs.scroller?.querySelector('[data-today]');
            if (!el || !this.$refs.scroller) return;
            const scroller = this.$refs.scroller;
            const elRect = el.getBoundingClientRect();
            const scRect = scroller.getBoundingClientRect();
            scroller.scrollLeft += (elRect.left - scRect.left) - (scRect.width / 3);
        },

        // Return the {x,y} of a task's "edge" in grid coords. Used only by the
        // drag-to-link preview path (committed arrows are rendered server-side).
        // When a task is split into multiple bar segments we pick the rightmost
        // for finish edges and the leftmost for start edges.
        barEdge(taskId, side) {
            const els = this.$refs.grid?.querySelectorAll(`[data-gantt-bar-id="${taskId}"]`);
            if (!els || els.length === 0) return null;
            const gridRect = this.$refs.grid.getBoundingClientRect();
            let chosen = els[0];
            if (els.length > 1) {
                for (const el of els) {
                    const r = el.getBoundingClientRect();
                    const c = chosen.getBoundingClientRect();
                    if (side === 'right'  && r.right > c.right) chosen = el;
                    if (side === 'left'   && r.left  < c.left)  chosen = el;
                }
            }
            const r = chosen.getBoundingClientRect();
            return {
                x: side === 'right' ? r.right - gridRect.left : r.left - gridRect.left,
                y: r.top - gridRect.top + (r.height / 2),
            };
        },

        // Orthogonal elbow path — shared with the server-side renderer. Source exits
        // horizontally, runs through the row gap, then enters the target horizontally.
        _elbowPath(x1, y1, x2, y2, sourceSide, targetSide) {
            const exit = 14;
            const sX = sourceSide === 'right' ? x1 + exit : x1 - exit;
            const tX = targetSide === 'right' ? x2 + exit : x2 - exit;
            const midY = (y1 + y2) / 2;
            return `M ${x1} ${y1} L ${sX} ${y1} L ${sX} ${midY} L ${tX} ${midY} L ${tX} ${y2} L ${x2} ${y2}`;
        },

        // ---- Drag-to-link dependency creation -------------------------------
        _linkDrag: null,        // { sourceTaskId, sourceEdge, x1, y1, path, hoverEl }
        _onLinkMove: null,
        _onLinkUp: null,

        startLinkDrag(detail) {
            if (!detail || !this.$refs.grid || !this.$refs.arrows) return;
            const evt = detail.event;
            const side = detail.edge === 'finish' ? 'right' : 'left';
            const source = this.barEdge(detail.taskId, side);
            if (!source) return;

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('class', 'gantt-link-preview');
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', '#6366f1');
            path.setAttribute('stroke-width', '2');
            path.setAttribute('stroke-dasharray', '4 3');
            path.setAttribute('stroke-linejoin', 'round');
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('marker-end', 'url(#gantt-arrow-preview)');
            this.$refs.arrows.appendChild(path);

            this._linkDrag = {
                sourceTaskId: detail.taskId,
                sourceEdge: detail.edge,
                sourceSide: side,
                x1: source.x, y1: source.y,
                path, hoverEl: null,
            };

            this._onLinkMove = (e) => this._linkMove(e);
            this._onLinkUp   = (e) => this._linkUp(e);
            window.addEventListener('pointermove', this._onLinkMove);
            window.addEventListener('pointerup', this._onLinkUp, { once: false });

            // Seed first position from the originating pointer event.
            if (evt) this._linkMove(evt);
        },

        _linkMove(e) {
            if (!this._linkDrag) return;
            const gridRect = this.$refs.grid.getBoundingClientRect();
            const x2 = e.clientX - gridRect.left;
            const y2 = e.clientY - gridRect.top;
            const { x1, y1, sourceSide, path } = this._linkDrag;
            // Use the same elbow routing as committed links; assume target enters from
            // the opposite side of the source to give a sensible preview.
            const targetSide = sourceSide === 'right' ? 'left' : 'right';
            path.setAttribute('d', this._elbowPath(x1, y1, x2, y2, sourceSide, targetSide));

            // Highlight a target handle if we're hovering one.
            const el = document.elementFromPoint(e.clientX, e.clientY);
            const handle = el?.closest?.('[data-link-target]');
            if (this._linkDrag.hoverEl && this._linkDrag.hoverEl !== handle) {
                this._linkDrag.hoverEl.classList.remove('!opacity-100', 'scale-150', 'bg-emerald-500');
            }
            if (handle && handle.dataset.linkTarget != this._linkDrag.sourceTaskId) {
                handle.classList.add('!opacity-100', 'scale-150', 'bg-emerald-500');
                this._linkDrag.hoverEl = handle;
            } else {
                this._linkDrag.hoverEl = null;
            }
        },

        _linkUp(e) {
            if (!this._linkDrag) return;
            const drag = this._linkDrag;
            this._linkDrag = null;
            window.removeEventListener('pointermove', this._onLinkMove);
            window.removeEventListener('pointerup', this._onLinkUp);

            // Cleanup hover styling and preview path.
            if (drag.hoverEl) {
                drag.hoverEl.classList.remove('!opacity-100', 'scale-150', 'bg-emerald-500');
            }
            drag.path?.remove();

            // Determine drop target: either a link handle or any bar.
            const el = document.elementFromPoint(e.clientX, e.clientY);
            const handle = el?.closest?.('[data-link-target]');
            const bar    = el?.closest?.('[data-gantt-bar-id]');

            let targetId = null, targetEdge = null;
            if (handle) {
                targetId   = parseInt(handle.dataset.linkTarget, 10);
                targetEdge = handle.dataset.linkTargetEdge;
            } else if (bar) {
                targetId = parseInt(bar.dataset.ganttBarId, 10);
                // Default to dropping on the bar's start edge for finish_to_start.
                targetEdge = drag.sourceEdge === 'finish' ? 'start' : 'finish';
            }

            if (!targetId || targetId === drag.sourceTaskId) return;

            this.$wire.createDependencyLink(
                drag.sourceTaskId,
                drag.sourceEdge,
                targetId,
                targetEdge,
            );
        },
    }));

    Alpine.data('plannerGanttBar', () => ({
        dragging: false,
        saving: false,
        justDragged: false,
        hovered: false,
        ghostLabel: '',

        // Per-drag state (re-initialized on each pointerdown)
        _mode: null,            // 'move' | 'left' | 'right'
        _pointerId: null,
        _captureEl: null,
        _startX: 0,
        _initialLeft: 0,
        _initialWidth: 0,
        _pxPerDay: 0,
        _lastDx: 0,
        _maxAbsDx: 0,
        _rafPending: false,
        _taskId: 0,
        _segStart: null,
        _segEnd: null,
        _ghostLeft: 0,
        _ghostWidth: 0,

        _gantt() {
            let parent = this.$el.parentElement;
            while (parent && !parent._x_dataStack) parent = parent.parentElement;
            return parent ? Alpine.$data(parent) : null;
        },

        startDrag(e) {
            if (e.button !== 0 || this.saving) return;
            this._begin(e, 'move', e.currentTarget);
        },
        startResize(e, side) {
            if (e.button !== 0 || this.saving) return;
            this._begin(e, side, e.currentTarget);
        },
        _begin(e, mode, captureEl) {
            const g = this._gantt();
            if (!g) return;
            const bar = this.$refs.bar;
            const ghost = this.$refs.ghost;
            if (!bar || !ghost) return;

            // Re-read fresh segment dates from data-* attrs on the bar (stable through morphs).
            // We deliberately use ONLY data-segment-start/end as the source of truth so there's
            // nothing to diverge from. data-start-date/data-end-date are kept on the element for
            // backwards-compat but are not read here.
            this._taskId   = parseInt(bar.dataset.taskId, 10);
            this._segStart = bar.dataset.segmentStart;
            this._segEnd   = bar.dataset.segmentEnd;

            if (!Number.isInteger(this._taskId) || this._taskId <= 0) {
                console.warn('[gantt] aborting drag: missing/invalid taskId on bar', bar);
                return;
            }

            this._mode = mode;
            this._pointerId = e.pointerId;
            this._captureEl = captureEl;
            this._startX = e.clientX;
            this._initialLeft  = parseFloat(bar.style.left)  || 0;
            this._initialWidth = parseFloat(bar.style.width) || 0;
            this._ghostLeft  = this._initialLeft;
            this._ghostWidth = this._initialWidth;
            this._pxPerDay = g.pxPerDay;
            this._lastDx = 0;
            this._maxAbsDx = 0;

            // Pre-position ghost on top of the bar.
            ghost.style.left  = this._initialLeft  + 'px';
            ghost.style.width = this._initialWidth + 'px';
            this._updateGhostLabel(0);
            this.dragging = true;

            e.preventDefault();

            // Capture on the element that received the event so we keep receiving
            // pointermove/up even when the pointer leaves it.
            try { captureEl.setPointerCapture(e.pointerId); } catch {}
            captureEl.addEventListener('pointermove',   this._onPointerMove);
            captureEl.addEventListener('pointerup',     this._onPointerUp);
            captureEl.addEventListener('pointercancel', this._onPointerUp);
        },
        _onPointerMove(e) {
            if (!this.dragging || e.pointerId !== this._pointerId) return;
            this._lastDx = e.clientX - this._startX;
            const absDx = Math.abs(this._lastDx);
            if (absDx > this._maxAbsDx) this._maxAbsDx = absDx;
            if (this._rafPending) return;
            this._rafPending = true;
            requestAnimationFrame(() => this._applyGhost());
        },
        _applyGhost() {
            this._rafPending = false;
            if (!this.dragging) return;
            const snap = this._pxPerDay;
            const snappedDx = Math.round(this._lastDx / snap) * snap;
            const ghost = this.$refs.ghost;
            if (!ghost) return;

            if (this._mode === 'move') {
                this._ghostLeft  = this._initialLeft + snappedDx;
                this._ghostWidth = this._initialWidth;
            } else if (this._mode === 'left') {
                const newWidth = this._initialWidth - snappedDx;
                if (newWidth < snap) return;
                this._ghostLeft  = this._initialLeft + snappedDx;
                this._ghostWidth = newWidth;
            } else if (this._mode === 'right') {
                const newWidth = this._initialWidth + snappedDx;
                if (newWidth < snap) return;
                this._ghostLeft  = this._initialLeft;
                this._ghostWidth = newWidth;
            }
            ghost.style.left  = this._ghostLeft  + 'px';
            ghost.style.width = this._ghostWidth + 'px';
            this._updateGhostLabel(Math.round(snappedDx / snap));
        },
        _updateGhostLabel(dayDelta) {
            const days = Math.max(1, Math.round(this._ghostWidth / this._pxPerDay));
            const dayWord = days === 1 ? 'day' : 'days';
            if (this._mode === 'move') {
                const sign = dayDelta > 0 ? '+' : '';
                this.ghostLabel = `${sign}${dayDelta}d · ${days} ${dayWord}`;
            } else {
                this.ghostLabel = `${days} ${dayWord}`;
            }
        },
        _onPointerUp(e) {
            if (e.pointerId !== this._pointerId) return;
            const captureEl = this._captureEl;
            captureEl.removeEventListener('pointermove',   this._onPointerMove);
            captureEl.removeEventListener('pointerup',     this._onPointerUp);
            captureEl.removeEventListener('pointercancel', this._onPointerUp);
            try { captureEl.releasePointerCapture(this._pointerId); } catch {}

            const snap = this._pxPerDay;
            const dayDelta = Math.round(this._lastDx / snap);

            // Suppress the trailing click if the pointer moved at all (even sub-snap).
            if (this._maxAbsDx > 3 || dayDelta !== 0) {
                this.justDragged = true;
                setTimeout(() => { this.justDragged = false; }, 250);
            }

            const mode = this._mode;
            this._mode = null;
            this._pointerId = null;
            this._captureEl = null;
            this.dragging = false;

            if (dayDelta === 0) return;

            // Compute new segment dates by applying dayDelta to the original segment dates
            // captured at drag start.
            if (!this._segStart || !this._segEnd) {
                console.warn('[gantt] aborting save: missing original segment dates', { taskId: this._taskId });
                return;
            }

            const newStart = this._addDays(this._segStart, mode === 'right' ? 0 : dayDelta);
            let   newEnd   = this._addDays(this._segEnd,   mode === 'left'  ? 0 : dayDelta);
            if (newEnd < newStart) newEnd = newStart;

            // Capture for logging BEFORE we send (no local mutation of segment dates —
            // we rely on Livewire's morph response to provide the new server-truth values
            // when the next drag begins).
            const oldStart = this._segStart;
            const oldEnd   = this._segEnd;

            // Optimistic visual position only (no data-* attr writes — those would drift
            // out of sync with the server response and corrupt subsequent drags).
            const barEl = this.$refs.bar;
            if (barEl) {
                barEl.style.left  = this._ghostLeft  + 'px';
                barEl.style.width = this._ghostWidth + 'px';
            }

            console.log('[gantt] updateTaskDates', { taskId: this._taskId, mode, dayDelta, newStart, newEnd, oldStart, oldEnd });

            this.saving = true;
            this.$wire.updateTaskDates(this._taskId, newStart, newEnd, oldStart, oldEnd)
                .finally(() => { this.saving = false; });
        },
        _addDays(ymd, n) {
            if (!ymd || typeof ymd !== 'string') return ymd;
            const [y, m, d] = ymd.split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d));
            dt.setUTCDate(dt.getUTCDate() + n);
            const yy = dt.getUTCFullYear();
            const mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
            const dd = String(dt.getUTCDate()).padStart(2, '0');
            return `${yy}-${mm}-${dd}`;
        },

        init() {
            // Pre-bind handlers so add/removeEventListener get identical references.
            this._onPointerMove = this._onPointerMove.bind(this);
            this._onPointerUp   = this._onPointerUp.bind(this);
        },
    }));
</script>
@endscript

