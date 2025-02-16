{{-- form classes divide-y divide-gray-200 --}}
<form wire:submit="{{$view_text['form_submit']}}">
    <x-cards class="max-w-2xl mx-auto">
        {{-- HEADER --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>{{$view_text['card_title']}}</h1>
            </x-slot>
            <x-slot name="right">

            </x-slot>
        </x-cards.heading>

        {{-- ROWS --}}
        <x-cards.body :class="'space-y-4 my-4'">

            {{-- USER --}}
            <x-forms.row
                wire:model.live.debounce.250ms="user_id"
                errorName="user_id"
                name="user_id"
                text="User"
                type="dropdown"
                >

                <option value="" readonly>Select User</option>
                @foreach ($users as $user)
                    <option value="{{$user->id}}">{{$user->full_name}}</option>
                @endforeach

            </x-forms.row>
        </x-cards.body>

        {{-- FOOTER --}}
        <x-cards.footer>
            <button
                type="button"
                {{-- class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" --}}
                >
                {{-- Cancel --}}
            </button>
            <button
                type="submit"
                {{-- x-bind:disabled="expense.project_id" --}}
                class="inline-flex justify-center px-4 py-2 ml-3 text-sm text-white bg-indigo-600 border border-transparent rounded-md shadow-sm disabled:opacity-50 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                {{$view_text['button_text']}}
            </button>
        </x-cards.footer>
    </x-cards>
</form>
