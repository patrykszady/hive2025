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
                    class="py-2 px-1 border-b-2 font-medium text-sm flex items-center gap-1.5"
                >
                    Dates
                    @if(!empty($form->dates))
                        <flux:badge size="sm" color="zinc">
                            {{ count($form->dates) }}
                        </flux:badge>
                    @endif
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
                    <button
                        type="button"
                        @click="activeTab = 'dependencies'"
                        :class="activeTab === 'dependencies' ? activeClasses : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="py-2 px-1 border-b-2 font-medium text-sm flex items-center gap-1.5"
                    >
                        Dependencies
                        @php
                            $depCount = $form->task->predecessorDependencies->count() + $form->task->successorDependencies->count();
                        @endphp
                        @if($depCount > 0)
                            <flux:badge size="sm" color="zinc">{{ $depCount }}</flux:badge>
                        @endif
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'history'"
                        :class="activeTab === 'history' ? activeClasses : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="py-2 px-1 border-b-2 font-medium text-sm"
                    >
                        History
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
                    <flux:select
                        wire:model.live="form.vendor_id"
                        variant="listbox"
                        searchable
                        clearable
                        label="Vendor"
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
                    <flux:select wire:model.live="form.user_ids" multiple label="Team Members" variant="listbox" placeholder="Assign team members...">
                        @foreach($this->employees as $employee)
                            <flux:select.option wire:key="{{$employee->id}}" value="{{$employee->id}}">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <flux:avatar size="xs" name="{{ $employee->full_name }}" color="auto" color:seed="{{ $employee->id }}"  />
                                    {{$employee->first_name}}
                                </div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    {{-- MEETING OPTIONS (visible only for Meet type) --}}
                    @if($form->type === 'Meet')
                        <div class="mt-4 space-y-4">
                            {{-- MEETING LOCATION TYPE --}}
                            <flux:radio.group wire:model.live="form.meeting_location_type" label="Meeting Type" variant="segmented">
                                <flux:radio value="in_person">
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon.map-pin variant="mini" class="size-4" />
                                        <span>In Person</span>
                                    </div>
                                </flux:radio>
                                <flux:radio value="virtual">
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon.video-camera variant="mini" class="size-4" />
                                        <span>Virtual</span>
                                    </div>
                                </flux:radio>
                            </flux:radio.group>

                            {{-- MEETING PARTICIPANTS --}}
                            <flux:field>
                                <flux:label>Participants</flux:label>

                                {{-- Selected participants as removable pills with full names --}}
                                @if(!empty($form->meeting_participants))
                                    <div class="flex flex-wrap gap-1.5 mt-1">
                                        @foreach($form->meeting_participants as $index => $email)
                                            @php
                                                $contact = collect($this->availableMeetingContacts)->firstWhere('email', $email);
                                                $displayName = ($contact && $contact['name']) ? $contact['name'] : $email;
                                            @endphp
                                            <span wire:key="participant-{{ $index }}" class="inline-flex items-center gap-1 rounded-md bg-zinc-100 dark:bg-zinc-700 px-2 py-1 text-sm text-zinc-700 dark:text-zinc-200">
                                                {{ $displayName }}
                                                <button type="button" wire:click="removeMeetingParticipant({{ $index }})" class="ml-0.5 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                                    <flux:icon.x-mark variant="micro" class="size-3.5" />
                                                </button>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Searchable dropdown to add participants --}}
                                @php
                                    $unselectedContacts = collect($this->availableMeetingContacts)
                                        ->reject(fn ($c) => in_array($c['email'], $form->meeting_participants))
                                        ->values();
                                @endphp
                                <div x-data="{ newEmail: '' }" class="flex gap-2 mt-2">
                                    @if($unselectedContacts->isNotEmpty())
                                        <div class="flex-1">
                                            <flux:autocomplete placeholder="Add participant..." size="sm" class="w-full">
                                                @foreach($unselectedContacts as $contact)
                                                    <flux:autocomplete.item
                                                        wire:key="contact-add-{{ $loop->index }}"
                                                        value="{{ $contact['email'] }}"
                                                        wire:click="addMeetingParticipant('{{ $contact['email'] }}')"
                                                    >
                                                        <div class="flex items-center justify-between w-full">
                                                            <span>{{ $contact['name'] ?: $contact['email'] }}</span>
                                                            <span class="text-xs text-zinc-400 ml-2">{{ $contact['email'] }}</span>
                                                        </div>
                                                    </flux:autocomplete.item>
                                                @endforeach
                                            </flux:autocomplete>
                                        </div>
                                    @else
                                        <flux:input
                                            x-model="newEmail"
                                            type="email"
                                            placeholder="Add email address..."
                                            size="sm"
                                            x-on:keydown.enter.prevent="$wire.addMeetingParticipant(newEmail).then(() => newEmail = '')"
                                            class="flex-1"
                                        />
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            icon="plus"
                                            x-on:click="$wire.addMeetingParticipant(newEmail).then(() => newEmail = '')"
                                        >Add</flux:button>
                                    @endif
                                </div>

                                <flux:error name="form.meeting_participants" />
                            </flux:field>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Select Days and Arrival Time Panel -->
            <div x-show="activeTab === 'schedule'">
                <div class="relative">
                    {{-- DATES --}}
                    <flux:field>
                        <div class="mb-2 flex items-center gap-2">
                            <flux:label>Select Days</flux:label>
                            @if(!empty($form->dates))
                                <flux:badge size="sm" color="zinc">
                                    {{ count($form->dates) }}
                                </flux:badge>
                            @endif
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" x-init="$nextTick(() => { let t = $el.querySelector('ui-calendar-today'); if (t) t.behavior = 'navigate'; })">
                            <flux:calendar
                                multiple
                                with-today
                                size="sm"
                                wire:model.live="form.dates"
                                :error="$errors->has('form.dates')"
                            />
                        </div>
                        <flux:error name="form.dates" />
                    </flux:field>

                    {{-- TIME SETTINGS --}}
                    @if(!empty($form->dates))
                        <flux:field>
                            <div class="flex items-center justify-between">
                                <flux:label>Arrival Time</flux:label>
                                <flux:switch
                                    x-on:change="$wire.toggleAllArrivalTimes($event.target.checked)"
                                    :checked="collect($form->time_settings)->contains('use_time', true)"
                                    size="sm"
                                />
                            </div>

                            <div class="mt-2 space-y-3 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                                @foreach($form->dates as $date)
                                    <div wire:key="arrival-{{ $date }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 space-y-2">
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
                                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                                                @foreach (['start_time' => 'Start', 'end_time' => 'End'] as $field => $placeholder)
                                                    @php
                                                        $isEnd = $field === 'end_time';
                                                        $startTimeValue = data_get($form->time_settings, $date . '.start_time');
                                                        $fieldValue = data_get($form->time_settings, $date . '.' . $field);
                                                        $fieldValueToken = is_string($fieldValue) && $fieldValue !== ''
                                                            ? str_replace(':', '-', $fieldValue)
                                                            : 'empty';
                                                        $minTime = $isEnd && is_string($startTimeValue) && $startTimeValue !== ''
                                                            ? $startTimeValue
                                                            : '06:00';
                                                        $openTo = $isEnd ? '10:00' : '08:00';
                                                    @endphp
                                                    <div
                                                        wire:key="time-{{ $date }}-{{ $field }}-{{ $fieldValueToken }}"
                                                        class="relative"
                                                        x-data="{
                                                            get time() { return $wire.form?.time_settings?.[@js($date)]?.[@js($field)] ?? '' },
                                                            get hourAngle() {
                                                                if (!this.time) return 0;
                                                                const [h, m] = this.time.split(':').map(Number);
                                                                return ((h % 12) + (m || 0) / 60) * 30;
                                                            },
                                                            get minuteAngle() {
                                                                if (!this.time) return 0;
                                                                const [, m] = this.time.split(':').map(Number);
                                                                return (m || 0) * 6;
                                                            },
                                                        }"
                                                    >
                                                        <flux:time-picker
                                                            wire:model.live="form.time_settings.{{ $date }}.{{ $field }}"
                                                            interval="30"
                                                            min="{{ $minTime }}"
                                                            max="23:00"
                                                            open-to="{{ $openTo }}"
                                                            :placeholder="$placeholder"
                                                        />
                                                        <div class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 dark:text-zinc-500">
                                                            <svg class="size-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                                <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.25" />
                                                                <line
                                                                    x1="8" y1="8" x2="8" y2="4.5"
                                                                    stroke="currentColor" stroke-width="1.25" stroke-linecap="round"
                                                                    style="transform-origin: 8px 8px; transition: transform 200ms ease;"
                                                                    :style="`transform-origin: 8px 8px; transform: rotate(${hourAngle}deg); transition: transform 200ms ease;`"
                                                                />
                                                                <line
                                                                    x1="8" y1="8" x2="8" y2="3"
                                                                    stroke="currentColor" stroke-width="1" stroke-linecap="round"
                                                                    style="transform-origin: 8px 8px; transition: transform 200ms ease;"
                                                                    :style="`transform-origin: 8px 8px; transform: rotate(${minuteAngle}deg); transition: transform 200ms ease;`"
                                                                />
                                                                <circle cx="8" cy="8" r="0.75" fill="currentColor" />
                                                            </svg>
                                                        </div>
                                                    </div>
                                                @endforeach
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

        <!-- Dependencies Panel -->
        @if($view_text['form_submit'] === 'edit' && $form->task)
            <div x-show="activeTab === 'dependencies'" x-cloak>
                @include('livewire.tasks._dependencies-panel')
            </div>
        @endif

        <!-- History Panel -->
        @if($view_text['form_submit'] === 'edit' && $form->task)
            <div x-show="activeTab === 'history'" x-cloak>
                <div class="max-h-96 scrollbar-gutter overflow-x-visible">
                    @if($this->taskHistory->isEmpty())
                        <div class="text-center text-sm text-zinc-400 dark:text-zinc-500 py-8">
                            No history yet
                        </div>
                    @else
                        <flux:timeline align="start" style="--flux-timeline-indicator-size: 1.5rem;">
                            @foreach($this->taskHistory as $entry)
                                <flux:timeline.item wire:key="history-{{ $entry['id'] }}">
                                    @if($loop->first)
                                        @php
                                            $colorMap = ['sky' => '#0ea5e9', 'indigo' => '#6366f1', 'orange' => '#f97316', 'rose' => '#f43f5e'];
                                            $hex = $colorMap[$this->taskTypeUi['flux']] ?? '#0ea5e9';
                                        @endphp
                                        <flux:timeline.indicator variant="bare">
                                            <div class="size-5 rounded-full flex items-center justify-center" style="background-color: {{ $hex }}15; border: 2px solid {{ $hex }}40;">
                                                <div class="size-2.5 rounded-full" style="background-color: {{ $hex }}90;"></div>
                                            </div>
                                        </flux:timeline.indicator>
                                    @else
                                        <flux:timeline.indicator variant="bare">
                                            <div class="size-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                        </flux:timeline.indicator>
                                    @endif

                                    <flux:timeline.content>
                                        <div class="space-y-2">
                                            @foreach($entry['changes'] as $change)
                                                <div>
                                                    <div class="flex items-baseline justify-between gap-2">
                                                        <flux:text class="text-sm italic !text-zinc-400 dark:!text-zinc-500">{{ $change['label'] }}</flux:text>
                                                        @if($loop->first)
                                                            <span class="text-[10px] text-zinc-300 dark:text-zinc-600 whitespace-nowrap">{{ $entry['causer'] }} · {{ $entry['created_at']->diffForHumans() }}</span>
                                                        @endif
                                                    </div>
                                                    @if($change['old'] || $change['new'])
                                                        <div class="flex items-baseline gap-2 leading-snug">
                                                            @if($change['old'])
                                                                @if(is_array($change['old']))
                                                                    <div class="flex flex-col">
                                                                        @foreach($change['old'] as $oldItem)
                                                                            <flux:text class="!text-xs line-through">{{ $oldItem }}</flux:text>
                                                                        @endforeach
                                                                    </div>
                                                                @else
                                                                    <flux:text class="!text-xs line-through">{{ $change['old'] }}</flux:text>
                                                                @endif
                                                            @endif
                                                            @if($change['new'])
                                                                @if(is_array($change['new']))
                                                                    <div class="flex flex-col">
                                                                        @foreach($change['new'] as $newItem)
                                                                            <flux:text class="!text-xs font-medium text-zinc-800 dark:text-zinc-200">{{ $newItem }}</flux:text>
                                                                        @endforeach
                                                                    </div>
                                                                @else
                                                                    <flux:text class="!text-xs font-medium text-zinc-800 dark:text-zinc-200">{{ $change['new'] }}</flux:text>
                                                                @endif
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </flux:timeline.content>
                                </flux:timeline.item>
                            @endforeach
                        </flux:timeline>
                    @endif
                </div>
            </div>
        @endif

    </div>

    <x-slot name="footer">
        <flux:spacer />

        @if($view_text['form_submit'] === 'edit' && $form->task_id)
            <flux:button.group>
                <flux:button type="button" wire:click="removeTask" variant="danger" icon="x-mark" />
                @if($form->is_trashed)
                    <flux:button type="button" wire:click="restoreTask" variant="primary">Restore</flux:button>
                @endif
            </flux:button.group>
        @endif

        @if($view_text['form_submit'] === 'edit')
            <flux:button type="button" wire:click="duplicateTask">Duplicate</flux:button>
        @endif

        <flux:button type="submit" form="task_create_form_modal_form" variant="primary" wire:loading.attr="disabled">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>
</div>
