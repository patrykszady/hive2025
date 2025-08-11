<div class="max-w-3xl">
    <flux:card class="space-y-6">
        <flux:heading size="lg">Select Account</flux:heading>
        <flux:subheading>{{$user->first_name}}, select one of your accounts to access your dashboard.</flux:subheading>
        
        <flux:radio.group wire:model.live="vendor_id" label="Your Hive Accounts" variant="cards" class="flex-col" :indicator="false">
            @foreach($this->vendors as $vendor)
                <flux:radio value="{{$vendor->id}}">
                    <div class="flex-1">
                        <!-- Company and Badges Row -->
                        <div class="flex justify-between items-center">
                            <flux:heading class="truncate">
                                {{$vendor->business_name}}
                                <flux:badge inset="top bottom" size="sm" color="blue">
                                    {{$vendor->business_type}}
                                </flux:badge>
                            </flux:heading>
                            <div class="flex gap-2">        
                                <flux:badge size="sm" color="indigo">{{$user->getRoleForVendor($vendor->id)}}</flux:badge>
                            </div>
                        </div>
                        
                        <!-- Address Row -->
                        <flux:text size="sm" class="truncate">{{$vendor->one_line_address}}</flux:text>
                    </div>
                </flux:radio>
            @endforeach
        </flux:radio.group>

        <div x-show="$wire.vendor_id" x-transition>
            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="save">
                    @if($vendor_id && $this->vendors->find($vendor_id))
                        @php
                            $selectedVendor = $this->vendors->find($vendor_id);
                            $buttonText = isset($selectedVendor->registration->registered) && $selectedVendor->registration->registered == true
                                ? 'Login to '
                                : 'Register ';
                        @endphp
                        {{ $buttonText . $selectedVendor->name }}
                    @endif
                </flux:button>
            </div>
        </div>
    </flux:card>

    <flux:separator text="+" class="my-8"/>
  
    <flux:card class="space-y-6">
        <flux:heading size="lg">Create a Hive</flux:heading>
        <flux:subheading>
            Contact us to get started for free. <br> Cell: 224-999-3880 Email: patryk@hive.contractors
        </flux:subheading>
    </flux:card>
</div>
