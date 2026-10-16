@php
    $projectStatuses = \App\Models\ProjectStatus::selectableStatuses();

    // Standalone /projects owns the page width and its tighter filter rhythm;
    // embedded in a show-page column it must inherit that column's spacing
    // instead (otherwise its cards sit 8px apart inside a 16px column).
    $embedded = $view !== null;
@endphp

<div class="{{ $embedded ? 'space-y-4' : 'max-w-3xl space-y-2' }}">
    @if($view === NULL && !auth()->user()->is_browsing_as_client)
        <x-filter-card :class="$this->statusCards === [] ? 'mb-4' : ''">
            <x-slot:mobile>
                @include('livewire.projects.partials.filter-fields', ['layout' => 'stacked'])
            </x-slot:mobile>
            <x-slot:desktop>
                @include('livewire.projects.partials.filter-fields', ['layout' => 'inline'])
            </x-slot:desktop>
        </x-filter-card>

        @if($this->statusCards !== [])
            {{-- Flux dashboard stats, one row that scrolls sideways once it
                 outgrows the column: each open status with its count beside
                 it, the change since 30 days ago (green better, red worse) and
                 a 12-week sparkline. A card shows just its status (clicked
                 again, every status); picking several is the Status menu's
                 job, and the cards light up for whatever it holds. Selection
                 is aria-pressed, flipped by Alpine the moment it's clicked, so
                 it shows at once instead of waiting for the server. --}}
            <div class="flex gap-3 overflow-x-auto -mx-1 px-1 pt-1 pb-2 [scrollbar-width:thin]" role="group" aria-label="Projects by status"
                x-data="{
                    statuses() { return [].concat($wire.project_status_title ?? []).map(Number) },
                    has(code) { return this.statuses().includes(code) },
                    select(code) {
                        const statuses = this.statuses();
                        $wire.$set('project_status_title', statuses.length === 1 && statuses[0] === code ? [] : [code]);
                    },
                }">
                @foreach($this->statusCards as $card)
                    <flux:card size="sm"
                        wire:key="status-card-{{ $card['code'] }}"
                        role="button"
                        tabindex="0"
                        aria-pressed="{{ $card['selected'] ? 'true' : 'false' }}"
                        x-bind:aria-pressed="has({{ $card['code'] }}).toString()"
                        x-on:click="select({{ $card['code'] }})"
                        x-on:keydown.enter.prevent="select({{ $card['code'] }})"
                        x-on:keydown.space.prevent="select({{ $card['code'] }})"
                        class="shrink-0 w-52 overflow-hidden cursor-pointer select-none transition-colors duration-200 not-aria-pressed:hover:border-zinc-300 dark:not-aria-pressed:hover:border-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-400 {{ $card['style']['selected'] }}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <flux:badge size="sm" :color="$card['color']" inset="top bottom">{{ $card['label'] }}</flux:badge>
                                {{-- A Flux sm badge's shape in a fainter tint of the status colour. --}}
                                <span class="-my-1 inline-flex items-center whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium tabular-nums {{ $card['style']['count'] }}">{{ number_format($card['count']) }}</span>
                            </div>

                            <flux:tooltip :content="number_format($card['before']).' thirty days ago'" position="bottom">
                                @if($card['delta'] === 0)
                                    <flux:badge size="sm" color="zinc" icon="minus" inset="top bottom">0</flux:badge>
                                @else
                                    <flux:badge size="sm" :color="$card['better'] ? 'green' : 'red'" :icon="$card['delta'] > 0 ? 'arrow-trending-up' : 'arrow-trending-down'" inset="top bottom">{{ sprintf('%+d', $card['delta']) }}</flux:badge>
                                @endif
                            </flux:tooltip>
                        </div>

                        {{-- Top gutter only: the sides and bottom still bleed to the
                             card's edges, but a peak (and its stroke) keeps 6px of
                             headroom instead of being cut off at the top. --}}
                        <flux:chart class="-mx-4 -mb-4 mt-3 h-10" :value="$card['points']">
                            <flux:chart.svg gutter="6 0 0 0">
                                <flux:chart.line class="{{ $card['style']['line'] }}" />
                                <flux:chart.area class="{{ $card['style']['area'] }}" />
                            </flux:chart.svg>
                        </flux:chart>
                    </flux:card>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Fixed wire:keys on every child: Livewire's generated keys pick up
         a stray key from the first render's lazy skeletons, so the next
         render saw new keys and rebuilt these cards as skeletons — the flash
         under the table on every status change (2026-10-15). --}}
    <livewire:projects.projects-table
        wire:key="projects-index-table"
        :project-name-search="$project_name_search"
        :client-id="$client_id"
        :client-vendor-id="$client?->vendor_id"
        :project-status-title="$project_status_title"
        :view="$view"
        lazy.bundle
    />

    @php
        // Skip the component (and its lazy skeleton) entirely for clients with
        // no tracking rows, so no empty card ever flashes on the client page.
        $showEmailTracking = ! auth()->user()->is_browsing_as_client
            && $this->hasEmailTrackingForClient;
    @endphp

    @if($showEmailTracking)
        <livewire:projects.email-tracking-table
            wire:key="projects-index-email-tracking"
            :client-id="$client_id"
            lazy.bundle
        />
    @endif

    {{-- Recovery for soft-deleted projects. Standalone /projects only, and
         never for a client browsing their own view — this is internal. The
         card renders nothing unless something is actually trashed. --}}
    @if($view === NULL && ! auth()->user()->is_browsing_as_client)
        <livewire:projects.deleted-projects-table wire:key="projects-index-deleted" />
    @endif
</div>
