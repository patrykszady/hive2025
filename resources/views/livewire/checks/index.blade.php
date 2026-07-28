@php
    // Embedded in a show-page column: inherit that column's width instead of
    // imposing this page's own.
    $embedded = $view !== null;
@endphp
<div class="{{ $embedded ? '' : 'max-w-3xl' }}">
    @if($view === NULL)
        <x-filter-card class="mb-4">
            {{-- single copy: the inline layout stacks below sm on its own --}}
            @include('livewire.checks.partials.filter-fields', ['layout' => 'inline'])
        </x-filter-card>
    @endif

    {{-- Standalone page: lazy island so the card paints the shared skeleton
         first, like the Projects card. Embedded variants render immediately. --}}
    @island(name: 'checks-table', lazy: island_lazy($view === NULL), always: true)
        @placeholder
            <x-index-table.placeholder
                heading="Checks"
                :columns="\App\Livewire\Checks\ChecksIndex::columnDefs()"
                :rows="\App\Livewire\Checks\ChecksIndex::placeholderRows($view)"
                :page-size="\App\Livewire\Checks\ChecksIndex::placeholderRows($view)"
                :compact="false"
            />
        @endplaceholder
    <x-index-table heading="{{ $view === 'expenses.show' && $this->checks->count() === 1 ? 'Check' : 'Checks' }}" :paginator="$this->checks">
        @php($cell = $view !== NULL ? "!px-1.5 whitespace-nowrap" : "")
            <flux:table class="{{ $view !== NULL ? 'table-fixed min-w-0 w-full' : 'index-table' }} [:where(&)]:p-0 [:where(&)]:space-y-0">
                <flux:table.columns>
                    {{-- sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')"> --}}
                    <flux:table.column class="{{ $view === NULL ? 'w-[14%]' : '' }} {{ $cell }}">Amount</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')" class="{{ $view === NULL ? 'w-[13%]' : '' }} {{ $cell }}">Date</flux:table.column>
                    <flux:table.column class="{{ $view === NULL ? 'w-[13%]' : '' }} {{ $cell }}">Check #</flux:table.column>
                    <flux:table.column class="{{ $view === NULL ? 'w-[18%] min-w-0' : '' }} {{ $cell }}">Bank</flux:table.column>
                    @if($view === NULL)
                        <flux:table.column class="w-[24%] min-w-0">Payee</flux:table.column>
                    @endif
                    <flux:table.column class="{{ $view === NULL ? 'w-[18%] min-w-0' : '' }} {{ $cell }}">Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->checks as $check)
                        <flux:table.row :key="$check->id">
                            <flux:table.cell
                                variant="strong"
                                class="cursor-pointer {{ $cell }}"
                                >
                                <a wire:navigate.hover href="{{route('checks.show', $check->id)}}">
                                    {{ money($check->amount) }}
                                </a>
                            </flux:table.cell>
                            <flux:table.cell :class="$cell">{{ $check->date->format($view !== NULL ? 'm/d/y' : 'm/d/Y') }}</flux:table.cell>
                            <flux:table.cell class="cursor-pointer min-w-0 {{ $cell }}">
                                <x-table-link
                                    :href="route('checks.show', $check->id)"
                                    :label="(string) ($check->check_type != 'Check' ? $check->check_type : $check->check_number)"
                                />
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap min-w-0 {{ $view !== NULL ? '!px-1.5' : '' }}">
                                @php($bankName = $check->bank_account?->bank?->name ?? '')
                                @if($view === NULL)
                                    <x-truncate-tooltip :content="$bankName">
                                        <div class="truncate">{{ $bankName }}</div>
                                    </x-truncate-tooltip>
                                @elseif($view !== NULL && mb_strlen($bankName) > 10)
                                    <flux:tooltip :content="$bankName" position="top">
                                        <span>{{ \Illuminate\Support\Str::limit($bankName, 10) }}</span>
                                    </flux:tooltip>
                                @else
                                    {{ $bankName }}
                                @endif
                            </flux:table.cell>
                            @if($view === NULL)
                                {{-- Payee links to whoever it names: the vendor, the
                                     via-vendor, or the team member. --}}
                                <flux:table.cell class="min-w-0 {{ $check->payeeUrl() ? 'cursor-pointer' : '' }}">
                                    <x-table-link
                                        :href="$check->payeeUrl()"
                                        :label="(string) $check->owner"
                                    />
                                </flux:table.cell>
                            @endif
                            <flux:table.cell :class="$cell">
                                @if($view !== NULL && mb_strlen($check->status) > 8)
                                    {{-- Compact card: truncate long statuses; full text on hover. --}}
                                    <flux:tooltip :content="$check->status" position="top">
                                        <flux:badge size="sm" :color="$check->statusColor" inset="top bottom">{{ \Illuminate\Support\Str::limit($check->status, 8) }}</flux:badge>
                                    </flux:tooltip>
                                @else
                                    <flux:badge size="sm" :color="$check->statusColor" inset="top bottom">{{ $check->status }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
    </x-index-table>
    @endisland
</div>
