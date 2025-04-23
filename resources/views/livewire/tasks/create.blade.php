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
        <flux:input wire:model.blur="form.title" label="Title" placeholder="Task Title" autofocus/>

        {{-- DATES --}}
        <flux:date-picker mode="range" with-today fixed-weeks wire:model.live="form.dates" />

        {{-- SAT/SUN INCLUSION --}}
        <flux:fieldset>
            <flux:legend>Include Weekend</flux:legend>

            <div class="flex gap-4 *:gap-x-2">
                <flux:checkbox wire:model.live="form.include_weekend_days.saturday" label="Saturday" />
                <flux:checkbox wire:model.live="form.include_weekend_days.sunday" label="Sunday" />
            </div>
        </flux:fieldset>

        {{-- DURATION --}}
        <flux:input wire:model.live="form.duration" label="Duration" text="Duration" name="duration" disabled />

        {{-- PROJECT --}}
        <flux:select wire:model.live="form.project_id" label="Project" variant="listbox" searchable placeholder="Select project...">
            @foreach($projects as $project)
                <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}"><div>{{$project->address}} <br> <i>{{$project->project_name}}</i></div></flux:select.option>
            @endforeach
        </flux:select>

        {{-- VENDOR --}}
        <flux:select wire:model.live="form.vendor_id" label="Vendor" variant="listbox" searchable placeholder="Select vendor...">
            @foreach($vendors as $vendor)
                <flux:select.option wire:key="{{$vendor->id}}" value="{{$vendor->id}}">{{$vendor->name}}</flux:select.option>
            @endforeach
        </flux:select>

        {{-- USERS --}}
        <flux:select wire:model.live="form.user_id" label="Team Members" variant="listbox" searchable placeholder="Select team member...">
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
