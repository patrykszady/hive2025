<x-modal wire:model="showModal">
    <x-modal.panel>
        {{-- HEADER --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>Add Categories to {{$vendor->name ?? 'Vendor'}}</h1>
            </x-slot>
            <x-slot name="right">
                @if(isset($vendor->id))
                    <x-cards.button
                        href="{{ route('vendors.show', ['vendor' => $vendor->id]) }}"
                        :href_target="'_blank'"
                        :button_color="'white'"
                        >
                        Show Vendor
                    </x-cards.button>
                @endif
            </x-slot>
        </x-cards.heading>

        <form wire:submit="save">
            {{-- ROWS --}}
            <x-cards.body :class="'space-y-4 my-4'">
                {{-- VENDOR --}}
                <x-forms.row
                    wire:model.live="form.vendor_id"
                    errorName="form.vendor_id"
                    name="vendor_id"
                    disabled
                    text="Vendor"
                    type="dropdown"
                    >

                    <option value="{{$form->vendor_id}}">{{$vendor->name ?? NULL}}</option>
                </x-forms.row>

                {{-- SHEETS TYPE/MATERIALS/GENERAL EXPENSES --}}
                <x-forms.row
                    wire:model.live="form.sheets_type"
                    errorName="form.sheets_type"
                    name="sheets_type"
                    text="Sheets Type"
                    type="dropdown"
                    >

                    <option value="">General Expenses</option>
                    <option value="Materials">Materials</option>
                    {{-- <option value="Not General">Not General Expenses</option> --}}
                </x-forms.row>

                {{-- VENDOR CATEGORIES --}}
                {{-- <x-forms.row
                    wire:model.live="form.vendor_category"
                    errorName="form.vendor_category"
                    name="vendor_category"
                    text="Vendor Category"
                    type="dropdown"
                    >

                    <option value="" readonly>Select Category</option>
                    @foreach ($vendor_categories as $category)
                        <option value="{{$category->id}}">{{$category->friendly_detailed}}</option>
                    @endforeach
                </x-forms.row> --}}

                <hr>

                <x-cards class="col-span-4 p-6 lg:col-span-2">
                    <x-cards.body>
                        <x-cards.heading>
                            <x-slot name="left">
                                <h1 class="text-lg">Existing Vendor Expense Categories</h1>
                            </x-slot>
                        </x-cards.heading>
                        <x-lists.ul>
                            @foreach($vendor_expense_categories as $vendor_category_primary => $vendor_expense_category)
                                <x-lists.search_li
                                    :basic=true
                                    :no_hover="TRUE"
                                    :bold="TRUE"
                                    :line_title="strtoupper($vendor_category_primary)"
                                    :line_data="''"
                                    >
                                </x-lists.search_li>

                                @foreach($vendor_expense_category->groupBy('category.friendly_detailed') as $vendor_category_detailed => $expense_category_expenses)
                                    <x-lists.search_li
                                        :basic=true
                                        :line_title="$vendor_category_detailed . ' (' . $expense_category_expenses->count() . ')'"
                                        :line_data="'Expense Category: ' . $expense_category_expenses->first()->category_id"
                                        >
                                        {{-- VENDOR CATEGORIES --}}
                                        <x-forms.row
                                            wire:model.live="form.vendor_expense_categories.{{$expense_category_expenses->first()->category_id}}"
                                            errorName="form.vendor_expense_categories.{{$expense_category_expenses->first()->category_id}}"
                                            name="form.vendor_expense_categories.{{$expense_category_expenses->first()->category_id}}"
                                            text="Vendor Category"
                                            type="dropdown"
                                            >

                                            <option value="" readonly>Select Category</option>
                                            @foreach($expense_categories as $expense_category)
                                                <option value="{{$expense_category->id}}">{{$expense_category->friendly_primary . ' | ' . $expense_category->friendly_detailed}}</option>
                                            @endforeach
                                        </x-forms.row>
                                    </x-lists.search_li>
                                @endforeach
                            @endforeach
                        </x-lists.ul>
                    </x-cards.body>
                </x-cards>
            </x-cards.body>

            {{-- FOOTER --}}
            <x-cards.footer>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm font-small hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                    Cancel
                </button>

                <x-forms.button
                    type="submit"
                    >
                    Save Vendor Categories
                </x-forms.button>
            </x-cards.footer>
        </form>
    </x-modal.panel>
</x-modal>
