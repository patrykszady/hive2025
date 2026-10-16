@php
    // Derive defaults from $task + $date if not explicitly passed
    $typeUi = $task->type_ui ?? [];
    $taskTypeTextClasses = $taskTypeTextClasses ?? data_get($typeUi, 'text', '');
    $taskUsers = $taskUsers ?? ($task->users ?? collect());
    $taskVendor = $taskVendor ?? ($task->vendor ?? null);
    $showAvatars = $showAvatars ?? true;
    $showProjectInfo = $showProjectInfo ?? false;
    $showVendorInfo = $showVendorInfo ?? true;

    // Compact (the planner): the checklist and notes fold behind a small
    // "1/3 to-dos · Notes" chip that opens them in place, so a day of cards
    // reads as a schedule instead of a wall of instructions. Elsewhere (the
    // subs' schedule page) they stay open.
    $compact = $compact ?? false;
    $compactChecklist = collect((array) data_get($task->options, 'checklist', []))
        ->map(fn ($item) => is_scalar($item) ? ['text' => $item] : (array) $item)
        ->filter(fn (array $item) => trim((string) ($item['text'] ?? '')) !== '');
    $compactNotes = trim((string) ($task->notes ?? ''));
    $hasCompactNotes = $compactNotes !== '' && ! \Illuminate\Support\Str::startsWith($compactNotes, 'Booked from lead email reply');

    // Arrival time — use model method if not pre-computed
    if (! isset($arrivalTimeLabel)) {
        $dayFormat = isset($date)
            ? ($date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date)
            : null;
        $arrivalTimeLabel = $dayFormat ? $task->getArrivalTimeLabel($dayFormat) : null;
    }

    // Previous arrival time — auto-compute if not explicitly passed
    if (! isset($previousArrivalTimeLabel)) {
        if (! isset($dayFormat)) {
            $dayFormat = isset($date)
                ? ($date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date)
                : null;
        }
        $previousArrivalTimeLabel = $dayFormat ? $task->getPreviousArrivalTimeLabel($dayFormat) : null;
    }

    // Day counter — compute from task options if not pre-set
    if (! isset($showDayCounter)) {
        $selectedDates = data_get($task->options, 'dates', []);
        $totalDays = count($selectedDates);
        $currentDay = 0;
        $showDayCounter = false;

        if (isset($date) && ! empty($selectedDates)) {
            $dayFormat = $date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date;
            sort($selectedDates);
            $currentDay = array_search($dayFormat, $selectedDates);
            if ($currentDay !== false) {
                $currentDay++;
            }
            $showDayCounter = $totalDays > 1 && $currentDay > 0;
        }
    }

@endphp

<div class="flex items-start justify-between gap-2 min-w-0">
    <div class="{{ ($isWeekend ?? false) ? 'min-w-0' : 'flex items-baseline gap-2 min-w-0' }}">
        <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }} {{ $task->trashed() ? 'line-through' : '' }}">
            {{ $task->title }}
        </flux:heading>
        @if ($arrivalTimeLabel && ! ($hideArrivalTime ?? false))
            <span class="shrink-0 text-xs whitespace-nowrap">
                <span class="text-zinc-500 dark:text-zinc-400">{{ $arrivalTimeLabel }}</span>
                @if ($previousArrivalTimeLabel ?? null)
                    <span class="line-through text-zinc-400 dark:text-zinc-500">{{ $previousArrivalTimeLabel }}</span>
                @endif
            </span>
        @endif
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <x-task-preferred-indicator :task="$task" />
        @if($showDayCounter && ! ($hideDayCounter ?? false))
            <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                {{ $currentDay }}/{{ $totalDays }}
            </span>
        @endif
    </div>
</div>

@if(($showProjectInfo ?? false) && $task->project)
    <div class="mt-1 space-y-0.5">
        <a wire:navigate.hover 
            href="{{ route('projects.show', $task->project) }}"
            wire:click.stop
            class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 hover:underline truncate"
        >
            {{ $task->project->short_address ?? 'No project' }}
        </a>
        @if($task->project->client)
            <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
                {{ $task->project->client->last_names }}
            </div>
        @endif
    </div>
@endif

@if($showAvatars && ($taskUsers->count() > 0 || $taskVendor))
    <div class="flex items-center gap-2 mt-2 min-w-0">
        @if($taskUsers->count() > 0)
            <flux:avatar.group>
                @foreach($taskUsers->take(3) as $user)
                    <flux:tooltip :content="$user->full_name" position="top">
                        <flux:avatar
                            circle
                            size="xs"
                            name="{{ $user->full_name }}"
                            color="auto"
                            color:seed="{{ $user->id }}"
                        />
                    </flux:tooltip>
                @endforeach
                @if($taskUsers->count() > 3)
                    <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                @endif
            </flux:avatar.group>
        @endif

        @if($taskVendor)
            <flux:avatar
                circle
                size="xs"
                name="{{ $taskVendor->name }}"
                color="auto"
                color:seed="{{ $taskVendor->id }}"
                :title="($showVendorInfo ?? true) ? $taskVendor->name : null"
            />
            @if($showVendorInfo ?? true)
                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>

                @if($task->vendor_status)
                    @php $statusUi = $task->vendor_status_ui; @endphp
                    <flux:badge
                        size="sm"
                        :color="$statusUi['flux'] ?? 'zinc'"
                        :icon="$statusUi['icon'] ?? null"
                        :title="$statusUi['label'] ?? ucfirst($task->vendor_status)"
                        class="aspect-square !px-1 justify-center"
                    />
                @endif
            @endif
        @endif
    </div>
@endif

{{-- Checklist + notes sub-card (shared partial). Interactive for team
     members; clicks inside never bubble into the card's edit-modal click.
     Compact cards fold it behind a chip (a span, not a button: the card
     itself is often a <button>). --}}
@if(! ($hideDetails ?? false) && $compact && ($compactChecklist->isNotEmpty() || $hasCompactNotes))
    <div x-data="{ open: false }" x-on:click.stop wire:click.stop class="mt-2">
        <span
            role="button"
            tabindex="0"
            x-on:click="open = ! open"
            x-on:keydown.enter.prevent="open = ! open"
            x-bind:aria-expanded="open.toString()"
            class="inline-flex cursor-pointer items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-200"
        >
            @if($compactChecklist->isNotEmpty())
                <flux:icon.check-circle variant="micro" class="size-3.5" />
                <span class="tabular-nums">{{ $compactChecklist->where('completed', true)->count() }}/{{ $compactChecklist->count() }} to-dos</span>
            @endif
            @if($compactChecklist->isNotEmpty() && $hasCompactNotes)
                <span aria-hidden="true">·</span>
            @endif
            @if($hasCompactNotes)
                <flux:icon.document-text variant="micro" class="size-3.5" />
                <span>Notes</span>
            @endif
            <flux:icon.chevron-down variant="micro" class="size-3 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
        </span>
        <div x-show="open" x-collapse x-cloak>
            <x-schedule.task-details
                :task="$task"
                :interactive="$detailsInteractive ?? (auth()->check() && ! (auth()->user()->is_browsing_as_client ?? false))"
            />
        </div>
    </div>
@elseif(! ($hideDetails ?? false) && ! $compact)
    <div x-on:click.stop wire:click.stop>
        <x-schedule.task-details
            :task="$task"
            :interactive="$detailsInteractive ?? (auth()->check() && ! (auth()->user()->is_browsing_as_client ?? false))"
        />
    </div>
@endif
