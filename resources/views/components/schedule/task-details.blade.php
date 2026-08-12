@props([
    'task',
    // Interactive mode renders each open item as a checkbox the viewer can
    // tick — the host component must expose toggleChecklistItem(taskId, i).
    'interactive' => false,
])

{{-- Checklist + notes sub-card: "Electrical Issues" says nothing about WHICH
     issues — the list and notes belong on the card, not behind a modal. --}}
@php
    $detailChecklist = collect((array) data_get($task->options, 'checklist', []))
        ->map(fn ($item) => is_scalar($item) ? ['text' => $item] : (array) $item)
        ->map(fn (array $item) => [
            'text' => trim((string) ($item['text'] ?? '')),
            'completed' => (bool) ($item['completed'] ?? false),
        ]);
    $detailNotes = trim((string) ($task->notes ?? ''));

    // Checking work off before anyone is on site invites premature ticks —
    // the boxes go live on the task's scheduled day, not before.
    $interactive = $interactive
        && $task->start_date
        && $task->start_date->copy()->startOfDay()->lte(today(browser_timezone()));
    $hasVisibleItems = $detailChecklist->contains(fn (array $item) => $item['text'] !== '');
@endphp

@if($hasVisibleItems || $detailNotes !== '')
    <div class="mt-2 space-y-1.5 rounded-lg border border-zinc-200 bg-white p-2 text-xs dark:border-zinc-700 dark:bg-zinc-900">
        @if($hasVisibleItems)
            <ul class="space-y-1">
                {{-- Original indexes preserved: blank rows are skipped for
                     display but the toggle writes back by real position. --}}
                @foreach($detailChecklist as $index => $item)
                    @if($item['text'] !== '')
                        <li wire:key="task-{{ $task->id }}-check-{{ $index }}">
                            @if($interactive)
                                <button
                                    type="button"
                                    wire:click.stop="toggleChecklistItem({{ $task->id }}, {{ $index }})"
                                    wire:loading.attr="disabled"
                                    class="flex w-full items-start gap-1.5 text-start"
                                >
                                    @if($item['completed'])
                                        <span class="mt-0.5 grid size-3.5 shrink-0 place-items-center rounded-[4px] bg-green-600 dark:bg-green-500"><flux:icon.check variant="micro" class="size-2.5 text-white" /></span>
                                        <span class="text-zinc-400 line-through dark:text-zinc-500">{{ $item['text'] }}</span>
                                    @else
                                        <span class="mt-0.5 size-3.5 shrink-0 rounded-[4px] border border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-900"></span>
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ $item['text'] }}</span>
                                    @endif
                                </button>
                            @else
                                <div class="flex items-start gap-1.5">
                                    @if($item['completed'])
                                        <span class="mt-0.5 grid size-3.5 shrink-0 place-items-center rounded-[4px] bg-green-600 dark:bg-green-500"><flux:icon.check variant="micro" class="size-2.5 text-white" /></span>
                                        <span class="text-zinc-400 line-through dark:text-zinc-500">{{ $item['text'] }}</span>
                                    @else
                                        <span class="mt-0.5 size-3.5 shrink-0 rounded-[4px] border border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-900"></span>
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ $item['text'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif

        @if($detailNotes !== '')
            <p class="whitespace-pre-line text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($detailNotes, 200) }}</p>
        @endif
    </div>
@endif
