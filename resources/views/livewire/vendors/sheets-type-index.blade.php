<div>
    <x-cards class="w-full px-4 pb-5 mb-1 sm:px-6 lg:max-w-xl lg:px-8">
        {{-- HEADING --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>Retail Vendor Sheets Type</h1>
                <p class="text-sm text-gray-500">Assign Materials type to Vendors below. Leave as "Type" if Vendor is a General Expense and not Materials.</p>
            </x-slot>
        </x-cards.heading>

        <x-cards.body :class="'space-y-2 py-2'">
            @foreach($vendors as $vendor_index => $vendor)
                <x-forms.row
                    wire:model.live="vendors.{{$vendor_index}}.sheets_type"
                    errorName="vendors.{{$vendor_index}}.sheets_type"
                    {{-- wire:key="{{$vendor->id}}" --}}
                    name="vendors.{{$vendor_index}}.sheets_type"
                    text="{!! $vendor->name !!}"
                    type="dropdown"
                    >

                    <option value="" readonly>Sheets Type</option>
                    <option value="Materials">Materials</option>
                </x-forms.row>

                @foreach($vendor->expenses->groupBy('category_id') as $category_id => $vendor_categories_grouped_expenses)
                    <fieldset>
                        <legend class="sr-only">{{$vendor->name}} Categories</legend>
                        <div class="space-y-5 sm:grid sm:grid-cols-4">
                            <div class="relative flex items-start pl-8 sm:col-span-3 sm:col-start-2 sm:pl-16">
                                @if(empty($category_id))
                                    <div class="flex items-center h-6">
                                        <input
                                            wire:model.live="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            id="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            aria-describedby="category-description"
                                            name="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            type="checkbox"
                                            value="{{$category_id}}"
                                            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-600"
                                            >
                                    </div>
                                    <div class="ml-3 text-sm leading-6">
                                        <label
                                            for="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            class="font-medium text-gray-900"
                                            >
                                            No Category
                                        </label>
                                        <span id="category-description" class="text-gray-500">
                                            <span class="sr-only">No Category</span>
                                            {{$vendor_categories_grouped_expenses->count()}}
                                        </span>
                                    </div>
                                @else
                                    <div class="flex items-center h-6">
                                        <input
                                            wire:model.live="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            id="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            aria-describedby="category-description"
                                            name="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            type="checkbox"
                                            value="{{$category_id}}"
                                            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-600"
                                            >
                                    </div>
                                    <div class="ml-3 text-sm leading-6">
                                        <label
                                            for="vendors.{{$vendor_index}}.categories.{{$category_id}}"
                                            class="font-medium text-gray-900"
                                            >
                                            {{$categories->find($category_id)->friendly_primary}} / <br>
                                            {{$categories->find($category_id)->friendly_detailed}}
                                        </label>
                                        <span id="category-description" class="text-gray-500">
                                            <span class="sr-only">{{$categories->find($category_id)->friendly_primary}} / {{$categories->find($category_id)->friendly_detailed}}</span>
                                            {{$vendor_categories_grouped_expenses->count()}}
                                        </span>
                                    </div>
                                @endif
                            </div>

                        </div>
                    </fieldset>
                @endforeach

                <x-forms.row
                    wire:model.live="vendors.{{$vendor_index}}.category_id"
                    errorName="vendors.{{$vendor_index}}.category_id"
                    {{-- wire:key="{{$vendor->id}}" --}}
                    name="vendors.{{$vendor_index}}.category_id"
                    text=""
                    type="dropdown"
                    >

                    <option value="" readonly>New Category</option>
                    @foreach($categories as $category)
                        <option value="{{$category->id}}">{{$category->friendly_primary}} / {{$category->friendly_detailed}}</option>
                    @endforeach
                </x-forms.row>

                <fieldset>
                    <legend class="sr-only">{{$vendor->name}} Category</legend>
                    <div class="space-y-5 sm:grid sm:grid-cols-4">
                        <div class="relative flex items-start pl-8 sm:col-span-3 sm:col-start-2 sm:pl-16">
                            <div class="flex items-center h-6">
                                <input
                                    wire:model.live="vendors.{{$vendor_index}}.permanent_category_id"
                                    id="vendors.{{$vendor_index}}.permanent_category_id"
                                    aria-describedby="permanent_category-description"
                                    name="vendors.{{$vendor_index}}.permanent_category_id"
                                    type="checkbox"
                                    value="{{$category_id}}"
                                    class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-600"
                                    >
                            </div>
                            <div class="ml-3 text-sm leading-6">
                                <label
                                    for="vendors.{{$vendor_index}}.permanent_category_id"
                                    class="italic text-gray-900"
                                    >
                                    Assign this Category to All {!! $vendor->name !!} expenses going forward.
                                </label>
                                <span id="permanent_category-description" class="text-gray-500">
                                    <span class="sr-only">No Category</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <x-forms.row
                    {{-- wire:click="$dispatchTo('users.user-create', 'newMember', { model: 'vendor', model_id: '{{$vendor_add_type}}' })" --}}
                    wire:click="save_vendor_categories({{$vendor_index}})"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50"
                    errorName=""
                    text=""
                    type="button"
                    {{-- {!! $vendor->name !!} --}}
                    buttonText="Update Vendor Categories"
                    >
                </x-forms.row>

                @if(!$loop->last)
                    <hr>
                @endif
            @endforeach
        </x-cards.body>
    </x-cards>
</div>

