<div class="max-w-3xl">
    <x-island-card heading="Select Account" subheading="{{$user->first_name}}, select one of your accounts to access your dashboard." class="space-y-6">
        
        @if($this->clients->count() > 0)
            {{-- Client Selection for client-only users --}}
            <flux:radio.group wire:model.live="client_id" label="Your Client Accounts" variant="cards" class="flex-col" :indicator="false">
                @foreach($this->clients as $client)
                    <flux:radio value="{{$client->id}}">
                        <div class="flex-1">
                            <div class="flex justify-between items-center">
                                <flux:heading class="truncate">
                                    {{ $client->name }}
                                </flux:heading>
                                <flux:badge size="sm" color="emerald">Client</flux:badge>
                            </div>
                            @if($client->address)
                                <flux:text size="sm" class="truncate">{{ $client->one_line_address ?? $client->address }}</flux:text>
                            @endif
                        </div>
                    </flux:radio>
                @endforeach
            </flux:radio.group>
            
            <div x-show="$wire.client_id" x-transition x-cloak>
                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="save">
                        Continue to Dashboard
                    </flux:button>
                </div>
            </div>
        @endif
        
        @if($this->vendors->count() > 0)
            {{-- Vendor Selection --}}
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

            <div x-show="$wire.vendor_id" x-transition x-cloak>
                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="save">
                        @if($vendor_id && $this->vendors->find($vendor_id))
                            @php
                                $selectedVendor = $this->vendors->find($vendor_id);
                                $buttonText = isset($selectedVendor->registration['registered']) && $selectedVendor->registration['registered'] == true
                                    ? 'Login to '
                                    : 'Register ';
                            @endphp
                            {{ $buttonText . $selectedVendor->name }}
                        @endif
                    </flux:button>
                </div>
            </div>
        @endif
        
        @if($this->clients->count() === 0 && $this->vendors->count() === 0)
            <flux:callout icon="exclamation-triangle" color="amber">
                <flux:callout.heading>No accounts found</flux:callout.heading>
                <flux:callout.text>You don't have any accounts associated with your profile yet.</flux:callout.text>
            </flux:callout>
        @endif
    </x-island-card>

    @if(!$user->is_client_user)
        <flux:separator text="+" class="my-8"/>
      
        <x-island-card heading="Create a Hive" subheading="Contact us to get started for free. Cell: 224-999-3880 Email: patryk@hive.contractors" class="space-y-6">
        </x-island-card>
    @endif
</div>
