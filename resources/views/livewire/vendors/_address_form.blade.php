<div
    x-data="{ open: @entangle('address').live }"
    x-show="open"
    x-transition.duration.150ms
    class="my-4 space-y-4"
    >

    <x-forms.row
        wire:model.live.debounce.1000ms="vendor.address"
        errorName="vendor.address"
        name="vendor.address"
        text="Address"
        type="text"
        placeholder="Street Address | 123 Main St"
        >
    </x-forms.row>

    <x-forms.row
        wire:model.live.debounce.500ms="vendor.address_2"
        errorName="vendor.address_2"
        name="vendor.address_2"
        text=""
        type="text"
        placeholder="Unit Number | Suite 106"
        >
    </x-forms.row>

    <x-forms.row
        wire:model.live.debounce.1000ms="vendor.city"
        errorName="vendor.city"
        name="vendor.city"
        text=""
        type="text"
        placeholder="City | Arlington Heights"
        >
    </x-forms.row>

    <x-forms.row
        wire:model.live.debounce.1000ms="vendor.state"
        errorName="vendor.state"
        name="vendor.state"
        text=""
        type="text"
        placeholder="State | IL"
        maxlength="2"
        minlength="2"
        >
    </x-forms.row>

    <x-forms.row
        wire:model.live.debounce.1000ms="vendor.zip_code"
        errorName="vendor.zip_code"
        name="vendor.zip_code"
        text=""
        type="number"
        placeholder="Zipcode | 60070"
        maxlength="5"
        minlength="5"
        inputmode="numeric"
        >
    </x-forms.row>
</div>
