@php
    $projectStatuses = \App\Models\ProjectStatus::selectableStatuses();

    // Standalone /projects owns the page width and its tighter filter rhythm;
    // embedded in a show-page column it must inherit that column's spacing
    // instead (otherwise its cards sit 8px apart inside a 16px column).
    $embedded = $view !== null;
@endphp

<div class="{{ $embedded ? 'space-y-4' : 'max-w-3xl space-y-2' }}">
    @if($view === NULL && !auth()->user()->is_browsing_as_client)
        <x-filter-card class="mb-4">
            <x-slot:mobile>
                @include('livewire.projects.partials.filter-fields', ['layout' => 'stacked'])
            </x-slot:mobile>
            <x-slot:desktop>
                @include('livewire.projects.partials.filter-fields', ['layout' => 'inline'])
            </x-slot:desktop>
        </x-filter-card>
    @endif

    <livewire:projects.projects-table
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
            && (! $client_id || \App\Models\EmailTracking::clientFacing()->forClientAndItsLeads($client_id)->exists());
    @endphp

    @if($showEmailTracking)
        <livewire:projects.email-tracking-table
            :client-id="$client_id"
            lazy.bundle
        />
    @endif

    {{-- Recovery for soft-deleted projects. Standalone /projects only, and
         never for a client browsing their own view — this is internal. The
         card renders nothing unless something is actually trashed. --}}
    @if($view === NULL && ! auth()->user()->is_browsing_as_client)
        <livewire:projects.deleted-projects-table />
    @endif
</div>
