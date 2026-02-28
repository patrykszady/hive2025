<div class="max-w-3xl">
    <x-island-card heading="Notifications" :separator="true">
        <x-slot:actions>
            @if ($this->unreadCount > 0)
                <flux:button variant="subtle" size="sm" wire:click="markAllAsRead">Mark all as read</flux:button>
            @endif
        </x-slot:actions>

        <div class="space-y-1">
            @forelse ($this->notifications as $notification)
                <div
                    wire:key="notification-{{ $notification->id }}"
                    wire:click="goToNotification({{ $notification->id }})"
                    class="flex items-start gap-3 p-3 rounded-lg cursor-pointer transition-colors
                        {{ $notification->isRead() ? 'opacity-60 hover:opacity-80' : 'bg-yellow-50 dark:bg-yellow-900/20 hover:bg-yellow-100 dark:hover:bg-yellow-900/30' }}"
                >
                    {{-- Unread dot --}}
                    <div class="pt-1.5 shrink-0">
                        @if (! $notification->isRead())
                            <div class="w-2.5 h-2.5 rounded-full bg-yellow-400"></div>
                        @else
                            <div class="w-2.5 h-2.5"></div>
                        @endif
                    </div>

                    {{-- Content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <flux:heading size="sm" class="truncate">{{ $notification->title }}</flux:heading>
                            <flux:text class="shrink-0 text-xs">{{ $notification->created_at->diffForHumans() }}</flux:text>
                        </div>
                        @if ($notification->body)
                            <flux:text class="mt-0.5 text-sm">{{ $notification->body }}</flux:text>
                        @endif
                    </div>

                    {{-- Mark as read button (without navigating) --}}
                    @if (! $notification->isRead())
                        <flux:button
                            variant="subtle"
                            size="xs"
                            icon="check"
                            wire:click.stop="markAsRead({{ $notification->id }})"
                            class="shrink-0 mt-1"
                        />
                    @endif
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
