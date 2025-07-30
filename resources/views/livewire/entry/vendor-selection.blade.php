<div class="max-w-3xl">
    <flux:card class="space-y-6">
        <div class="flex">
            <div class="flex-1">
                <flux:heading size="lg">Select Account</flux:heading>
                <flux:subheading>
                    <p>{{$user->first_name}}, select one of your accounts to access your dashboard.</p>
                </flux:subheading>
            </div>
        </div>
        <flux:radio.group wire:model.live="vendor_id" label="{{$user->first_name}}'s Accounts" variant="cards" class="flex-col" :indicator="false">
            @foreach($this->vendors as $vendor)
                <flux:radio 
                    value="{{$vendor->id}}" 
                    label="{!!$vendor->business_name!!} | {{$vendor->business_type}}" 
                    description="{{ $vendor->one_line_address }} | Role: {{ $user->getRoleForVendor($vendor->id) }}" 
                />
            @endforeach
        </flux:radio.group>

        <div x-show="$wire.vendor_id" x-transition>
            <div class="flex gap-4">
                <flux:spacer />
                <flux:button variant="primary" wire:click="save">
                    @if($vendor_id && $this->vendors->find($vendor_id))
                        @php
                            $selectedVendor = $this->vendors->find($vendor_id);
                            $buttonText = isset($selectedVendor->registration['registered']) && $selectedVendor->registration['registered'] 
                                ? 'Login to ' 
                                : 'Register ';
                        @endphp
                        {{ $buttonText . $selectedVendor->business_name }}
                    @endif
                </flux:button>
            </div>
        </div>
    </flux:card>

    <flux:separator text="+" class="my-8"/>
  
    <flux:card class="space-y-6">
        <div class="flex">
            <div class="flex-1">
                <flux:heading size="lg">Create a Hive</flux:heading>

                <flux:subheading>
                    <p>Contact us to get started for free. <br> Cell: 224-999-3880 Email: patryk@hive.contractors</p>
                </flux:subheading>
            </div>
        </div>
        
        {{-- CREATE NEW VENDOR/BUSINESS --}}
        {{-- <livewire:vendors.vendor-create /> --}}
    </flux:card>
</div>
