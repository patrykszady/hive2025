@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile, 'inline' for desktop --}}
])

@php($fieldWrap = $layout === 'inline' ? 'flex-1 min-w-0 w-full' : 'min-w-0 w-full')

<div class="{{ $layout === 'inline' ? 'flex flex-col sm:flex-row items-end gap-4' : 'flex flex-col gap-4' }}">
    <div class="{{ $fieldWrap }}">
        <flux:input
            wire:model.live.debounce.400ms="project_name_search"
            label="Project"
            icon="magnifying-glass"
            placeholder="Search projects..."
        />
    </div>

    <div class="{{ $fieldWrap }}">
        {{-- Filtered in the browser from /clients/picker-options: every
             client as a listbox option, in both layouts, was 866 KB of the
             page's 1 MB and travelled on every status-card click (2026-11-06). --}}
        <x-client-picker wire:model.live="client_id" label="Client" placeholder="All Clients..." clearable />
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select variant="listbox" multiple label="Status" clearable placeholder="All statuses..." wire:model.live="project_status_title">
            @foreach(\App\Models\ProjectStatus::selectableStatuses() as $status)
                <flux:select.option :value="$status['code']">
                    <flux:badge size="md" inset="top bottom" :color="$status['color']">
                        {{ $status['label'] }}
                    </flux:badge>
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>
</div>
