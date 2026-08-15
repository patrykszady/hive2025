<div class="max-w-lg space-y-4" @if (! $healthProbed) wire:init="probeHealth" @endif>
    {{-- Same shape as /banks: an intro card, red callouts for anything
         failing (with the provider's OWN error as the reason), then one card
         per account. --}}
    <x-island-card heading="Email Accounts" subheading="Mailboxes connected through Nylas — sending, calendars, and lead ingestion all ride on these grants.">
        <x-slot:actions>
            <flux:button wire:click="recheck" size="sm" variant="ghost" icon="arrow-path"
                wire:loading.attr="disabled" wire:target="recheck">
                Recheck
            </flux:button>
            <flux:button
                x-on:click="window.openNylasPopup('{{ route('company-email.login') }}')"
                size="sm"
                icon="plus"
            >
                Add Email Account
            </flux:button>
        </x-slot:actions>
    </x-island-card>

    @foreach ($this->accounts->filter(fn ($email) => $email->health['state'] === 'error') as $email)
        <flux:callout color="red" icon="exclamation-triangle">
            <flux:callout.heading>{{ $email->email }}</flux:callout.heading>
            <flux:callout.text>{{ $email->health['reason'] }}</flux:callout.text>
        </flux:callout>
    @endforeach

    @foreach ($this->accounts as $email)
        @php
            $health = $email->health;
            [$color, $label] = match ($health['state']) {
                'connected' => ['green', 'Connected'],
                'unlinked' => ['zinc', 'Not connected'],
                'checking' => ['zinc', 'Checking…'],
                default => ['red', 'Error'],
            };
        @endphp
        <x-island-card :heading="$email->email" wire:key="company-email-{{ $email->id }}">
            <x-slot:badge>
                <flux:badge inset="top bottom" :color="$color">{{ $label }}</flux:badge>
                @if ($health['provider'])
                    <flux:badge inset="top bottom" size="sm" color="zinc">{{ ucfirst($health['provider']) }}</flux:badge>
                @endif
            </x-slot:badge>
            <x-slot:actions>
                @if (! in_array($health['state'], ['connected', 'checking'], true))
                    <flux:button
                        x-on:click="window.openNylasPopup('{{ route('company-email.login') }}')"
                        size="sm" variant="filled"
                    >
                        Reconnect
                    </flux:button>
                @endif
                @if ($health['state'] !== 'checking')
                    <div class="text-xs"><i>checked {{ \Carbon\Carbon::parse($health['checked_at'])->diffForHumans() }}</i></div>
                @endif
            </x-slot:actions>

            @if ($health['state'] === 'error')
                <flux:subheading class="text-red-800!">{{ $health['reason'] }}</flux:subheading>
            @elseif ($health['state'] === 'unlinked')
                <flux:subheading>{{ $health['reason'] }}</flux:subheading>
            @endif

            <div class="text-xs text-zinc-500">
                @if ($email->grant_id)
                    Grant {{ $email->grant_id }}
                    @if ($health['grant_email'] && strcasecmp($health['grant_email'], $email->email) !== 0)
                        · authenticates as {{ $health['grant_email'] }}
                    @endif
                @else
                    No grant on file
                @endif
            </div>
        </x-island-card>
    @endforeach
</div>

{{-- @script runs inside Livewire's lifecycle, so the message listener is
     registered once per component instance and torn down on navigate —
     a bare <script> re-registered it on every wire:navigate visit. --}}
@script
<script>
window.openNylasPopup = function(url) {
    const width = 600;
    const height = 700;
    const left = (screen.width / 2) - (width / 2);
    const top = (screen.height / 2) - (height / 2);

    window.nylasPopup = window.open(
        url,
        'nylasAuth',
        `width=${width},height=${height},top=${top},left=${left},toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes`
    );
};

const onNylasMessage = function(event) {
    if (event.data.type === 'nylas-auth-success') {
        if (window.nylasPopup && !window.nylasPopup.closed) {
            window.nylasPopup.close();
        }
        window.location.reload();
    }
};

window.addEventListener('message', onNylasMessage);

document.addEventListener('livewire:navigating', function () {
    window.removeEventListener('message', onNylasMessage);
}, { once: true });
</script>
@endscript
