{{-- Root wrapper carries the transition like every other lazy index card, so
     Livewire patches the card in place instead of recreating it. --}}
<div wire:transition>
@php
    $columns = \App\Livewire\Projects\DeletedProjectsTable::columnDefs();
@endphp

{{-- No trashed projects, no card — this is a recovery tool, not a fixture of
     the page. Starts collapsed: it matters when you need it, not before. --}}
@if($this->deletedProjects->isNotEmpty())
    <x-index-table
        heading="Deleted Projects"
        :collapsible="true"
        :expanded="false"
        :paginator="$this->deletedProjects"
        wire:loading.class="opacity-50"
        wire:transition
    >
        <x-slot:badge>
            <flux:badge color="zinc" size="sm">{{ $this->deletedProjects->total() }}</flux:badge>
        </x-slot:badge>

        <x-index-table.table :columns="$columns">
            @foreach($this->deletedProjects as $project)
                <flux:table.row :key="'deleted-'.$project->id">
                    <flux:table.cell variant="strong" class="w-[40%] min-w-0">
                        <x-truncate-tooltip :content="$project->project_name">
                            <div class="truncate">{{ $project->project_name }}</div>
                        </x-truncate-tooltip>
                    </flux:table.cell>
                    <flux:table.cell class="w-[28%] min-w-0">
                        <x-truncate-tooltip :content="$project->client?->name ?? '—'">
                            <div class="truncate">{{ $project->client?->name ?? '—' }}</div>
                        </x-truncate-tooltip>
                    </flux:table.cell>
                    <flux:table.cell class="w-[20%] whitespace-nowrap">
                        {{ $project->deleted_at?->format('m/d/Y') }}
                    </flux:table.cell>
                    <flux:table.cell class="w-[12%] text-right">
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="arrow-uturn-left"
                            wire:click="restoreProject({{ $project->id }})"
                            wire:confirm="Restore this project? Its estimates come back with it."
                            wire:loading.attr="disabled"
                        >Restore</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </x-index-table.table>
    </x-index-table>
@endif
</div>
