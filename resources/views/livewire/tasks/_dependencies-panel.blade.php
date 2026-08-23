@php
    // $task is null while creating — the picker still works, and the chosen
    // predecessors live in $pendingDependencies until save() writes them.
    $task = $form->task;
    $predecessorDeps = $task ? $task->predecessorDependencies : collect();
    $successorDeps = $task ? $task->successorDependencies : collect();
    $pending = collect($pendingDependencies ?? []);
    $hasDeps = $predecessorDeps->isNotEmpty() || $successorDeps->isNotEmpty() || $pending->isNotEmpty();
@endphp

@if(! $task && $pending->isNotEmpty())
    {{-- Chosen but not yet written: they are created the moment you save. --}}
    <div class="space-y-2">
        <flux:heading size="sm">Predecessors (added on save)</flux:heading>
        @foreach($pending as $index => $row)
            <div wire:key="pending-dep-{{ $index }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium">{{ $row['title'] }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ str_replace('_', ' ', $row['type']) }}@if(($row['lag_days'] ?? 0) != 0), {{ $row['lag_days'] }}d lag @endif
                    </div>
                </div>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removePendingDependency({{ $index }})" />
            </div>
        @endforeach
    </div>
@endif

<div class="space-y-6">
    {{-- Add new predecessor --}}
    <div class="space-y-2">
        <flux:heading size="sm">Add Predecessor</flux:heading>
        <flux:text size="xs" class="!text-zinc-500">
            Pick a task in this project that must relate to this one.
        </flux:text>

        @if($this->availableTasks->isEmpty())
            <flux:callout icon="information-circle" color="zinc">
                <flux:callout.text>No other scheduled tasks in this project are available.</flux:callout.text>
            </flux:callout>
        @else
            <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
                <div class="md:col-span-5">
                    <flux:select wire:model="selectedPredecessorId" placeholder="Select task…">
                        @foreach($this->availableTasks as $availableTask)
                            <flux:select.option value="{{ $availableTask->id }}">
                                {{ $availableTask->title }}
                                @if($availableTask->start_date)
                                    — {{ \Carbon\Carbon::parse($availableTask->start_date)->format('M j') }}
                                @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('selectedPredecessorId')
                        <flux:text size="xs" class="!text-rose-500 mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>

                <div class="md:col-span-4">
                    <flux:select wire:model="dependencyType">
                        <flux:select.option value="finish_to_start">Finish → Start</flux:select.option>
                        <flux:select.option value="start_to_start">Start → Start</flux:select.option>
                        <flux:select.option value="finish_to_finish">Finish → Finish</flux:select.option>
                        <flux:select.option value="start_to_finish">Start → Finish</flux:select.option>
                    </flux:select>
                </div>

                <div class="md:col-span-2">
                    <flux:input
                        type="number"
                        wire:model="lagDays"
                        placeholder="Lag"
                        title="Lag days"
                    />
                </div>

                <div class="md:col-span-1">
                    <flux:button
                        type="button"
                        wire:click="addDependency"
                        variant="primary"
                        icon="plus"
                        class="w-full"
                    />
                </div>
            </div>
        @endif
    </div>

    <flux:separator />

    {{-- Predecessors --}}
    <div class="space-y-2">
        <flux:heading size="sm">
            Predecessors
            @if($predecessorDeps->isNotEmpty())
                <flux:badge size="sm" color="zinc">{{ $predecessorDeps->count() }}</flux:badge>
            @endif
        </flux:heading>

        @if($predecessorDeps->isEmpty())
            <flux:text size="xs" class="!text-zinc-400">No predecessors.</flux:text>
        @else
            <div class="space-y-1">
                @foreach($predecessorDeps as $dep)
                    @php $other = $dep->predecessor; @endphp
                    @if($other)
                        <div
                            wire:key="pred-dep-{{ $dep->id }}"
                            class="flex items-center gap-3 px-3 py-2 rounded-md ring-1 ring-zinc-200 dark:ring-zinc-700 bg-white dark:bg-zinc-800"
                        >
                            <flux:icon.arrow-left class="size-4 text-zinc-400 shrink-0" />
                            <button
                                type="button"
                                wire:click="viewDependentTask({{ $other->id }})"
                                class="flex-1 min-w-0 text-left truncate text-sm hover:underline {{ data_get(\App\Models\Task::TYPE_UI, ($other->type ?? 'Task').'.text', '') }}"
                            >
                                {{ $other->title }}
                            </button>
                            <flux:badge size="sm" color="zinc">{{ $dep->type_display }}</flux:badge>
                            @if((int) $dep->lag_days !== 0)
                                <flux:badge size="sm" color="amber">{{ $dep->lag_display }}</flux:badge>
                            @endif
                            @if($dep->isBlocking())
                                <flux:badge size="sm" color="rose" icon="lock-closed">Blocking</flux:badge>
                            @endif
                            <flux:button
                                type="button"
                                wire:click="removeDependency({{ $dep->id }})"
                                wire:confirm="Remove this dependency?"
                                variant="subtle"
                                icon="trash"
                                size="xs"
                            />
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    {{-- Successors --}}
    <div class="space-y-2">
        <flux:heading size="sm">
            Successors
            @if($successorDeps->isNotEmpty())
                <flux:badge size="sm" color="zinc">{{ $successorDeps->count() }}</flux:badge>
            @endif
        </flux:heading>

        @if($successorDeps->isEmpty())
            <flux:text size="xs" class="!text-zinc-400">No successors.</flux:text>
        @else
            <div class="space-y-1">
                @foreach($successorDeps as $dep)
                    @php $other = $dep->successor; @endphp
                    @if($other)
                        <div
                            wire:key="succ-dep-{{ $dep->id }}"
                            class="flex items-center gap-3 px-3 py-2 rounded-md ring-1 ring-zinc-200 dark:ring-zinc-700 bg-white dark:bg-zinc-800"
                        >
                            <flux:icon.arrow-right class="size-4 text-zinc-400 shrink-0" />
                            <button
                                type="button"
                                wire:click="viewDependentTask({{ $other->id }})"
                                class="flex-1 min-w-0 text-left truncate text-sm hover:underline {{ data_get(\App\Models\Task::TYPE_UI, ($other->type ?? 'Task').'.text', '') }}"
                            >
                                {{ $other->title }}
                            </button>
                            <flux:badge size="sm" color="zinc">{{ $dep->type_display }}</flux:badge>
                            @if((int) $dep->lag_days !== 0)
                                <flux:badge size="sm" color="amber">{{ $dep->lag_display }}</flux:badge>
                            @endif
                            <flux:button
                                type="button"
                                wire:click="removeDependency({{ $dep->id }})"
                                wire:confirm="Remove this dependency?"
                                variant="subtle"
                                icon="trash"
                                size="xs"
                            />
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
