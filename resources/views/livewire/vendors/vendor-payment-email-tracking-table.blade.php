<div class="mt-4">
    <x-island-card heading="Email Tracking" :separator="true" wire:loading.class="opacity-50 text-opacity-50" wire:transition>
        <div class="space-y-2">
            <flux:table :paginate="$this->events->hasPages() ? $this->events : null">
                <flux:table.columns>
                    <flux:table.column>Event</flux:table.column>
                    <flux:table.column>Template</flux:table.column>
                    <flux:table.column class="w-56">Vendor</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->events as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell>
                                <flux:badge
                                    size="sm"
                                    :color="match($event->event_type) {
                                        'opened' => 'blue',
                                        'link_clicked', 'clicked' => 'green',
                                        'replied' => 'purple',
                                        'bounced' => 'red',
                                        default => 'zinc'
                                    }"
                                    inset="top bottom"
                                >
                                    {{ ucfirst(str_replace('_', ' ', $event->event_type)) }}
                                    @if(isset($event->event_count) && $event->event_count > 1)
                                        <span class="ml-1">x{{ $event->event_count }}</span>
                                    @endif
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc" variant="outline">
                                    {{ $event->email_template_name ?: 'Vendor Payment' }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="w-56">
                                @php
                                    $names = $event->recipient_vendor_names ?? collect();
                                    $names = $names instanceof \Illuminate\Support\Collection ? $names : collect($names);
                                    $nameCount = $names->count();
                                    $firstName = $nameCount > 0 ? $names->first() : null;

                                    $emails = is_array($event->all_recipient_emails ?? null) ? $event->all_recipient_emails : [];
                                    $emailCount = count($emails);
                                    $firstEmail = $emailCount > 0 ? $emails[0] : null;

                                    $displayName = $firstName ?: $firstEmail;
                                    $extraCount = $nameCount > 0
                                        ? max($nameCount - 1, 0)
                                        : max($emailCount - 1, 0);
                                @endphp

                                @if($displayName)
                                    <div class="text-sm text-zinc-700 dark:text-zinc-300">
                                        {{ $displayName }}@if($extraCount > 0) <span class="text-xs text-zinc-500 dark:text-zinc-400">+{{ $extraCount }}</span>@endif
                                    </div>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($event->event_at)
                                    @php
                                        $daysAgo = $event->event_at->diffInDays(now());
                                        $dateLabel = $daysAgo > 14
                                            ? $event->event_at->format('m/d/y')
                                            : $event->event_at->diffForHumans();
                                    @endphp
                                    <div class="text-sm text-zinc-700 dark:text-zinc-300">{{ $dateLabel }}</div>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-gray-500">No vendor payment email tracking events found.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </x-island-card>
</div>
