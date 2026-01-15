<div>
    @if($iconOnly)
        <flux:button
            wire:click="initiateCall"
            wire:loading.attr="disabled"
            variant="{{ $buttonVariant }}"
            size="{{ $buttonSize }}"
            icon="phone"
            :disabled="$isLoading"
            title="Call {{ $customerName ?? $customerPhone }}"
        />
    @else
        <flux:button
            wire:click="initiateCall"
            wire:loading.attr="disabled"
            variant="{{ $buttonVariant }}"
            size="{{ $buttonSize }}"
            icon="phone"
            :disabled="$isLoading"
        >
            <span wire:loading.remove wire:target="initiateCall">{{ $buttonText }}</span>
            <span wire:loading wire:target="initiateCall">Calling...</span>
        </flux:button>
    @endif

    {{-- Success/Error Notifications --}}
    @if($lastSuccess)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 5000)"
            x-show="show"
            x-transition
            class="fixed bottom-4 right-4 z-50 max-w-sm"
        >
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.heading>Call Initiated</flux:callout.heading>
                <flux:callout.text>{{ $lastSuccess }}</flux:callout.text>
            </flux:callout>
        </div>
    @endif

    @if($lastError)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 5000)"
            x-show="show"
            x-transition
            class="fixed bottom-4 right-4 z-50 max-w-sm"
        >
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>Call Failed</flux:callout.heading>
                <flux:callout.text>{{ $lastError }}</flux:callout.text>
            </flux:callout>
        </div>
    @endif
</div>
