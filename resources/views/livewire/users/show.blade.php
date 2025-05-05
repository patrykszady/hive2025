<div>
	<x-page.top
        class="max-w-xl lg:max-w-5xl"
        h1="{!! $user->full_name !!}"
        p="{{$user->this_vendor ? 'Team Member for ' . $user->this_vendor->name : ''}}"
        {{-- right_button_href="{{auth()->user()->can('update', $vendor) ? route('vendors.show', $vendor->id) : ''}}"
        right_button_text="Edit Vendor" --}}
        >
    </x-page.top>

	<div class="grid max-w-xl grid-cols-4 gap-4 mx-auto lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2 lg:top-5">
            {{-- USER DETAILS --}}
            <div>
                <x-lists.details_card>
                    {{-- HEADING --}}
                    <x-slot:heading>
                        <div>
                            <flux:heading size="lg" class="mb-0">User Details</flux:heading>
                        </div>

                        @can('create', $user)
                            <flux:button
                                wire:click="$dispatchTo('users.user-create', 'removeMember', { user: {{$user->id}} })"
                                wire:confirm.prompt="Are you sure you want to remove this User from this Vendor?\n\nType REMOVE to confirm|REMOVE"
                                size="sm"
                                variant="danger"
                                >
                                Remove User from Vendor
                            </flux:button>
                        @endcan
                    </x-slot>
                    {{-- <livewire:vendors.vendor-create /> --}}
                    <livewire:users.user-create />

                    {{-- DETAILS --}}
                    <x-lists.details_list>
                        <x-lists.details_item title="Name" detail="{{$user->full_name}}" />
                        <x-lists.details_item title="Email" detail="{{$user->email}}" />
                        <x-lists.details_item title="Cell Phone" detail="{{$user->cell_phone}}" />
                        @if($user->this_vendor)
                            @can('update', $user)
                                <x-lists.details_item title="Start Date" detail="{{$user->this_vendor->pivot->start_date->format('m/d/Y')}}" />
                                <x-lists.details_item title="Hourly Rate" detail="{{money($user->this_vendor->pivot->hourly_rate)}}" />
                            @endcan

                            <x-lists.details_item title="Vendor Role" detail="{{$user->getVendorRole($user->this_vendor->id)}}" />
                        @endif
                    </x-lists.details_list>

                    <div class="flex space-x-2">
                        <flux:spacer />

                        <div
                            x-data="{ vendor_info: @entangle('registration') }"
                            x-show="vendor_info"
                            x-transition
                            >
                            <flux:button type="submit" variant="primary" wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcessStep', { process_step: 'vendor_info' })">
                                Confirm Details
                            </flux:button>
                        </div>
                    </div>
                </x-lists.details_card>
            </div>
        </div>

        {{-- VENDOR DETAILS --}}
        @if($user->this_vendor)
            <div class="col-span-4 lg:col-span-2">
                <livewire:vendors.vendor-details :vendor="$user->vendor">
            </div>
        @endif

        {{-- USER / VENDOR FINANCES --}}
        @if(!is_null($user->this_vendor))
            @can('update', $user)
                <div class="col-span-4 lg:col-span-2">
                </div>
                <div class="col-span-4 lg:col-span-2 lg:col-start-3">
                    <flux:card class="space-y-2 col-span-4">
                        {{-- HEADING --}}
                        <div class="flex justify-between">
                            <flux:heading size="lg" class="mb-0">User Finances</flux:heading>
                        </div>

                        <flux:separator variant="subtle" />

                        {{-- DETAILS --}}
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column></flux:table.column>
                                <flux:table.column>{{$year}}</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                <flux:table.row>
                                    <flux:table.cell><b>Checks Written</b></flux:table.cell>
                                    <flux:table.cell>{{money($checks_written->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell>&emsp; Timesheets Paid</flux:table.cell>
                                    <flux:table.cell>{{money($timesheets_paid->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell>&emsp; Timesheets Paid Others</flux:table.cell>
                                    <flux:table.cell>{{money($timesheets_paid_others->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell>&emsp; Timesheets Paid By</flux:table.cell>
                                    <flux:table.cell>{{money($timesheets_paid_by->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell>&emsp; Distribution Checks</flux:table.cell>
                                    <flux:table.cell>{{money($distribution_checks->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell>&emsp; Expenses Paid</flux:table.cell>
                                    <flux:table.cell>{{money($expenses_paid->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell><b>TOTAL FOR USER</b></flux:table.cell>
                                    <flux:table.cell>{{money($timesheets_paid->sum('amount') + $distribution_checks->sum('amount') + $timesheets_paid_by->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell>&emsp; Distribution Expenses</flux:table.cell>
                                    <flux:table.cell>{{money($distribution_expenses->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell>&emsp; Member Extra Payments</flux:table.cell>
                                    <flux:table.cell>{{money($user_checks)}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell><b>TOTAL TOTAL FOR USER</b></flux:table.cell>
                                    <flux:table.cell>{{money($timesheets_paid->sum('amount') + $distribution_checks->sum('amount') + $timesheets_paid_by->sum('amount') + $distribution_expenses->sum('amount'))}}</flux:table.cell>
                                </flux:table.row>

                                <flux:table.row>
                                    <flux:table.cell><i>[Difference]</i></flux:table.cell>
                                    <flux:table.cell>{{ money($this->getCheckDifference()) }}</flux:table.cell>
                                </flux:table.row>


                                {{-- @if($user_checks != 0)

                                @endif --}}
{{--
                                @if($timesheets_paid_others != 0)

                                @endif


                                @if($expenses_paid != 0)

                                @endif




                                @if($distribution_expenses != 0)

                                @endif --}}
{{--
                                <flux:table.row>
                                    <flux:table.cell>TOTAL FOR USER</flux:table.cell>
                                    <flux:table.cell>{{money($timesheets_paid + $distribution_checks + $distribution_expenses + $timesheets_paid_by)}}</flux:table.cell>
                                </flux:table.row> --}}
{{--
                                <flux:table.row>
                                    <flux:table.cell>&emsp; <i>difference</i></flux:table.cell>
                                    <flux:table.cell><i>{{money($difference)}}</i></flux:table.cell>
                                </flux:table.row> --}}
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                </div>
            @endcan
        @endif
	</div>
</div>
