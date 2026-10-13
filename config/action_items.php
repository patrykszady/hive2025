<?php

/*
|--------------------------------------------------------------------------
| Action items from calls and texts (ProjectActionItemExtractor)
|--------------------------------------------------------------------------
*/

return [
    // An open item nobody has mentioned for this many days lapses out of
    // the planner list (it stays on record; a new mention makes a new row).
    'stale_days' => (int) env('ACTION_ITEMS_STALE_DAYS', 30),

    // Dated items lapse this many days after their day.
    'overdue_grace_days' => (int) env('ACTION_ITEMS_OVERDUE_GRACE_DAYS', 1),

    // The planner modal lists items mentioned within this many days up front;
    // the rest wait behind an "older" count.
    'list_days' => (int) env('ACTION_ITEMS_LIST_DAYS', 14),

    // "Tomorrow" and "Monday" in a call are the business's days, not UTC's.
    'timezone' => env('ACTION_ITEMS_TIMEZONE', 'America/Chicago'),
];
