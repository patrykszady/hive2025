<x-details.card
    :title="$titleOverride ?? 'Estimate Details'"
    :subheading="!($nonLivewire ?? false) ? $estimate->project->project_name . ' | ' . $estimate->client->name : ''"
    :expanded="$expanded ?? true"
    :nonLivewire="$nonLivewire ?? false"
    >
    @unless($nonLivewire ?? false)
        <x-slot:header_buttons>
            <flux:dropdown>
                <flux:button size="sm" icon-trailing="chevron-down">Options</flux:button>

                <flux:menu>
                    @can('update', $estimate)
                        <flux:menu.item icon="cog-6-tooth" wire:click="$dispatchTo('estimates.estimate-accept', 'accept')">Settings</flux:menu.item>
                        
                        <flux:menu.item icon="document-duplicate" wire:click="$dispatchTo('estimates.estimate-duplicate', 'duplicateModal', { estimate: {{$estimate->id}} })">Duplicate Estimate</flux:menu.item>
                        <flux:menu.item icon="envelope" wire:click="$dispatchTo('estimates.estimate-email', 'compose', { estimate: {{$estimate->id}} })">Email Estimate</flux:menu.item>
                        <flux:menu.item icon="paper-airplane" wire:click="sendInvite" wire:loading.attr="disabled" wire:target="sendInvite">Send Invite</flux:menu.item>

                        <flux:menu.separator />
                    @endcan

                    <flux:menu.submenu heading="Export" icon="arrow-down-tray">
                        <flux:menu.item icon="document-text" wire:click="create_pdf('estimate')" wire:loading.attr="disabled" wire:loading.class="opacity-50" wire:target="create_pdf">Export Estimate</flux:menu.item>
                        <flux:menu.item icon="receipt-percent" wire:click="create_pdf('invoice')" wire:loading.attr="disabled" wire:loading.class="opacity-50" wire:target="create_pdf">Export Invoice</flux:menu.item>
                        <flux:menu.item icon="wrench-screwdriver" wire:click="create_pdf('work order')" wire:loading.attr="disabled" wire:loading.class="opacity-50" wire:target="create_pdf">Export Work Order</flux:menu.item>
                        <flux:menu.item icon="table-cells" wire:click="export_csv">Export Excel Estimate</flux:menu.item>
                    </flux:menu.submenu>

                    @can('update', $estimate)
                        @if($estimate->status === 'Active')
                            <flux:menu.separator />
                            <flux:menu.item icon="x-circle" wire:click="disableEstimate" variant="danger">Disable Estimate</flux:menu.item>
                        @else
                            <flux:menu.separator />
                            <flux:menu.item icon="arrow-path" wire:click="activateEstimate">Restore Estimate</flux:menu.item>
                            <flux:menu.item icon="trash" wire:click="removeEstimate" variant="danger">Delete Estimate</flux:menu.item>
                        @endif
                    @endcan
                </flux:menu>
            </flux:dropdown>
        </x-slot:header_buttons>
    @endunless

    <x-slot:details>
        <x-details.row 
            title="Client" 
            :content="$client->name ?? $estimate->client->name"
            :href="($nonLivewire ?? false) ? null : route('clients.show', $client->id ?? $estimate->client->id)"
            :no-cloak="$nonLivewire ?? false"
            :navigate="!($nonLivewire ?? false)"
        />

        <x-details.row 
            title="Project Name" 
            :content="$project->project_name ?? $estimate->project->project_name"
            :href="($nonLivewire ?? false) ? null : route('projects.show', $project->id ?? $estimate->project->id)"
            :no-cloak="$nonLivewire ?? false"
            :navigate="!($nonLivewire ?? false)"
        />

        @php
            $jobsiteAddress = $project->full_address ?? $estimate->project->full_address;
            $billingAddress = $client->full_address ?? $estimate->client->full_address;
            $sameAddress = $jobsiteAddress === $billingAddress;
        @endphp

        @if($sameAddress)
            <x-details.row 
                title="Address" 
                :content="$jobsiteAddress"
                :href="$project?->getAddressMapURI() ?? $estimate->project->getAddressMapURI()"
                :copyable="!($nonLivewire ?? false)"
                no-truncate
                :no-cloak="$nonLivewire ?? false"
            />
        @else
            <x-details.row 
                title="Jobsite Address" 
                :content="$jobsiteAddress"
                :href="$project?->getAddressMapURI() ?? $estimate->project->getAddressMapURI()"
                :copyable="!($nonLivewire ?? false)"
                no-truncate
                :no-cloak="$nonLivewire ?? false"
            />

            @unless(in_array('billing address', $hide ?? []))
                @if($billingAddress)
                    <x-details.row 
                        title="Billing Address" 
                        :content="$billingAddress"
                        no-truncate
                        :no-cloak="$nonLivewire ?? false"
                    />
                @endif
            @endunless
        @endif

        <x-details.row 
            title="Number" 
            :content="$estimate->number"
            :no-cloak="$nonLivewire ?? false"
        />

        @unless(in_array('total', $hide ?? []))
            <x-details.row 
                title="Total" 
                :content="money(($nonLivewire ?? false) ? ($estimateTotal ?? 0) : $this->estimate_total)"
                :no-cloak="$nonLivewire ?? false"
            />
        @endunless
    </x-slot:details>

    @unless($nonLivewire ?? false)
        <x-slot:footer>
            <livewire:estimates.estimate-accept :estimate="$estimate"/>
            <livewire:estimates.estimate-duplicate />
        </x-slot:footer>
    @endunless
</x-details.card>
