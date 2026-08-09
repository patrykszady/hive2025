{{-- Layout comes from <x-page.shell>: same width, gutter, grid and 16px
     rhythm as every other show page. --}}
<x-page.shell
    :cols="2"
    width="flat"
    :breadcrumbs="[
        ['label' => 'Clients', 'href' => auth()->user()->can('viewAny', \App\Models\Client::class) ? route('clients.index') : null],
        ['label' => html_entity_decode((string) $client->name, ENT_QUOTES, 'UTF-8')],
    ]"
>
    <x-page.column>
            {{-- CLIENT DETAILS --}}
            <x-details.card 
                :title="$client->name"
                :canEdit="auth()->user()->can('update', $client)"
                :expanded="!auth()->user()->is_browsing_as_client"
            >
                @if(!auth()->user()->is_browsing_as_client)
                    <x-slot:header_buttons>
                        <flux:button
                            wire:click="$dispatchTo('clients.client-create', 'editClient', { client: {{$client->id}}})"
                            size="sm"
                        >
                            Edit Client
                        </flux:button>
                    </x-slot:header_buttons>
                @endif
                
                <x-slot:details>
                    {{-- Client Name --}}
                    <x-details.row 
                        title="Name" 
                        :content="$client->name"
                        :noCloak="true"
                    />

                    {{-- Client Address with Link --}}
                    <x-details.row 
                        title="Billing Address" 
                        :content="$client->full_address" 
                        :href="$client->getAddressMapURI()"
                        :copyable="true"
                        :noCloak="true"
                    />

                    {{-- Client Phone --}}
                    @if($client->home_phone)
                        <x-details.row 
                            title="Phone" 
                            :content="$client->home_phone"
                            :copyable="true"
                            :noCloak="true"
                        />
                    @endif
                </x-slot:details>
            </x-details.card>

            {{-- CONSULTATION TIMES the homeowner picked on the scheduling
                 page — shown while any are still ahead, so "when can they
                 meet?" is answered right here. Internal chrome: hidden from
                 clients browsing their own page. --}}
            @if (! $this->isClientUser && ($la = $this->leadAvailability))
                <x-island-card heading="Consultation Times">
                    <x-slot:badge>
                        @if ($la['preference'] === 'virtual')
                            <flux:badge size="sm" color="amber" icon="video-camera" inset="top bottom">video call</flux:badge>
                        @endif
                    </x-slot:badge>
                    <x-slot:actions>
                        <flux:button size="sm" wire:click="$dispatchTo('leads.lead-create', 'editLead', { lead: {{ $la['lead_id'] }} })">
                            Open Lead
                        </flux:button>
                    </x-slot:actions>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($la['times'] as $slot)
                            <flux:badge color="sky">
                                {{ \Carbon\Carbon::parse($slot['date'])->format('D, M j') }} · {{ $slot['time'] }}
                            </flux:badge>
                        @endforeach
                    </div>
                    @if ($la['updated'])
                        <flux:text class="text-xs text-zinc-500">
                            Sent {{ \Carbon\Carbon::parse($la['updated'])->diffForHumans() }} — booking a consult from the lead uses these.
                        </flux:text>
                    @endif
                </x-island-card>
            @endif

            {{-- CLIENT PROJECTS (includes Email Tracking) --}}
            <livewire:projects.projects-index :client="$client" :view="'clients.index'" />
    </x-page.column>

    <x-page.column>
            {{-- CLIENT USERS --}}
            <livewire:users.users-index :client="$client" :view="'clients.show'"/>

            {{-- CLIENT PROJECT TASKS --}}
            <livewire:clients.upcoming-client-tasks :client="$client" lazy />
    </x-page.column>

    {{-- Modal hosts: outside the grid so they contribute no gap. --}}
    <x-slot:offstage>
        @if(!auth()->user()->is_browsing_as_client)
            <livewire:clients.client-create />
            <livewire:tasks.task-create />
            {{-- Host for the Consultation Times card's "Open Lead". --}}
            <livewire:leads.lead-create />
        @endif
    </x-slot:offstage>
</x-page.shell>
