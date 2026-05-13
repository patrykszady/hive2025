<div class="max-w-3xl space-y-2" wire:transition>
    @php($isProjectScoped = (bool) $this->project)

    {{-- STANDALONE PAGE (NON-PROJECT SCOPED) --}}
    @if(!$isProjectScoped)
        {{-- Mobile: accordion collapsed by default --}}
        <flux:card class="!px-5 !py-2 sm:hidden">
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="lg" class="mb-0">Filters</flux:heading>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="openProjectSelector">
                    Create
                </flux:button>
            </div>
            <flux:accordion transition>
                <flux:accordion.item>
                    <flux:accordion.heading>
                        <flux:heading size="sm">Filter & Search</flux:heading>
                    </flux:accordion.heading>
                    <flux:accordion.content>
                        <div class="space-y-3">
                            <flux:input 
                                wire:model.live.debounce.500ms="search" 
                                placeholder="Search vendor or project..." 
                                icon="magnifying-glass"
                                size="md" 
                                class="w-full"
                            />
                            <flux:select 
                                wire:model.live="statusFilter" 
                                label="Status"
                                size="md" 
                                class="w-full"
                            >
                                <flux:select.option value="">All statuses</flux:select.option>
                                @foreach($this->statusOptions as $opt)
                                    <flux:select.option value="{{ $opt['value'] }}">
                                        <flux:badge size="sm" inset="top bottom" :color="\App\Enums\LienWaiverStatus::from($opt['value'])->color()">
                                            {{ $opt['label'] }}
                                        </flux:badge>
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>

        {{-- Desktop: always expanded --}}
        <x-island-card heading="Filters" :separator="true" class="hidden sm:block">
            <x-slot:actions>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="openProjectSelector">
                    Create Waiver
                </flux:button>
            </x-slot:actions>

            <div class="grid grid-cols-2 gap-4 items-end">
                <flux:input 
                    wire:model.live.debounce.500ms="search" 
                    label="Search"
                    placeholder="Search vendor or project..." 
                    icon="magnifying-glass"
                    size="md" 
                    class="w-full"
                />
                <flux:select 
                    wire:model.live="statusFilter" 
                    label="Status"
                    size="md" 
                    class="w-full"
                >
                    <flux:select.option value="">All statuses</flux:select.option>
                    @foreach($this->statusOptions as $opt)
                        <flux:select.option value="{{ $opt['value'] }}">
                            <flux:badge size="sm" inset="top bottom" :color="\App\Enums\LienWaiverStatus::from($opt['value'])->color()">
                                {{ $opt['label'] }}
                            </flux:badge>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </x-island-card>

        {{-- MAIN TABLE CARD --}}
        <x-island-card heading="Lien Waivers" class="overflow-hidden">
            @include('livewire.lien-waivers._table')
        </x-island-card>
    @else
        {{-- PROJECT-SCOPED VARIANT --}}
        <x-details.card title="Lien Waivers" :expanded="false" :details_text="false" :separator="false">
            <x-slot:header_buttons>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="openCreate"
                    class="!bg-indigo-500 hover:!bg-indigo-600 !text-white">
                    Create Waiver
                </flux:button>
            </x-slot:header_buttons>
            <x-slot:details>
                @include('livewire.lien-waivers._table')
            </x-slot:details>
        </x-details.card>
    @endif

    {{-- PROJECT SELECTOR MODAL (STANDALONE PAGE ONLY) --}}
    @if(!$this->project && !$isProjectScoped)
        <flux:modal wire:model.self="showProjectSelector" name="lien-waiver-project-select" class="max-w-lg">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Create Lien Waiver</flux:heading>
                    <flux:subheading>Select a project to get started.</flux:subheading>
                </div>

                <flux:field>
                    <flux:label>Project</flux:label>
                    <flux:select wire:model.live="selectedProjectForCreate" searchable placeholder="Search projects..." variant="listbox">
                        @foreach($this->availableProjects as $proj)
                            <flux:select.option value="{{ $proj['id'] }}">
                                <div class="flex items-center w-full gap-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium">{{ $proj['project_name'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $proj['short_address'] }}</div>
                                    </div>
                                    @if($proj['status'])
                                        <flux:badge size="sm" :color="$proj['status']->badge_color" inset="top bottom" class="shrink-0">
                                            {{ $proj['status']->title }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showProjectSelector', false)">Cancel</flux:button>
                    @if($selectedProjectForCreate)
                        <flux:button type="button" variant="primary" wire:click="selectProjectAndCreate({{ $selectedProjectForCreate }})">
                            Continue
                        </flux:button>
                    @else
                        <flux:button type="button" variant="primary" disabled>
                            Continue
                        </flux:button>
                    @endif
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- CREATE MODAL (PROJECT-SCOPED OR STANDALONE AFTER SELECTION) --}}
    @if($this->project)
        <flux:modal wire:model.self="showCreate" name="lien-waiver-create" class="max-w-xl">
            <form wire:submit="createWaiver" class="space-y-4">
                <div>
                    <flux:heading size="lg">Create Lien Waiver</flux:heading>
                    <flux:subheading>
                        Issue a waiver for {{ $this->project->project_name }} (e.g. for an upcoming client payment).
                        The current vendor is recorded as the claimant.
                    </flux:subheading>
                </div>

                <flux:select wire:model.live="newType" label="Waiver type">
                    @foreach($this->typeOptions as $opt)
                        <flux:select.option value="{{ $opt['value'] }}">{{ $opt['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex flex-nowrap items-start gap-4">
                    <div class="min-w-0 flex-1">
                        @if($newType === \App\Enums\LienWaiverType::UnconditionalFinal->value)
                            <flux:field>
                                <flux:label>Amount</flux:label>
                                <div class="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-semibold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                    PAID IN FULL
                                </div>
                                <flux:description>Final unconditional waivers are issued for full payment, so no dollar figure is shown.</flux:description>
                            </flux:field>
                        @else
                            <flux:input wire:model="newAmount" type="number" step="0.01" min="0.01" label="Amount" placeholder="0.00" />
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <flux:input wire:model="newThroughDate" type="date" label="Through date" />
                    </div>
                </div>

                <flux:separator variant="subtle" />

                <flux:input wire:model="newPayerName" label="Payer name" placeholder="Project client / GC name" />
                <flux:input wire:model="newPayerAddress" label="Payer address" placeholder="123 Main St" />
                <flux:input wire:model="newPayerCityStateZip" label="City, State Zip" placeholder="Sacramento, CA 95814" />

                <flux:textarea wire:model="newNotes" label="Internal notes (optional)" rows="2" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showCreate', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Create waiver</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
