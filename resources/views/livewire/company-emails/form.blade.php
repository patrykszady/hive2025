<x-modals.modal :class="'max-w-lg'">
    {{-- @dd($errors->any()) --}}
    <form wire:submit="{{$view_text['form_submit']}}">
        <x-cards.heading>
            <x-slot name="left">
                    <h1>
                        {{$view_text['card_title']}}
                    </h1>
            </x-slot>

            <x-slot name="right">

            </x-slot>
        </x-cards.heading>

        <x-cards.body :class="'space-y-4 my-4'">
            <x-forms.row
                wire:model.live.debounce.1000ms="email"
                errorName="email"
                name="email"
                text="Email"
                placeholder="Email"
                {{-- :disabled="isset($user) ? isset($user['id']) ? true : false : false" --}}
                >
            </x-forms.row>
        </x-cards.body>

        <x-cards.footer>
            <button
                type="button"
                {{-- wire:click="$dispatch('company-emails.company-emails-form', 'refreshComponent')" --}}
                x-on:click="open = false"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                Cancel
            </button>

            <button
                x-data="{ open: @entangle('modal_show') }"
                {{-- x-on:click="open = false && errors = false" --}}
                type="submit"
                class="inline-flex justify-center px-4 py-2 ml-3 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                {{$view_text['button_text']}}
            </button>
        </x-cards.footer>
    </form>
</x-modals.modal>
