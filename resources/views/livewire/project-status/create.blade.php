<div wire:transition>
    {{-- PROJECT LIFESPAN / STATUS --}}
    <flux:card class="space-y-6">
        <flux:accordion transition>
            <flux:accordion.item expanded>
                <flux:accordion.heading>
                    <flux:heading size="lg" class="mb-0">Project Lifespan</flux:heading>
                </flux:accordion.heading>

                <flux:accordion.content>
                    <ul role="list" class="mt-6">
                        @foreach($statuses as $status)
                            <li class="relative flex gap-x-4 pb-1 group">
                                @if(!$loop->last)
                                    <div class="absolute top-0 left-0 flex justify-center w-6 -bottom-1">
                                        <div class="w-px bg-gray-200"></div>
                                    </div>
                                @endif
                                <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                                    @if($loop->last)
                                        <div class="h-6 w-6 rounded-full flex items-center justify-center opacity-30" style="border: 2px solid {{ $status->dotColor }}">
                                            <div class="h-2.5 w-2.5 rounded-full opacity-100" style="background-color: {{ $status->dotColor }}"></div>
                                        </div>
                                    @else
                                        <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                    @endif
                                </div>
                                <div class="flex-auto py-0.5">
                                    <div class="flex items-center gap-2">
                                        <flux:badge size="sm" :color="$status->badgeColor">{{ $status->title }}</flux:badge>
                                        <span class="text-xs text-gray-500">{{$status->start_date->format('m/d/y')}}</span>
                                        
                                        @can('update', $project)
                                            {{-- Edit button - hidden by default, shown on group hover (desktop only) --}}
                                            <button 
                                                wire:click="editStatus({{ $status->id }})"
                                                type="button"
                                                class="hidden md:group-hover:inline-flex text-gray-400 hover:text-indigo-600 transition-colors"
                                                title="Edit status"
                                            >
                                                <flux:icon.pencil variant="micro" />
                                            </button>
                                        @endcan
                                    </div>
                                    
                                    @if($loop->index < count($statuses) - 1)
                                        @php
                                            $nextStatus = $statuses[$loop->index + 1];
                                            $diffInDays = floor(abs($nextStatus->start_date->diffInDays($status->start_date)));
                                            
                                            if ($diffInDays > 0) {
                                                $timeText = $diffInDays . ' day' . ($diffInDays === 1 ? '' : 's') . ' later';
                                            } else {
                                                $timeText = 'same day';
                                            }
                                        @endphp
                                        <div class="text-xs italic text-gray-400 pl-4">{{ $timeText }}</div>
                                    @endif
                                </div>
                                <time datetime="{{$status->start_date}}" class="flex-none py-0.5 text-xs leading-5 text-gray-500">
                                    {{ $status->start_date->diffForHumans() }}
                                </time>
                            </li>
                        @endforeach
                        
                        @php
                            $lastStatus = $statuses->last();
                            $diffInDays = floor(abs(now()->diffInDays($lastStatus->start_date)));
                            
                            if ($diffInDays > 0) {
                                $timeText = $diffInDays . ' day' . ($diffInDays === 1 ? '' : 's') . ' since';
                            } else {
                                $timeText = 'today';
                            }
                        @endphp
                        
                        <li class="relative flex gap-x-4 pb-1">
                            @can('update', $project)
                                <div class="absolute top-0 left-0 flex justify-center w-6 -bottom-1">
                                    <div class="w-px bg-gray-200"></div>
                                </div>
                            @endcan
                            <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                            </div>
                            <div class="flex-auto py-0.5">
                                <div class="text-xs italic text-gray-400 pl-4">{{ $timeText }}</div>
                            </div>
                        </li>
                        
                        @can('update', $project)
                            <li class="relative flex gap-x-4 pt-1">
                                <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                                    <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                </div>
                                <div class="flex-auto">
                                    @include('livewire.project-status._status_controls')
                                </div>
                            </li>
                        @endcan

                    </ul>
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
