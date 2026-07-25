<div @class(['mt-4' => is_null($vendorId)])>
    {{-- Per-vendor usage (vendor show page) hides the card when empty. --}}
    @if(is_null($vendorId) || $this->events->total() > 0)
    <x-index-table heading="Email Tracking" :paginator="$this->events" wire:loading.class="opacity-50 text-opacity-50" wire:transition>
            {{-- Per-vendor card sits in a ~500px column: no table floor there.
                 The /vendors page is max-w-2xl (~630px interior), so the shared
                 640px floor is lowered to 600px via the CSS variable. --}}
            <flux:table
                class="{{ $vendorId ? 'table-fixed min-w-0 w-full' : 'index-table' }} [:where(&)]:p-0 [:where(&)]:space-y-0"
                :style="$vendorId ? null : '--index-table-min: 600px'"
            >
                <flux:table.columns>
                    @foreach(\App\Livewire\Vendors\VendorPaymentEmailTrackingTable::columnDefs() as $trackingColumn)
                        <flux:table.column class="{{ $trackingColumn['width'] }}">{{ $trackingColumn['label'] }}</flux:table.column>
                    @endforeach
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->events as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell class="w-[20%]">
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
                            <flux:table.cell class="w-[24%] min-w-0">
                                {{-- inset: without it this badge is 24px and the row grows to
                                     49px, 4px taller than every other index row (and the skeleton). --}}
                                <flux:badge size="sm" color="zinc" variant="outline" inset="top bottom" class="max-w-full min-w-0">
                                    <span class="truncate">{{ $event->email_template_name ?: 'Vendor Payment' }}</span>
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="w-[34%] min-w-0">
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
                                    {{-- Hover reveals every recipient address behind the "+N". --}}
                                    <flux:tooltip :content="implode(', ', $emails) ?: (string) $displayName" position="top">
                                        <div class="truncate text-sm text-zinc-700 dark:text-zinc-300 cursor-default">
                                            {{ $displayName }}@if($extraCount > 0) <span class="text-xs text-zinc-500 dark:text-zinc-400">+{{ $extraCount }}</span>@endif
                                        </div>
                                    </flux:tooltip>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="w-[22%] min-w-0 whitespace-nowrap">
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
                            <flux:table.cell colspan="4" class="text-center text-gray-500">No email tracking events found.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
    </x-index-table>
    @endif
</div>
