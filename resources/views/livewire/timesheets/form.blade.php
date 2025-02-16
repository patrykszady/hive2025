<div class="grid grid-cols-5 gap-4 xl:relative sm:px-6 lg:max-w-5xl">
    <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
        {{-- TIMESHEET DETAILS --}}
        {{-- user info/ confirm/ change hourly if you can update Hours/Timesheets...ONLY if you Admin --}}
        <form wire:submit="save">
            <x-lists.details_card>
                {{-- HEADING --}}
                <x-slot:heading>
                    <div>
                        <flux:heading size="lg" class="mb-0">Confirm week of <b>{{$week_date}}</b> for {{$user->first_name}}</flux:heading>
                        <flux:subheading><i>Confirm Timesheet Info for {{$user->first_name}}</i></flux:subheading>
                    </div>
                </x-slot>

                <x-forms.one_line label="Payee">
                    <flux:input wire:model="form.full_name" type="text" disabled />
                    <flux:error name="form.full_name" />
                </x-forms.one_line>

                <x-forms.one_line label="Hours">
                    <flux:input wire:model="form.hours" type="text" disabled />
                    <flux:error name="form.hours" />
                </x-forms.one_line>

                {{-- is user admin and not Timesheet being confirmed owner? not disabled. is Member or admin confirming own timesheets? disabled --}}
                <x-forms.one_line label="Hourly">
                    <flux:input.group>
                        <flux:input.group.prefix>$</flux:input.group.prefix>
                        <flux:input wire:model="form.hourly" type="numeric" disabled inputmode="numeric" step="0.25" :disabled="$user->user_role == 'Member' ? true : ($user->logged_in ? true : false)" />
                        <flux:error name="form.hourly" />
                    </flux:input.group>
                </x-forms.one_line>

                <x-forms.one_line label="Amount">
                    <flux:input.group>
                        <flux:input.group.prefix>$</flux:input.group.prefix>
                        <flux:input wire:model="form.amount" type="text" disabled />
                        <flux:error name="form.amount" />
                    </flux:input.group>
                </x-forms.one_line>

                <div class="space-y-2 mt-2">
                    <flux:button class="w-full">Total Amount | <b>{{money($this->user_hours_amount)}}</b></flux:button>
                    <flux:button type="submit" variant="primary" class="w-full">Confirm Weekly Timesheet</flux:button>
                </div>
            </x-lists.details_card>
        </form>
    </div>

    <div class="col-span-5 space-y-2 lg:col-span-3 lg:col-start-3 overflow-y-auto">
        {{-- HOURS / DAYS --}}
        {{-- EACH PROJECT DURING WEEK & DAY --}}
        @foreach($daily_hours as $date => $hours)
            @include('livewire.timesheets._daily_hours')
        @endforeach
    </div>
</div>
