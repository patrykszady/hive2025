@dd('in users/team-members.blade ... should be in users/index.blade')

<x-cards accordian="{{$accordian}}">
    <x-cards.heading>
        <x-slot name="left">
            <h1>Team Members</h1>
        </x-slot>

        <x-slot name="right">
            @can('create_team_member', [App\Models\User::class, $vendor->id])
                <x-cards.button wire:click="$dispatchTo('users.user-create', 'newMember', { 'model': 'vendor', 'model_id': {{$vendor->id}} })">
                    Add Team Member
                </x-cards.button>
                <livewire:users.user-create>
            @endcan
        </x-slot>
    </x-cards.heading>

    <x-cards.body>
        <x-lists.ul>
            @foreach($vendor_users as $user_vendor)
                @php
                    $line_details = [
                        // 1 => [
                        //     'text' => 'Vendor role',
                        //     'icon' => 'M10 2a1 1 0 00-1 1v1a1 1 0 002 0V3a1 1 0 00-1-1zM4 4h3a3 3 0 006 0h3a2 2 0 012 2v9a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2zm2.5 7a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm2.45 4a2.5 2.5 0 10-4.9 0h4.9zM12 9a1 1 0 100 2h3a1 1 0 100-2h-3zm-1 4a1 1 0 011-1h2a1 1 0 110 2h-2a1 1 0 01-1-1z'
                        //     ],
                    ];
                @endphp

                <x-lists.search_li
                    {{-- wire:click="$dispatch('showMember',)" --}}
                    wire:navigate.hover
                    href="{{route('users.show',  $user_vendor->id)}}"
                    :line_details="$line_details"
                    :line_title="$user_vendor->full_name"
                    :bubble_message="$user_vendor->getVendorRole($vendor->id)"
                    >
                </x-lists.search_li>

                {{-- 2-7-2022 ..only render when clicked above... --}}
                {{-- @livewire('users.users-show', ['user' => $user]) --}}
            @endforeach
        </x-lists.ul>
    </x-cards.body>

    <div
        x-data="{ vendor_info: @entangle('registration') }"
        x-show="vendor_info"
        x-transition.duration.250ms
        >
        <x-cards.footer>
            <button></button>
            <x-cards.button
                wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcessStep', { process_step: 'team_members' })"
                button_color=white
                >
                No More Employees
            </x-cards.button>
        </x-cards.footer>
    </div>
</x-cards>


