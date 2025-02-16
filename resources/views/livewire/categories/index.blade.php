<div>
    <x-cards class="w-full px-4 pb-5 mb-1 sm:px-6 lg:max-w-2xl lg:px-8">
        {{-- HEADING --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>Vendor Categories</h1>
                <p
                    class="max-w-2xl mt-1 text-sm text-gray-500"
                    >
                    Add Categories to Vendor.
                </p>
            </x-slot>
        </x-cards.heading>

        {{-- BODY --}}
        <x-cards.body>
            <div>
                <x-lists.ul>
                    @foreach($vendors as $vendor)
                        @php
                            if(isset($vendor->sheets_type)){
                                $line_details = [
                                    1 => [
                                        'text' => $vendor->sheets_type,
                                        'icon' => 'M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z'
                                        ],
                                ];
                            }else{
                                $line_details = [];
                            }

                        @endphp

                        <x-lists.search_li
                            wire:click="$dispatchTo('categories.vendor-categories-create', 'addCategories', { vendor: {{$vendor->id}} })"
                            :line_details="$line_details"
                            :line_title="$vendor->business_name"
                            {{-- :bubble_message="$vendor->vendor_categories()->exists() ? 'Existing' : 'Add Category'"
                            :bubble_color="$vendor->vendor_categories()->exists() ? 'green' : 'red'" --}}
                            >
                        </x-lists.search_li>
                    @endforeach
                </x-lists.ul>
            </div>
        </x-cards.body>

        <livewire:categories.vendor-categories-create />
    </x-cards>
</div>
