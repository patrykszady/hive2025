<flux:modal name="task_create_form_modal" class="space-y-2">
    <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- TYPE --}}
        <flux:radio.group wire:model="form.type" label="Task Type" variant="segmented">
            <flux:radio value="Task" label="Task" />
            <flux:radio value="Milestone" label="Milestone" />
            {{-- <flux:radio value="Material" label="Material" /> --}}
        </flux:radio.group>

        {{-- TITLE --}}
        <flux:input wire:model.blur="form.title" label="Title" placeholder="Task Title" autofocus/>

        {{-- DATES --}}
        <flux:input.group>
            <flux:date-picker with-today mode="range" wire:model.live="form.dates" />
            {{-- DURATION --}}
            <flux:input.group.suffix>{{ $form->duration }} {{ Str::plural('Day', $form->duration) }}</flux:input.group.suffix>
        </flux:input.group>

        {{-- PROJECT --}}
        <flux:select wire:model.live="form.project_id" label="Project" variant="listbox" searchable placeholder="Assign project...">
            @foreach($projects as $project)
                <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}"><div>{{$project->address}} <br> <i>{{$project->project_name}}</i></div></flux:select.option>
            @endforeach
        </flux:select>

        {{-- VENDOR --}}
        <flux:select wire:model.live="form.vendor_id" label="Vendor" variant="listbox" searchable placeholder="Assign vendor...">
            @foreach($vendors as $vendor)
                <flux:select.option wire:key="{{$vendor->id}}" value="{{$vendor->id}}">
                    <div class="flex items-center gap-2 whitespace-nowrap">
                        <flux:avatar size="xs" name="{{ $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}"  />
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

        {{-- FOOTER --}}
        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            <flux:button wire:click="removeTask" variant="danger">Remove</flux:button>

            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>
</flux:modal>
