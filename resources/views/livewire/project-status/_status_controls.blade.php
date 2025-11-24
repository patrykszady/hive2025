@php($projectStatuses = \App\Models\ProjectStatus::selectableStatuses())

<flux:input.group class="flex w-full">
    <flux:select wire:model.live="project_status" variant="listbox" class="flex-1" placeholder="Choose Status...">
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

<x-forms.error errorName="project_status" />
