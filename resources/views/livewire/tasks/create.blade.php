<flux:modal name="task_create_form_modal" class="!mt-8 w-full max-w-sm">
    <flux:heading size="lg" class="!mb-0">{{$view_text['card_title']}}</flux:heading>
    @if(isset($form->task))
        <flux:subheading>{{$form->task->title}}</flux:subheading>
    @endif

    <!-- Tab Navigation -->
    <div x-data="{ activeTab: 'details' }">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8">
                <button
                    @click="activeTab = 'details'"
                    :class="activeTab === 'details' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="py-2 px-1 border-b-2 font-medium text-sm"
                >
                    Details
                </button>
                @if($view_text['form_submit'] === 'edit' && $form->task)
                    <button
                        @click="activeTab = 'dependencies'"
                        :class="activeTab === 'dependencies' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="py-2 px-1 border-b-2 font-medium text-sm flex items-center gap-2"
                    >
                        Dependencies
                        @if($form->task->predecessorDependencies->count() > 0)
                            <flux:badge size="sm" color="blue">
                                {{ $form->task->predecessorDependencies->count() }}
                            </flux:badge>
                        @endif
                    </button>
                @endif
            </nav>
        </div>

        <!-- Task Details Panel -->
        <div x-show="activeTab === 'details'">
            <div class="relative">
                <form wire:submit="{{$view_text['form_submit']}}" class="grid space-y-4">
                    {{-- TYPE --}}
                    <flux:radio.group wire:model="form.type" label="Task Type" variant="segmented">
                        <flux:radio value="Task" label="Task" class="!text-accent"/>
                        <flux:radio value="Milestone" label="Milestone" class="!text-indigo-800" />
                    </flux:radio.group>

                    {{-- TITLE --}}
                    <flux:input wire:model.blur="form.title" label="Title" placeholder="Task Title" autofocus/>

                    {{-- DATES --}}
                    <flux:input.group label="Dates" class="w-full">
                        <flux:date-picker
                            with-today
                            mode="range"
                            wire:model.live="form.dates"
                            :error="$errors->has('form.dates')"
                            class="flex-1"
                        />
                        <flux:input.group.suffix>{{ $this->duration }} {{ Str::plural('Day', $this->duration) }}</flux:input.group.suffix>
                    </flux:input.group>
                    <flux:error name="form.dates" />

                    {{-- WEEKEND DAYS --}}
                    <flux:checkbox.group label="Weekend" variant="buttons">
                        <flux:checkbox wire:model.live="form.saturday" value="saturday" label="Saturday" />
                        <flux:checkbox wire:model.live="form.sunday" value="sunday" label="Sunday" />
                    </flux:checkbox.group>

                    {{-- PROJECT --}}
                    <flux:select wire:model.live="form.project_id" label="Project" variant="listbox" searchable placeholder="Assign project...">
                        @foreach($projects as $project)
                            <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}">
                                <div>{{$project->address}} <br> <i>{{$project->project_name}}</i></div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    {{-- VENDOR --}}
                    <flux:select
                        wire:model.live="form.vendor_id"
                        label="Vendor"
                        variant="listbox"
                        searchable
                        clearable
                        placeholder="Assign vendor..."
                    >
                        @foreach($vendors as $vendor)
                            <flux:select.option wire:key="{{$vendor->id}}" value="{{$vendor->id}}">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <flux:avatar size="xs" name="{{ $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}" />
                                    {{$vendor->name}}
                                </div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    {{-- USERS --}}
                    <flux:select wire:model.blur="form.user_ids" multiple label="Team Members" variant="listbox" placeholder="Assign team members...">
                        @foreach($employees as $employee)
                            <flux:select.option wire:key="{{$employee->id}}" value="{{$employee->id}}">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <flux:avatar size="xs" name="{{ $employee->full_name }}" color="auto" color:seed="{{ $employee->id }}"  />
                                    {{$employee->first_name}}
                                </div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    {{-- NOTES --}}
                    <flux:textarea
                        wire:model.blur="form.notes"
                        label="Task Notes"
                        rows="auto"
                        placeholder="Notes about this task."
                    />

                    {{-- STICKY FOOTER - NOW INSIDE FORM --}}
                    <div class="sticky bottom-0 flex justify-end space-x-2">
                        {{-- Only show duplicate button when editing (not creating) --}}
                        @if($view_text['form_submit'] === 'edit')
                            <flux:button wire:click="duplicateTask">Duplicate</flux:button>
                        @endif

                        <flux:button wire:click="removeTask" variant="danger">Remove</flux:button>

                        <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Dependencies Panel -->
        @if($view_text['form_submit'] === 'edit' && $form->task)
            <div x-show="activeTab === 'dependencies'">
                <div class="relative">
                    <div class="space-y-4">
                        <!-- Current Dependencies -->
                        @if($form->task->predecessorDependencies->count() > 0)
                            <div class="space-y-3">
                                <flux:subheading>Current Prerequisites</flux:subheading>
                                <div class="space-y-2">
                                    @foreach($form->task->predecessorDependencies as $dependency)
                                        @php
                                            $isBlocking = $dependency->isBlocking();
                                        @endphp
                                        <div class="relative bg-white/50 border transition-all duration-200
                                            {{ $isBlocking ? 'border-red-500 border-2 border-dashed bg-red-50/50' : 'border-opacity-30 border-accent bg-accent/30' }}
                                            rounded flex items-center shadow select-none p-3
                                            {{ $isBlocking ? 'hover:border-red-600 hover:bg-red-50' : 'hover:bg-accent/10' }} hover:shadow-md group">

                                            <!-- Task content -->
                                            <div class="flex-1 flex items-center justify-between">
                                                <div class="flex flex-col gap-1 flex-1 min-w-0">
                                                    <span class="font-medium leading-tight text-sm whitespace-nowrap overflow-hidden text-ellipsis
                                                        {{ $isBlocking ? 'text-red-800' : '' }}">
                                                        {{ $dependency->predecessor->title }}
                                                    </span>

                                                    <div class="text-xs {{ $isBlocking ? 'text-red-700 font-medium' : 'text-gray-600' }}">
                                                        {{ ucfirst(str_replace('_', ' to ', $dependency->type)) }}
                                                        @if($dependency->lag_days != 0)
                                                            • {{ $dependency->lag_days > 0 ? '+' : '' }}{{ $dependency->lag_days }} days
                                                        @endif
                                                        @if($isBlocking)
                                                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                                                Blocking
                                                            </span>
                                                        @endif
                                                    </div>

                                                    @if($dependency->predecessor->start_date && $dependency->predecessor->end_date)
                                                        <div class="text-xs {{ $isBlocking ? 'text-red-600' : 'text-gray-500' }}">
                                                            {{ $dependency->predecessor->start_date->format('M j') }} - {{ $dependency->predecessor->end_date->format('M j') }}
                                                        </div>
                                                    @endif

                                                    <!-- Users and vendor info like in gantt -->
                                                    <div class="flex items-center gap-1 min-h-0 overflow-hidden">
                                                        @if($dependency->predecessor->users->count() > 0)
                                                            @if($dependency->predecessor->users->count() === 1)
                                                                @foreach($dependency->predecessor->users as $user)
                                                                    <flux:avatar
                                                                        size="xs"
                                                                        name="{{ $user->full_name }}"
                                                                        color="auto"
                                                                        color:seed="{{ $user->id }}"
                                                                    />
                                                                @endforeach
                                                            @else
                                                                <flux:avatar.group>
                                                                    @foreach($dependency->predecessor->users as $user)
                                                                        <flux:avatar
                                                                            size="xs"
                                                                            name="{{ $user->full_name }}"
                                                                            color="auto"
                                                                            color:seed="{{ $user->id }}"
                                                                        />
                                                                    @endforeach
                                                                </flux:avatar.group>
                                                            @endif
                                                        @endif
                                                        @if($dependency->predecessor->vendor)
                                                            <flux:avatar
                                                                size="xs"
                                                                name="{{ $dependency->predecessor->vendor->name }}"
                                                                color="auto"
                                                                color:seed="{{ $dependency->predecessor->vendor->id }}"
                                                            />
                                                            <flux:text size="xs" class="min-w-0 whitespace-nowrap truncate">{{ $dependency->predecessor->vendor->name }}</flux:text>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Right action button (like resize handle position) -->
                                            <div class="ml-3 opacity-30 group-hover:opacity-100 transition-all duration-200">
                                                <flux:button
                                                    wire:click="removeDependency({{ $dependency->id }})"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="trash"
                                                    class="{{ $isBlocking ? 'text-red-700 hover:text-red-900' : 'text-red-600 hover:text-red-800' }}"
                                                />
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <flux:separator />
                        @endif

                        <!-- Add New Dependency -->
                        <div class="space-y-4">
                            <flux:subheading>Add New Dependency</flux:subheading>

                            <flux:select wire:model="selectedPredecessorId" label="Prerequisite Task" placeholder="Select a task that must complete first...">
                                @foreach($this->availableTasks as $availableTask)
                                    <flux:select.option value="{{ $availableTask->id }}">
                                        <div>
                                            <div class="font-medium">{{ $availableTask->title }}</div>
                                            @if($availableTask->start_date && $availableTask->end_date)
                                                <div class="text-xs text-gray-500">
                                                    {{ $availableTask->start_date->format('M j') }} - {{ $availableTask->end_date->format('M j') }}
                                                </div>
                                            @endif
                                        </div>
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            <div class="grid grid-cols-2 gap-4">
                                <flux:field>
                                    <flux:label>
                                        Dependency Type

                                        <flux:tooltip toggleable>
                                            <flux:button icon="information-circle" size="sm" variant="ghost" />

                                            <flux:tooltip.content>
                                                <ul class="text-sm">
                                                    <li>• <strong>Finish to Start:</strong> Prerequisite must finish before this task starts</li>
                                                    <li>• <strong>Start to Start:</strong> Both tasks start at the same time</li>
                                                    <li>• <strong>Finish to Finish:</strong> Both tasks finish at the same time</li>
                                                    <li>• <strong>Start to Finish:</strong> This task finishes when prerequisite starts</li>
                                                </ul>
                                            </flux:tooltip.content>
                                        </flux:tooltip>
                                    </flux:label>
                                    <flux:select wire:model="dependencyType">
                                        <flux:select.option value="finish_to_start">Finish to Start</flux:select.option>
                                        <flux:select.option value="start_to_start">Start to Start</flux:select.option>
                                        <flux:select.option value="finish_to_finish">Finish to Finish</flux:select.option>
                                        <flux:select.option value="start_to_finish">Start to Finish</flux:select.option>
                                    </flux:select>
                                </flux:field>

                                <flux:field>
                                    <flux:label class="!mb-4 !mt-2">Lag Days</flux:label>
                                    <flux:input
                                        wire:model="lagDays"
                                        type="number"
                                        placeholder="0"
                                    />
                                    <flux:description class="!mt-1">Positive for delay, negative for overlap.</flux:description>
                                </flux:field>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        {{-- STICKY FOOTER --}}
                        <div class="sticky bottom-0 flex justify-end space-x-2">
                            <flux:button
                                wire:click="addDependency"
                                variant="primary"
                                {{-- :disabled="!$selectedPredecessorId" --}}
                            >
                                Add Dependency
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</flux:modal>
