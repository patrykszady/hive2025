<div>
    {{-- Add Task --}}
    {{-- <form wire:submit="add">
        <div class="flex gap-2">
            <input wire:model="draft" type="text" class="grow rounded-full shadow shadow-slate-300 px-5 py-3" placeholder="New Task">
        </div>
    </form> --}}
    {{-- <flux:button
        wire:click="form_modal"
        icon="plus"
    /> --}}

    <div x-sort="$wire.sort($key, $position)" x-sort:group="tasks" class="grid gap-3">
        {{-- <flux:button
            wire:click="form_modal"
            icon="plus"
        /> --}}
        @foreach($this->tasks as $task)
            <div
                wire:click="form_modal({{ $task->id }})"
                {{-- wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{$task->id}} })" --}}
                x-sort:item="{{$task->id}}"
                :key="$task->id"
                @class([
                    'cursor-pointer pl-1 border border-solid border-gray-300 h-12 hover:bg-gray-100 font-bold rounded-md text-clip overflow-hidden bg-white'
                    // 'filtered' => !is_null($task->start_date) ? $task->start_date->format('Y-m-d') != $task_date : false
                ])
                >
                <div class="px-3 py-1 flex gap-2 items-center">
                    <span>
                        {{$task->title}}
                    </span>
                </div>

                {{-- <button wire:click="remove({{ $task->id }})" type="button" class="transition opacity-0 [body:not(.sorting)_&]:group-hover:opacity-100 text-slate-500 hover:bg-emerald-100/75 hover:text-emerald-700 rounded-full p-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4">
                        <path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" />
                    </svg>
                </button> --}}
            </div>
        @endforeach
    </div>
    {{-- @foreach ($this->tasks as $task)
        <div class="px-3 py-1 flex gap-2 items-center">
            <div class="transition translate-x-[-1.5rem] [body:not(.sorting)_&]:group-hover:translate-x-0 text-sm text-slate-600">{{ $task->title }}</div>
        </div>
    @endforeach --}}

    <flux:modal name="task_create_form_modal" class="space-y-2">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>

        <flux:separator variant="subtle" />

        <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
            {{-- TYPE --}}
            <flux:radio.group wire:model="form.type" label="Task Type" variant="segmented">
                <flux:radio value="Task" label="Task" />
                <flux:radio value="Milestone" label="Milestone" />
                <flux:radio value="Material" label="Material" />
            </flux:radio.group>

            {{-- TITLE --}}
            <flux:input wire:model.blur="form.title" label="Title" placeholder="Task Title" />

            {{-- DATES --}}
            <flux:input wire:model.live="form.start_date" type="date" max="2999-12-31" label="Start Date" />
            <flux:input wire:model.live="form.end_date" type="date" max="2999-12-31" label="End Date" />

            {{-- SAT/SUN INCLUSION --}}
            <flux:fieldset>
                <flux:legend>Work on Weekend</flux:legend>
                {{-- <flux:description>Choose weekend days</flux:description> --}}

                <div class="flex gap-4 *:gap-x-2">
                    <flux:checkbox value="saturday" label="Saturday" />
                    <flux:checkbox value="sunday" label="Sunday" />
                </div>
            </flux:fieldset>

            {{-- DURATION --}}
            <flux:input wire:model.live="form.duration" label="Duration" text="Duration" name="duration" disabled />

            {{-- PROJECT --}}
            <flux:select wire:model.live="form.project_id" label="Project" variant="listbox" searchable placeholder="Select project...">
                @foreach($projects as $project)
                    <flux:option wire:key="{{$project->id}}" value="{{$project->id}}"><div>{{$project->address}} <br> <i>{{$project->project_name}}</i></div></flux:option>
                @endforeach
            </flux:select>

            {{-- VENDOR --}}
            <flux:select wire:model.live="form.vendor_id" label="Vendor" variant="listbox" searchable placeholder="Select vendor...">
                @foreach($vendors as $vendor)
                    <flux:option wire:key="{{$vendor->id}}" value="{{$vendor->id}}">{{$vendor->name}}</flux:option>
                @endforeach
            </flux:select>

            {{-- USERS --}}
            <flux:select wire:model.live="form.user_id" label="Team Members" variant="listbox" searchable placeholder="Select team member...">
                @foreach($employees as $employee)
                    <flux:option wire:key="{{$employee->id}}" value="{{$employee->id}}">{{$employee->first_name}}</flux:option>
                @endforeach
            </flux:select>

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

                <flux:button wire:click="removeTask" variant="danger">Remove</flux:button>

                <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
