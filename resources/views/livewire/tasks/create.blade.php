<div>
<x-form-modal 
    name="task_create_form_modal" 
    class="!mt-8 w-full max-w-sm" 
    x-on:modal-show.window="$dispatch('reset-tabs'); window.dispatchEvent(new CustomEvent('task-modal-opened'))"
>
    <x-slot name="header">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <flux:heading size="lg" class="!mb-0">{{ str_replace('Task', $form->type ?? 'Task', $view_text['card_title']) }}</flux:heading>
            </div>
            @if(isset($form->task))
                <flux:subheading>{{$form->task->title}}</flux:subheading>
            @endif
        </div>
    </x-slot>

    <!-- Tab Navigation -->
    <div 
        x-data="{ 
            activeTab: 'details',
            taskType: @entangle('form.type').live,
            tabClasses: @js($this->taskTypeTabClasses),
            get activeClasses() { return this.tabClasses[this.taskType] || this.tabClasses.Task }
        }" 
        @reset-tabs.window="activeTab = 'details'"
    >
        <div class="border-b border-gray-200 mb-4">
            <nav class="-mb-px flex space-x-8">
                <button
                    type="button"
                    @click="activeTab = 'details'"
                    :class="activeTab === 'details' ? activeClasses : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="py-2 px-1 border-b-2 font-medium text-sm"
                >
                    Details
                </button>
                <button
                    type="button"
                    @click="activeTab = 'schedule'"
                    :class="activeTab === 'schedule' ? activeClasses : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="py-2 px-1 border-b-2 font-medium text-sm"
                >
                    Dates
                </button>
                @if($view_text['form_submit'] === 'edit' && $form->task)
                    <button
                        type="button"
                        @click="activeTab = 'notes'"
                        :class="activeTab === 'notes' ? activeClasses : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="py-2 px-1 border-b-2 font-medium text-sm"
                    >
                        Notes
                    </button>
                @endif
            </nav>
        </div>

        <form id="task_create_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="grid space-y-4">
            <!-- Task Details Panel -->
            <div x-show="activeTab === 'details'">
                <div class="relative">
                    {{-- TYPE --}}
                    <flux:radio.group wire:model.live="form.type" label="Task Type" variant="segmented">
                        <flux:radio value="Task"><span class="{{ data_get($this->taskTypeTextClasses, 'Task') }}">Task</span></flux:radio>
                        <flux:radio value="Milestone"><span class="{{ data_get($this->taskTypeTextClasses, 'Milestone') }}">Milestone</span></flux:radio>
                        <flux:radio value="Meet"><span class="{{ data_get($this->taskTypeTextClasses, 'Meet') }}">Meet</span></flux:radio>
                        <flux:radio value="Reminder"><span class="{{ data_get($this->taskTypeTextClasses, 'Reminder') }}">Reminder</span></flux:radio>
                    </flux:radio.group>

                    {{-- TITLE --}}
                    <flux:input wire:model.blur="form.title" label="Title" placeholder="Task Title" autofocus/>

                    {{-- PROJECT --}}
                    <x-forms.project-select
                        :projects="$projects"
                        model="form.project_id"
                        placeholder="Assign project..."
                    />

                    {{-- VENDOR --}}
                    <flux:label>Vendor</flux:label>
                    <flux:select
                        wire:model.live="form.vendor_id"
                        variant="listbox"
                        searchable
                        clearable
                        placeholder="Assign vendor..."
                    >
                        @foreach($this->vendors as $vendor)
                            <flux:select.option wire:key="{{$vendor->id}}" value="{{$vendor->id}}">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <flux:avatar size="xs" name="{{ $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}" />
                                    {{$vendor->name}}
                                </div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @if($view_text['form_submit'] === 'edit' && $form->task && $form->task->vendor_id)
                        @php
                            $statusUi = $form->task->vendor_status_ui;
                            $canRespond = in_array($form->task->vendor_status, [\App\Models\Task::VENDOR_STATUS_REQUESTED, \App\Models\Task::VENDOR_STATUS_PROPOSED], true);
                            $isConfirmed = $form->task->vendor_status === \App\Models\Task::VENDOR_STATUS_CONFIRMED;
                            $canToggle = $canRespond || $isConfirmed;
                        @endphp
                        @if($statusUi)
                            <div class="mt-2 flex items-center justify-between">
                                <div class="text-xs text-zinc-500">Vendor Status</div>
                                <flux:button.group>
                                    <flux:badge size="sm" :color="$statusUi['flux'] ?? 'zinc'" :icon="$statusUi['icon'] ?? null" class="rounded-r-none">
                                        {{ $statusUi['label'] ?? ucfirst($form->task->vendor_status) }}
                                    </flux:badge>
                                    @if($isConfirmed)
                                        <flux:button
                                            size="xs"
                                            icon="x-mark"
                                            variant="outline"
                                            class="hover:text-red-600 hover:border-red-600"
                                            wire:click="resetVendorAvailability"
                                            :disabled="! $canToggle"
                                        ></flux:button>
                                    @else
                                        <flux:button
                                            size="xs"
                                            icon="check"
                                            variant="outline"
                                            class="hover:text-green-600 hover:border-green-600"
                                            wire:click="confirmVendorAvailability"
                                            :disabled="! $canToggle"
                                        ></flux:button>
                                    @endif
                                </flux:button.group>
                            </div>
                        @endif
                    @endif

                    {{-- USERS --}}
                    <flux:select wire:model="form.user_ids" multiple label="Team Members" variant="listbox" placeholder="Assign team members...">
                        @foreach($this->employees as $employee)
                            <flux:select.option wire:key="{{$employee->id}}" value="{{$employee->id}}">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <flux:avatar size="xs" name="{{ $employee->full_name }}" color="auto" color:seed="{{ $employee->id }}"  />
                                    {{$employee->first_name}}
                                </div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <!-- Select Days and Arrival Time Panel -->
            <div x-show="activeTab === 'schedule'">
                <div class="relative">
                    {{-- DATES --}}
                    <flux:field>
                        <flux:label>Select Days</flux:label>
                        <flux:dropdown class="w-full" width="trigger">
                            <flux:button class="w-full justify-between" variant="filled">
                                @if(empty($form->dates))
                                    Select dates...
                                @else
                                    {{ count($form->dates) }} {{ Str::plural('day', count($form->dates)) }} selected
                                @endif
                                <flux:icon.chevron-down variant="micro" />
                            </flux:button>
                            
                            <flux:popover class="p-4">
                                <flux:calendar
                                    multiple
                                    with-today
                                    size="sm"
                                    wire:model.live="form.dates"
                                    :error="$errors->has('form.dates')"
                                />
                            </flux:popover>
                        </flux:dropdown>
                        <flux:error name="form.dates" />
                    </flux:field>

                    {{-- TIME SETTINGS --}}
                    @if(!empty($form->dates))
                        <flux:field>
                            <flux:label>Arrival Time</flux:label>
                            
                            <div class="space-y-3 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                                @foreach($form->dates as $date)
                                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <flux:subheading class="text-sm">
                                                {{ \Carbon\Carbon::parse($date)->format('D, M j') }}
                                            </flux:subheading>
                                            <flux:switch 
                                                wire:model.live="form.time_settings.{{ $date }}.use_time"
                                                x-on:change="$wire.copyTimesToDate('{{ $date }}')"
                                                size="sm"
                                            />
                                        </div>

                                        @if($form->time_settings[$date]['use_time'] ?? false)
                                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800 [&_[data-flux-time-picker-button]:not(:has([data-flux-time-picker-placeholder]))>[data-flux-icon]:first-child]:hidden">
                                                <flux:time-picker
                                                    wire:model.live="form.time_settings['{{ $date }}'].start_time"
                                                    wire:change="updateEndTime('{{ $date }}')"
                                                    interval="30"
                                                    min="06:00"
                                                    max="23:00"
                                                    open-to="08:00"
                                                    placeholder="Start"
                                                />
                                                <flux:time-picker
                                                    wire:model.live="form.time_settings['{{ $date }}'].end_time"
                                                    wire:change="applyTimeToAllDates('{{ $date }}')"
                                                    interval="30"
                                                    min="06:00"
                                                    max="23:00"
                                                    open-to="10:00"
                                                    placeholder="End"
                                                />
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </flux:field>
                    @endif
                </div>
            </div>
        </form>

        <!-- Notes Panel -->
        @if($view_text['form_submit'] === 'edit' && $form->task)
            <div x-show="activeTab === 'notes'" x-effect="if (activeTab === 'notes') $nextTick(() => { $el.querySelector('textarea')?.dispatchEvent(new Event('input', { bubbles: true })) })">
                <div class="relative">
                    <div class="space-y-4">
                        {{-- NOTES --}}
                        <flux:composer
                            wire:model="form.notes"
                            label="Notes"
                            variant="input"
                            max-rows="20"
                            placeholder="Notes about this task..."
                        >
                            <x-slot name="actionsLeading">
                                {{-- Empty to push trailing to the right --}}
                            </x-slot>

                            <x-slot name="actionsTrailing">
                                <flux:button 
                                    wire:click="saveNotes" 
                                    wire:loading.class="opacity-50"
                                    wire:target="saveNotes"
                                    type="button" 
                                    size="sm" 
                                    variant="filled"
                                    loading
                                >
                                    Save
                                </flux:button>
                            </x-slot>
                        </flux:composer>

                        {{-- CHECKLIST --}}
                        <div class="w-full">
                            <flux:kanban class="rounded-lg min-w-0 w-full !block">
                                <flux:kanban.column class="!w-full !max-w-none [&>div]:!w-full [&>div]:!max-w-none">
                                    <flux:kanban.column.header class="min-w-0">
                                        <flux:heading class="min-w-0 flex items-center gap-2">
                                            <span>Checklist</span>
                                            <span class="text-sm text-zinc-500">
                                                {{ count(array_filter($form->checklist ?? [], fn($item) => !(is_array($item) ? ($item['completed'] ?? false) : ($item->completed ?? false)))) }}
                                            </span>
                                        </flux:heading>

                                        <x-slot name="actions">
                                            <div class="flex justify-end">
                                                <flux:button
                                                    variant="subtle"
                                                    icon="{{ $showCompletedChecklist ? 'eye-slash' : 'eye' }}"
                                                    size="sm"
                                                    wire:click.stop="toggleCompletedChecklist"
                                                />
                                            </div>
                                        </x-slot>
                                    </flux:kanban.column.header>

                                    <flux:kanban.column.cards class="w-full">
                                        @php
                                            $checklistItems = $form->checklist ?? [];
                                        @endphp

                                        {{-- Sortable incomplete items --}}
                                        <div x-sort="$wire.sortChecklistItems($key, $position)" class="flex flex-col gap-2">
                                            @foreach($checklistItems as $index => $item)
                                                @php
                                                    $isCompleted = is_array($item) ? ($item['completed'] ?? false) : ($item->completed ?? false);
                                                @endphp

                                                @if(!$isCompleted)
                                                    <flux:kanban.card class="min-w-0 w-full !px-3 !py-2" wire:key="checklist-item-{{ $index }}" x-sort:item="{{ $index }}">
                                                        <div class="flex items-center gap-2 min-w-0">
                                                            <flux:checkbox
                                                                wire:change="toggleChecklistItem({{ $index }})"
                                                                :checked="$isCompleted"
                                                                class="shrink-0"
                                                            />

                                                            <input
                                                                type="text"
                                                                wire:model.blur="form.checklist.{{ $index }}.text"
                                                                class="flex-1 min-w-0 bg-transparent border-none focus:outline-none text-sm leading-tight"
                                                                placeholder="Checklist item..."
                                                            />

                                                            <flux:icon.chevron-up-down x-sort:handle class="shrink-0 size-4 text-zinc-400 cursor-grab active:cursor-grabbing" />
                                                        </div>
                                                    </flux:kanban.card>
                                                @endif
                                            @endforeach
                                        </div>

                                        {{-- Completed items (not sortable) --}}
                                        @if($showCompletedChecklist)
                                            @foreach($checklistItems as $index => $item)
                                                @php
                                                    $isCompleted = is_array($item) ? ($item['completed'] ?? false) : ($item->completed ?? false);
                                                @endphp

                                                @if($isCompleted)
                                                    <flux:kanban.card class="min-w-0 w-full !px-3 !py-2" wire:key="checklist-item-{{ $index }}">
                                                        <div class="flex items-center gap-2 min-w-0">
                                                            <flux:checkbox
                                                                wire:change="toggleChecklistItem({{ $index }})"
                                                                :checked="$isCompleted"
                                                                class="shrink-0"
                                                            />

                                                            <input
                                                                type="text"
                                                                wire:model.blur="form.checklist.{{ $index }}.text"
                                                                class="flex-1 min-w-0 bg-transparent border-none focus:outline-none text-sm leading-tight line-through text-zinc-400 dark:text-zinc-500"
                                                                placeholder="Checklist item..."
                                                            />
                                                        </div>
                                                    </flux:kanban.card>
                                                @endif
                                            @endforeach
                                        @endif

                                        {{-- Add new item form --}}
                                        <flux:kanban.card class="min-w-0 w-full !px-3 !py-2">
                                            <form x-data="{ newItem: '' }" @submit.prevent="$wire.addChecklistItem(newItem).then(() => newItem = '')" class="flex items-center gap-2">
                                                <input
                                                    x-model="newItem"
                                                    class="flex-1 min-w-0 bg-transparent border-none focus:outline-none text-sm placeholder:text-zinc-400"
                                                    placeholder="New task..."
                                                />
                                                <flux:button type="submit" variant="filled" size="sm">Add</flux:button>
                                            </form>
                                        </flux:kanban.card>
                                    </flux:kanban.column.cards>
                                </flux:kanban.column>
                            </flux:kanban>
                        </div>
                    </div>
                </div>
            </div>
        @endif

    </div>

    <x-slot name="footer">
        <flux:spacer />

        @if($view_text['form_submit'] === 'edit')
            <flux:button type="button" wire:click="duplicateTask">Duplicate</flux:button>
        @endif

        @if($view_text['form_submit'] === 'edit' && $form->task)
            <flux:button type="button" wire:click="removeTask" variant="danger">Remove</flux:button>
        @endif

        <flux:button type="submit" form="task_create_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>
</div>
