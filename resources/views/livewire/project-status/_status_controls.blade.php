@php($projectStatuses = \App\Models\ProjectStatus::selectableStatuses())

<flux:input.group class="flex w-full text-sm">
    <flux:select wire:model.live="project_status" variant="listbox" class="flex-1" placeholder="Choose Status..." size="sm">
        @foreach($projectStatuses as $status)
            <flux:select.option :value="$status['code']" :label="$status['label']">
                <flux:badge size="sm" inset="top bottom" :color="$status['color']">
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
        size="sm"
    >
        Change
    </flux:button>
</flux:input.group>

<x-forms.error errorName="project_status" />
