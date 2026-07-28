<div wire:transition>
    {{-- PROJECT LIFESPAN / STATUS --}}
    {{-- Heading comes from <x-island-card> like every other card — a hand-rolled
         header here sits at a different height (the shared one reserves a
         2.25rem row for action buttons) and shifts when the skeleton swaps out.
         The history toggle rides in the actions slot. --}}
    <x-island-card heading="Project Timeline" x-data="{ open: false }" :clickable="$statuses->count() > 1">
        {{-- Slot forwarded unconditionally: Blade hoists <x-slot> out of an
             @if, so the guard lives inside. --}}
        <x-slot:actions>
            @if($statuses->count() > 1)
                <button type="button" @click.stop="open = !open" class="flex items-center p-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-pointer" aria-label="Toggle status history">
                    <flux:icon.chevron-down variant="mini" class="transition-transform duration-200" ::class="open && 'rotate-180'" />
                </button>
            @endif
        </x-slot:actions>
        <div>

            @if($statuses->isNotEmpty())
                <flux:timeline class="mt-4" align="start" style="--flux-timeline-indicator-size: 1.5rem;">
                    {{-- Collapsible history (previous statuses) --}}
                    @if($statuses->count() > 1)
                        @foreach($statuses as $status)
                            @if(!$loop->last)
                                <flux:timeline.item x-show="open" x-collapse x-cloak class="group">
                                    <flux:timeline.indicator variant="bare">
                                        <div class="size-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                    </flux:timeline.indicator>

                                    <flux:timeline.content>
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm" :color="$status->badgeColor">{{ $status->title }}</flux:badge>
                                            <span class="text-xs text-gray-500">{{ $status->start_date->format('m/d/y') }}</span>

                                            @can('update', $project)
                                                <button
                                                    wire:click="editStatus({{ $status->id }})"
                                                    type="button"
                                                    class="hidden md:group-hover:inline-flex text-gray-400 hover:text-indigo-600 transition-colors"
                                                    title="Edit status"
                                                >
                                                    <flux:icon.pencil variant="micro" />
                                                </button>
                                            @endcan

                                            <time datetime="{{ $status->start_date }}" class="ml-auto text-xs text-gray-500">
                                                {{ $status->start_date->diffForHumans() }}
                                            </time>
                                        </div>

                                        @php
                                            $nextStatus = $statuses[$loop->index + 1];
                                            $diffInDays = floor(abs($nextStatus->start_date->diffInDays($status->start_date)));
                                            $timeText = $diffInDays > 0
                                                ? $diffInDays . ' day' . ($diffInDays === 1 ? '' : 's') . ' later'
                                                : 'same day';
                                        @endphp
                                        <div class="text-xs italic text-gray-400">{{ $timeText }}</div>
                                    </flux:timeline.content>
                                </flux:timeline.item>
                            @endif
                        @endforeach
                    @endif

                    {{-- Current status (always visible) --}}
                    <flux:timeline.item>
                        <flux:timeline.indicator variant="bare">
                            <div class="size-5 rounded-full flex items-center justify-center opacity-40" style="border: 2px solid {{ $statuses->last()->dotColor }}">
                                <div class="size-2.5 rounded-full opacity-100" style="background-color: {{ $statuses->last()->dotColor }}"></div>
                            </div>
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <div class="flex items-center gap-2 group">
                                <flux:badge size="sm" :color="$statuses->last()->badgeColor">{{ $statuses->last()->title }}</flux:badge>
                                <span class="text-xs text-gray-500">{{ $statuses->last()->start_date->format('m/d/y') }}</span>

                                @can('update', $project)
                                    <button
                                        wire:click="editStatus({{ $statuses->last()->id }})"
                                        type="button"
                                        class="hidden md:group-hover:inline-flex text-gray-400 hover:text-indigo-600 transition-colors"
                                        title="Edit status"
                                    >
                                        <flux:icon.pencil variant="micro" />
                                    </button>
                                @endcan

                                <time datetime="{{ $statuses->last()->start_date }}" class="ml-auto text-xs text-gray-500">
                                    {{ $statuses->last()->start_date->diffForHumans() }}
                                </time>
                            </div>
                            @php
                                $daysSince = floor(abs(now()->diffInDays($statuses->last()->start_date)));
                                $sinceText = $daysSince > 0
                                    ? $daysSince . ' day' . ($daysSince === 1 ? '' : 's') . ' since'
                                    : 'today';
                            @endphp
                            <div class="text-xs italic text-gray-400">{{ $sinceText }}</div>
                        </flux:timeline.content>
                    </flux:timeline.item>

                    {{-- Status change controls --}}
                    @can('update', $project)
                        <flux:timeline.item>
                            <flux:timeline.indicator variant="bare">
                                <div class="size-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                            </flux:timeline.indicator>
                            <flux:timeline.content>
                                @include('livewire.project-status._status_controls')
                            </flux:timeline.content>
                        </flux:timeline.item>
                    @endcan
                </flux:timeline>
            @else
                @can('update', $project)
                    <div class="mt-3">
                        @include('livewire.project-status._status_controls')
                    </div>
                @endcan
            @endif
        </div>
    </x-island-card>

    @can('update', $project)
    {{-- Edit Status Modal --}}
    <x-form-modal name="edit_status_modal" title="Edit Status">
        <form id="edit_status_modal_form" wire:submit="updateStatus" class="space-y-4">
            <flux:select 
                wire:model="editingStatusCode" 
                label="Status" 
                variant="listbox" 
                placeholder="Choose Status..."
            >
                @php($projectStatuses = \App\Models\ProjectStatus::selectableStatuses())
                @foreach($projectStatuses as $status)
                    <flux:select.option :value="$status['code']">
                        <flux:badge size="sm" inset="top bottom" :color="$status['color']">
                            {{ $status['label'] }}
                        </flux:badge>
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="editingStatusDate"
                label="Status Date"
                type="date"
            />
        </form>

        <x-slot name="footer">
            <flux:button 
                wire:click="deleteStatus"
                type="button"
                variant="danger"
            >
                Delete
            </flux:button>
            <flux:spacer />
            <flux:button type="submit" form="edit_status_modal_form" variant="primary">Save Changes</flux:button>
        </x-slot>
    </x-form-modal>
    @endcan
</div>
