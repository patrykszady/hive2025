<div class="max-w-3xl">
    <x-island-card heading="Notifications" :separator="true">
        <x-slot:actions>
            @if ($this->unreadCount > 0)
                <flux:button variant="subtle" size="sm" wire:click="markAllAsRead">Mark all as read</flux:button>
            @endif
        </x-slot:actions>

        <div class="space-y-4">
            @forelse ($this->grouped as $date => $entries)
                @php
                    $day = \Carbon\Carbon::parse($date);
                    $label = $day->isToday() ? 'Today' : ($day->isYesterday() ? 'Yesterday' : $day->format('D, M j'));
                @endphp
                <div wire:key="day-{{ $date }}">
                    {{-- Day separator --}}
                    <div class="mb-1 flex items-center gap-3">
                        <flux:text class="shrink-0 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</flux:text>
                        <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
                    </div>

                    <div class="space-y-1">
                        @foreach ($entries as $entry)
                            @php
                                $notification = $entry['notification'];
                            @endphp
                            <div
                                wire:key="notification-{{ $notification->id }}"
                                wire:click="goToGroup({{ Js::from($entry['ids']) }})"
                                class="flex items-start gap-3 p-3 rounded-lg cursor-pointer transition-colors
                                    {{ $entry['unread'] ? 'bg-yellow-50 dark:bg-yellow-900/20 hover:bg-yellow-100 dark:hover:bg-yellow-900/30' : 'opacity-60 hover:opacity-80' }}"
                            >
                                {{-- Unread dot --}}
                                <div class="pt-1.5 shrink-0">
                                    @if ($entry['unread'])
                                        <div class="w-2.5 h-2.5 rounded-full bg-yellow-400"></div>
                                    @else
                                        <div class="w-2.5 h-2.5"></div>
                                    @endif
                                </div>

                                {{-- Type icon --}}
                                <div class="shrink-0 pt-0.5">
                                    @switch($notification->type)
                                        @case('voicemail_received')
                                            <flux:icon.microphone class="size-5 text-rose-500" />
                                            @break
                                        @case('missed_call')
                                            <flux:icon.phone-x-mark class="size-5 text-amber-500" />
                                            @break
                                        @case('sms_received')
                                            <flux:icon.chat-bubble-left class="size-5 text-sky-500" />
                                            @break
                                        @case('lead_created')
                                        @case('lead_times_picked')
                                            <flux:icon.bell class="size-5 text-green-500" />
                                            @break
                                        @case('vendor_times_selected')
                                            <flux:icon.calendar-days class="size-5 text-indigo-500" />
                                            @break
                                        @case('menards_browser')
                                            <flux:icon.receipt-percent class="size-5 text-orange-500" />
                                            @break
                                        @default
                                            <flux:icon.bell class="size-5 text-zinc-400" />
                                    @endswitch
                                </div>

                                {{-- Content --}}
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <flux:heading size="sm" class="truncate">{{ $notification->title }}</flux:heading>
                                            {{-- Identical notifications collapse into one row --}}
                                            @if ($entry['count'] > 1)
                                                <flux:badge size="sm" color="zinc" inset="top bottom">×{{ $entry['count'] }}</flux:badge>
                                            @endif
                                        </div>
                                        <flux:text class="shrink-0 text-xs">{{ $notification->created_at->diffForHumans() }}</flux:text>
                                    </div>
                                    @if ($notification->body)
                                        <flux:text class="mt-0.5 text-sm">{{ $notification->body }}</flux:text>
                                    @endif
                                </div>

                                {{-- Mark group as read (without navigating) --}}
                                @if ($entry['unread'])
                                    <flux:button
                                        variant="subtle"
                                        size="xs"
                                        icon="check"
                                        wire:click.stop="markGroupAsRead({{ Js::from($entry['ids']) }})"
                                        class="shrink-0 mt-1"
                                    />
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <flux:icon.bell class="mx-auto mb-2 size-8 text-zinc-300 dark:text-zinc-600" />
                    <flux:text>No notifications yet</flux:text>
                </div>
            @endforelse
        </div>

        @if ($this->notifications->hasPages())
            <div class="mt-4">
                {{ $this->notifications->links() }}
            </div>
        @endif
    </x-island-card>
</div>
