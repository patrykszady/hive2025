<flux:modal name="receipt_account_vendor_form_modal" class="space-y-2">
    <div>
        <flux:heading size="lg">{{$vendor ? $vendor->name : 'NO VENDOR'}}</flux:heading>
        <flux:subheading>Choose which Distribution a receipt from this vendor should be automatically attached to. Select NO PROJECT if you do not want to assign a distribution. </flux:subheading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="store" class="grid gap-6">
        <flux:select label="Distribution" wire:model.live="distribution_id" variant="listbox" placeholder="Connect Vendor...">
            <flux:option value="NO_PROJECT">NO PROJECT</flux:option>
            @foreach($distributions as $distribution)
                <flux:option value="{{$distribution->id}}">{{$distribution->name}}</flux:option>
            @endforeach
        </flux:select>

        @if($vendor ? $vendor->receipts->first()->from_type == 4 : false)
            <div
                x-data="{ logged_in: @entangle('vendor.logged_in') }"
                >
                <div>
                    <flux:button
                        wire:click="api_login"
                        x-text="logged_in == true ? 'Logout' : 'Login'"
                        variant="primary"
                        class="w-full"
                        >
                    </flux:button>
                </div>
            </div>
        @endif

        <div
            x-data="{ open: @entangle('distribution_id'); connect_logged_in: @entangle('vendor.logged_in') }"
            x-show="open && connect_logged_in"
            x-transition
            >
            <div class="flex space-x-2 sticky bottom-0">
                <flux:spacer />

                <flux:button type="submit" variant="primary">Connect</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
