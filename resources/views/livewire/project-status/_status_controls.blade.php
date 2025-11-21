@php($projectStatuses = \App\Models\ProjectStatus::selectableStatuses())

<div class="flex max-w-lg -mt-1 rounded-md shadow-xs">
    <flux:input.group>
        <flux:input wire:model.live="project_status_date" type="date" max="2999-12-31" placeholder="2023-12-31" />

        <flux:select wire:model.live="project_status" variant="listbox" class="max-w-fit" placeholder="Choose Status...">
            @foreach($projectStatuses as $status)
                <flux:select.option :value="$status['code']">
                    <flux:badge size="md" inset="top bottom" :color="$status['color']">
                        {{ $status['label'] }}
                    </flux:badge>
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:button
            wire:click="update_project"
            wire:loading.attr="disabled"
            wire:loading.class="opacity-50"
            icon="arrow-uturn-right"
        >
            Change
        </flux:button>
    </flux:input.group>
</div>

<x-forms.error errorName="project_status" />
