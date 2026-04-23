@props(['item'])

@if($item)
    <div class="space-y-4">
        {{-- Image --}}
        @if(!empty($item['image_url']))
            <div class="overflow-hidden rounded-lg bg-white dark:bg-zinc-800">
                <img
                    src="{{ $item['image_url'] }}"
                    alt="{{ $item['Description'] ?? '' }}"
                    class="w-full max-h-64 object-contain"
                    referrerpolicy="no-referrer"
                />
            </div>
        @endif

        {{-- Heading --}}
        <div class="flex items-center gap-2">
            <flux:heading size="lg">{{ $item['Description'] ?? 'Unknown Item' }}</flux:heading>
            @if(!empty($item['product_url']))
                <a href="{{ $item['product_url'] }}" target="_blank" class="ml-auto shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <flux:icon.arrow-top-right-on-square class="size-5" />
                </a>
            @endif
        </div>

        {{-- Details Grid --}}
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            @if(!empty($item['Manufacturer']))
                <div>
                    <flux:text class="text-zinc-400">Manufacturer</flux:text>
                    <flux:text class="font-medium">{{ $item['Manufacturer'] }}</flux:text>
                </div>
            @endif

            @if(!empty($item['ManufacturerPartNumber']))
                <div>
                    <flux:text class="text-zinc-400">Part Number</flux:text>
                    <flux:text class="font-medium">{{ $item['ManufacturerPartNumber'] }}</flux:text>
                </div>
            @endif

            @if(!empty($item['VendorCode'] ?? $item['ProductCode'] ?? null))
                <div>
                    <flux:text class="text-zinc-400">{{ !empty($item['_vendor_name']) ? $item['_vendor_name'] . ' SKU' : 'SKU' }}</flux:text>
                    <flux:text class="font-medium">{{ $item['VendorCode'] ?? $item['ProductCode'] }}</flux:text>
                </div>
            @endif

            @if(!empty($item['Quantity']))
                <div>
                    <flux:text class="text-zinc-400">Quantity</flux:text>
                    <flux:text class="font-medium">{{ $item['Quantity'] }} {{ $item['Unit'] ?? '' }}</flux:text>
                </div>
            @endif

            @if(!auth()->user()?->is_browsing_as_client)
                @if(!empty($item['Price']))
                    <div>
                        <flux:text class="text-zinc-400">Unit Price</flux:text>
                        <flux:text class="font-medium">{{ money($item['Price']) }}</flux:text>
                    </div>
                @endif

                @if(!empty($item['TotalPrice']))
                    <div>
                        <flux:text class="text-zinc-400">Total</flux:text>
                        <flux:text class="font-medium">{{ money($item['TotalPrice']) }}</flux:text>
                    </div>
                @endif
            @endif

            @if(!empty($item['Status']))
                @php(
                    $modalNormalizedStatus = match (true) {
                        in_array(strtolower(trim($item['Status'])), ['back ord', 'back order', 'bo', 'backorder', 'b/o'], true) => 'back order',
                        str_starts_with(strtolower(trim($item['Status'])), 'availabl') || strtolower(trim($item['Status'])) === 'available' => 'available',
                        in_array(strtolower(trim($item['Status'])), ['open', 'open item'], true) => 'open',
                        in_array(strtolower(trim($item['Status'])), ['received', 'recv', 'rec', 'delivered'], true) => 'received',
                        in_array(strtolower(trim($item['Status'])), ['shipped', 'ship'], true) => 'shipped',
                        in_array(strtolower(trim($item['Status'])), ['partial', 'partially shipped'], true) => 'partial',
                        in_array(strtolower(trim($item['Status'])), ['cancelled', 'cancel', 'canceled'], true) => 'cancelled',
                        default => strtolower(trim($item['Status'])),
                    }
                )
                @if($modalNormalizedStatus === 'back order' && !empty($item['ETA']) && !\Carbon\Carbon::parse($item['ETA'])->isFuture())
                    @php($modalNormalizedStatus = 'available')
                @endif
                @php($modalStatusColor = match($modalNormalizedStatus) {
                    'back order' => 'red',
                    'available', 'received', 'shipped' => 'green',
                    'open', 'partial' => 'amber',
                    'cancelled' => 'zinc',
                    default => 'zinc',
                })
                <div>
                    <flux:text class="text-zinc-400">Status</flux:text>
                    <div class="mt-0.5">
                        <flux:badge size="sm" :color="$modalStatusColor">{{ ucfirst($modalNormalizedStatus) }}</flux:badge>
                    </div>
                </div>
            @endif

            @if(!empty($item['ETA'] ?? $item['_expense_date']))
                <div>
                    <flux:text class="text-zinc-400">ETA</flux:text>
                    @php($modalDate = \Carbon\Carbon::parse($item['ETA'] ?? $item['_expense_date']))
                    <flux:text class="font-medium {{ $modalDate->isFuture() ? 'text-red-500 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $modalDate->format('M j, Y') }}</flux:text>
                </div>
            @endif

            @if(!empty($item['_vendor_name']))
                <div>
                    <flux:text class="text-zinc-400">Vendor</flux:text>
                    <flux:text class="font-medium">{{ $item['_vendor_name'] }}</flux:text>
                </div>
            @endif
        </div>

        @if(!empty($item['Area']))
            <div>
                <flux:text class="text-zinc-400 text-sm mb-1">Area</flux:text>
                <div class="flex flex-wrap gap-1.5">
                    @foreach((is_array($item['Area']) ? $item['Area'] : [$item['Area']]) as $area)
                        <flux:badge size="sm" color="zinc">{{ $area }}</flux:badge>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Status/ETA Change History --}}
        @if(!empty($item['_history']))
            <div>
                <flux:text class="text-zinc-400 text-sm mb-1">History</flux:text>
                <div class="space-y-2">
                    @foreach($item['_history'] as $change)
                        <div class="rounded-md bg-zinc-50 dark:bg-zinc-900/60 px-3 py-2 text-sm space-y-1">
                            @if(!empty($change['old_status']) && !empty($change['new_status']) && $change['old_status'] !== $change['new_status'])
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <flux:badge size="sm" color="red">{{ $change['old_status'] }}</flux:badge>
                                        <flux:icon.arrow-right class="size-3 text-zinc-400 shrink-0" />
                                        <flux:badge size="sm" color="green">{{ $change['new_status'] }}</flux:badge>
                                    </div>
                                    <flux:text class="text-xs text-zinc-400">{{ $change['date'] }}</flux:text>
                                </div>
                            @endif
                            @if(!empty($change['old_eta']) && !empty($change['new_eta']) && $change['old_eta'] !== $change['new_eta'])
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ \Carbon\Carbon::parse($change['old_eta'])->format('M j, Y') }}</span>
                                    <flux:icon.arrow-right class="size-3 text-zinc-400 shrink-0" />
                                    <span class="text-xs font-medium text-green-600 dark:text-green-400">{{ \Carbon\Carbon::parse($change['new_eta'])->format('M j, Y') }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if(!empty($item['Notes'] ?? $item['notes'] ?? null))
            <div>
                <flux:text class="text-zinc-400 text-sm mb-1">Notes</flux:text>
                <div class="rounded-md bg-zinc-50 dark:bg-zinc-900/60 px-3 py-2">
                    <p class="text-sm text-zinc-500 dark:text-white/70">{!! nl2br(e(trim((string) ($item['Notes'] ?? $item['notes'])))) !!}</p>
                </div>
            </div>
        @endif
    </div>
@endif
