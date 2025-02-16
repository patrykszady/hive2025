<fieldset
    class="w-full"
    x-data="{
        value: null,
        select(option) { this.value = option },
        isSelected(option) { return this.value === option },
        }"
    role="radiogroup"
    >
{{-- 
    <legend class="sr-only">
        x-text="User Vendors"
        User Vendors
    </legend> --}}

    <div class="space-y-4">
        {{$slot}}
    </div>
</fieldset>