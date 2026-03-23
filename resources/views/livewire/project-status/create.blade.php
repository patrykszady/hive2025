<div wire:transition>
    {{-- PROJECT LIFESPAN / STATUS --}}
    <flux:card class="space-y-6">
        <flux:accordion transition>
            <flux:accordion.item expanded>
                <flux:accordion.heading>
                    <flux:heading size="lg" class="mb-0">Project Timeline</flux:heading>
                </flux:accordion.heading>

                <flux:accordion.content>
                    <flux:timeline class="mt-6" align="start" style="--flux-timeline-indicator-size: 1.5rem;">
                        @foreach($statuses as $status)
                            <flux:timeline.item class="group">
                                @if($loop->last)
                                    <flux:timeline.indicator variant="bare">
                                        <div class="size-5 rounded-full flex items-center justify-center opacity-40" style="border: 2px solid {{ $status->dotColor }}">
                                            <div class="size-2.5 rounded-full opacity-100" style="background-color: {{ $status->dotColor }}"></div>
                                        </div>
                                    </flux:timeline.indicator>
                                @else
                                    <flux:timeline.indicator variant="bare">
                                        <div class="size-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                    </flux:timeline.indicator>
                                @endif

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

                                    @if($loop->last)
                                        @php
                                            $daysSince = floor(abs(now()->diffInDays($status->start_date)));
                                            $sinceText = $daysSince > 0
                                                ? $daysSince . ' day' . ($daysSince === 1 ? '' : 's') . ' since'
                                                : 'today';
                                        @endphp
                                        <div class="text-xs italic text-gray-400">{{ $sinceText }}</div>
                                    @elseif($loop->index < count($statuses) - 1)
                                        @php
                                            $nextStatus = $statuses[$loop->index + 1];
                                            $diffInDays = floor(abs($nextStatus->start_date->diffInDays($status->start_date)));
                                            $timeText = $diffInDays > 0
                                                ? $diffInDays . ' day' . ($diffInDays === 1 ? '' : 's') . ' later'
                                                : 'same day';
                                        @endphp
                                        <div class="text-xs italic text-gray-400">{{ $timeText }}</div>
                                    @endif
                                </flux:timeline.content>
                            </flux:timeline.item>
                        @endforeach

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
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    </flux:card>

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
