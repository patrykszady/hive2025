@props(['task'])

{{--
    Shared homeowner preferred-time indicator used across every task card
    (planner, dashboard, client schedule, vendor availability).

    - Scheduled onto a client submitted preferred slot → green "Service Scheduled"
    - Homeowner submitted times covering this task, still unscheduled → red "Schedule"
    - Homeowner has not chosen times for this task yet → orange "Awaiting Client"
--}}
@php($indicator = $task->preferredTimeIndicator())

@if($indicator === 'scheduled')
    <span {{ $attributes->class('inline-flex items-center gap-1 shrink-0 whitespace-nowrap text-xs text-green-700 dark:text-green-300') }} title="This task has been scheduled for a project with client submitted preferred times">
        <flux:icon.calendar-days variant="micro" class="size-4" />
        <span>Service Scheduled</span>
    </span>
@elseif($indicator === 'schedule')
    <span {{ $attributes->class('inline-flex items-center gap-1 shrink-0 whitespace-nowrap text-xs text-red-600 dark:text-red-400') }} title="Client submitted preferred times; schedule this task in one of those windows">
        <flux:icon.calendar-days variant="micro" class="size-4" />
        <span>Schedule</span>
    </span>
@elseif($indicator === 'pending')
    <span {{ $attributes->class('inline-flex items-center gap-1 shrink-0 whitespace-nowrap text-xs text-orange-600 dark:text-orange-400') }} title="Waiting for client to share preferred times">
        <flux:icon.calendar-days variant="micro" class="size-4" />
        <span>Awaiting Client</span>
    </span>
@endif
