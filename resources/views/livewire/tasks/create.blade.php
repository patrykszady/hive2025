<flux:modal name="task_create_form_modal" class="space-y-2">
    <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid space-y-2">
        {{-- TYPE --}}
        <flux:radio.group wire:model="form.type" label="Task Type" variant="segmented">
            {{-- checked --}}
            <flux:radio value="Task" label="Task" class="!text-blue-800" />
            <flux:radio value="Milestone" label="Milestone" class="!text-indigo-800" />
            {{-- <flux:radio value="Material" label="Material" /> --}}
        </flux:radio.group>

        {{-- TITLE --}}
        <flux:input wire:model.blur="form.title" label="Title" placeholder="Task Title" autofocus/>

        {{-- DATES --}}
        <flux:input.group label="Dates">
            <flux:date-picker
                with-today
                mode="range"
                wire:model.live="form.dates"
                :error="$errors->has('form.dates')"
            />
            <flux:input.group.suffix>{{ $this->duration }} {{ Str::plural('Day', $this->duration) }}</flux:input.group.suffix>
        </flux:input.group>
        <flux:error name="form.dates" />

        {{-- OPTIONS --}}
        <flux:fieldset>
            <flux:legend>Weekend</flux:legend>
            <flux:description>Include weekend days.</flux:description>
            <div class="flex gap-4 *:gap-x-2">
                <flux:checkbox wire:model.live="form.saturday" value="saturday" label="Saturday" />
                <flux:checkbox wire:model.live="form.sunday" value="sunday" label="Sunday" />
            </div>
        </flux:fieldset>

        {{-- PROJECT --}}
        <flux:select wire:model.live="form.project_id" label="Project" variant="listbox" searchable placeholder="Assign project...">
            @foreach($projects as $project)
                <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}"><div>{{$project->address}} <br> <i>{{$project->project_name}}</i></div></flux:select.option>
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

        {{-- DEPENDENCIES --}}
        @if($view_text['form_submit'] === 'edit' && $form->task)
            <flux:separator />

            <!-- Dependencies Accordion -->
            <flux:accordion>
                <flux:accordion.item>
                    <flux:accordion.heading>
                        <div class="flex items-center justify-between w-full">
                            <flux:heading size="sm">Task Dependencies</flux:heading>
                            @if($form->task->predecessorDependencies->count() > 0)
                                <flux:badge size="sm" color="blue">
                                    {{ $form->task->predecessorDependencies->count() }}
                                </flux:badge>
                            @endif
                        </div>
                    </flux:accordion.heading>

                    <flux:accordion.content>
                        <div class="space-y-4 p-4 bg-gray-50 rounded-lg">
                            <!-- Current Dependencies -->
                            @if($form->task->predecessorDependencies->count() > 0)
                                <div class="space-y-2">
                                    <flux:subheading>Prerequisites ({{ $form->task->predecessorDependencies->count() }})</flux:subheading>
                                    @foreach($form->task->predecessorDependencies as $dependency)
                                        <div class="flex items-center justify-between p-3 bg-white rounded border">
                                            <div class="flex-1">
                                                <div class="font-medium text-sm">{{ $dependency->predecessor->title }}</div>
                                                <div class="text-xs text-gray-600">
                                                    {{ ucfirst(str_replace('_', ' to ', $dependency->type)) }}
                                                    @if($dependency->lag_days != 0)
                                                        • {{ $dependency->lag_days > 0 ? '+' : '' }}{{ $dependency->lag_days }} days
                                                    @endif
                                                </div>
                                                @if($dependency->predecessor->start_date && $dependency->predecessor->end_date)
                                                    <div class="text-xs text-gray-500">
                                                        {{ $dependency->predecessor->start_date->format('M j') }} - {{ $dependency->predecessor->end_date->format('M j') }}
                                                    </div>
                                                @endif
                                            </div>
                                            <flux:button
                                                wire:click="removeDependency({{ $dependency->id }})"
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                class="text-red-600 hover:text-red-800"
                                            />
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <!-- Add New Dependency -->
                            <div class="space-y-3">
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

                                <div class="grid grid-cols-2 gap-3">
                                    <flux:select wire:model="dependencyType" label="Dependency Type">
                                        <flux:select.option value="finish_to_start">Finish to Start</flux:select.option>
                                        <flux:select.option value="start_to_start">Start to Start</flux:select.option>
                                        <flux:select.option value="finish_to_finish">Finish to Finish</flux:select.option>
                                        <flux:select.option value="start_to_finish">Start to Finish</flux:select.option>
                                    </flux:select>

                                    <flux:input
                                        wire:model="lagDays"
                                        type="number"
                                        label="Lag Days"
                                        placeholder="0"
                                        description="Positive for delay, negative for overlap"
                                    />
                                </div>

                                <flux:button
                                    wire:click="addDependency"
                                    variant="primary"
                                    size="sm"
                                    :disabled="!$selectedPredecessorId"
                                >
                                    Add Dependency
                                </flux:button>
                            </div>

                            <!-- Dependency Help -->
                            <div class="text-xs text-gray-600 bg-blue-50 p-3 rounded">
                                <strong>Dependency Types:</strong><br>
                                • <strong>Finish to Start:</strong> Prerequisite must finish before this task starts<br>
                                • <strong>Start to Start:</strong> Both tasks start at the same time<br>
                                • <strong>Finish to Finish:</strong> Both tasks finish at the same time<br>
                                • <strong>Start to Finish:</strong> This task finishes when prerequisite starts
                            </div>
                        </div>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        @endif

        {{-- NOTES --}}
        <flux:textarea
            wire:model.blur="form.notes"
            label="Task Notes"
            rows="auto"
            placeholder="Notes about this task."
        />

        {{-- FOOTER --}}
        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            {{-- Only show duplicate button when editing (not creating) --}}
            @if($view_text['form_submit'] === 'edit')
                <flux:button wire:click="duplicateTask" variant="filled">Duplicate</flux:button>
            @endif

            <flux:button wire:click="removeTask" variant="danger">Remove</flux:button>

            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>
</flux:modal>
