<?php
/**
 * Single source of truth for day-cell visual styling shared between
 * the gantt view (_gantt.blade.php) and the table view (cards.blade.php).
 *
 * Loaded via `@php require resource_path('views/livewire/planner/_day-classes.php'); @endphp`
 * so the variables land in the parent view's scope (Blade @include does NOT
 * share variables defined inside the included file back to the parent).
 */

/** Grid line + row separator color (solid, no alpha). */
$dayBorderClass = 'border-zinc-200 dark:border-zinc-700';

/** Background tint for weekend day columns/cells. */
$dayWeekendBgClass = 'bg-zinc-50 dark:bg-zinc-800';

/** Muted text color for weekend day headers. */
$dayWeekendTextClass = 'text-zinc-400 dark:text-zinc-500';

/** Default (weekday) text color for day headers. */
$dayWeekdayTextClass = 'text-zinc-600 dark:text-zinc-300';

/** Background tint for the today column/cell. */
$dayTodayBgClass = 'bg-indigo-100/70 dark:bg-indigo-900/25';

/** Highlight text color for the today day header. */
$dayTodayTextClass = '!text-indigo-600 dark:!text-indigo-400 font-bold';

/** Inline "Today" pill (used inside the day header on both views). */
$dayTodayPillClass = 'inline-flex items-center rounded bg-indigo-100 px-1.5 py-0 text-[10px] font-medium leading-4 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300';
