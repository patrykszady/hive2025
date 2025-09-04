<flux:modal name="task_create_form_modal" class="!mt-8 w-full max-w-sm" x-on:modal-show.window="$dispatch('reset-tabs'); window.dispatchEvent(new CustomEvent('task-modal-opened'))">
    <flux:heading size="lg" class="!mb-0">{{$view_text['card_title']}}</flux:heading>
    @if(isset($form->task))
        <flux:subheading>{{$form->task->title}}</flux:subheading>
    @endif

    <!-- Tab Navigation -->
    <div 
        x-data="{ activeTab: 'details' }" 
        @reset-tabs.window="activeTab = 'details'"
    >
        <div class="border-b border-gray-200 mb-4">
            <nav class="-mb-px flex space-x-8">
                <button
                    @click="activeTab = 'details'"
                    :class="activeTab === 'details' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="py-2 px-1 border-b-2 font-medium text-sm"
                >
                    Details
                </button>
                @if($view_text['form_submit'] === 'edit' && $form->task)
                    <!-- Replace the "Dependencies" tab button with "Related Tasks" -->
                    <button
                        @click="activeTab = 'dependencies'"
                        :class="activeTab === 'dependencies' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="py-2 px-1 border-b-2 font-medium text-sm flex items-center gap-2"
                    >
                        Related Tasks

                        <!-- Use red badge if any dependency is blocking, blue otherwise -->
                        <flux:badge size="sm" color="{{ $this->hasBlockingDependency ? 'red' : 'blue' }}">
                            {{ $form->task->total_dependencies_count }}
                        </flux:badge>
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
                        @foreach($this->vendors as $vendor)
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
                        @foreach($this->employees as $employee)
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

        <!-- Updated Dependencies Panel (now called Related Tasks) -->
        @if($view_text['form_submit'] === 'edit' && $form->task)
            <div x-show="activeTab === 'dependencies'">
                <div class="relative">
                    <div class="space-y-6">
                        <!-- Tasks This Depends On (Prerequisites) -->
                        @if($form->task->predecessorDependencies->count() > 0)
                            <div class="space-y-3">
                                <flux:subheading>Tasks This Depends On</flux:subheading>
                                <div class="space-y-2">
                                    @foreach($form->task->predecessorDependencies as $dependency)
                                        <x-task-dependency-card :dependency="$dependency" mode="predecessor" />
                                    @endforeach
                                </div>
                            </div>

                            <flux:separator />
                        @endif

                        <!-- Tasks That Depend On This (Successors) -->
                        @if($form->task->successorDependencies->count() > 0)
                            <div class="space-y-3">
                                <flux:subheading>Tasks That Depend On This</flux:subheading>
                                <div class="space-y-2">
                                    @foreach($form->task->successorDependencies as $dependency)
                                        <x-task-dependency-card :dependency="$dependency" mode="successor" />
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="mt-4 text-sm text-gray-500">No tasks depend on this task.</div>
                        @endif

                        <flux:separator />

                        <!-- Add New Dependency Section - Keep this unchanged -->
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
                                        </flux:tooltip> <!-- FIXED: Close tooltip tag properly -->
                                    </flux:label> <!-- Then close label tag -->

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
                        <!-- STICKY FOOTER -->
                        <div class="sticky bottom-0 flex justify-end space-x-2">
                            <flux:button
                                wire:click="addDependency"
                                variant="primary"
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
