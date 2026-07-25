<div class="w-full px-4 sm:px-6 lg:max-w-4xl lg:px-8 pb-5 mb-1 space-y-6">

    {{-- lazy island: the card paints from the shared skeleton first, then the
         real table swaps in — same loading treatment as the Leads card. --}}
    @island(name: 'receipts-table', lazy: island_lazy(), always: true)
        @placeholder
            <x-index-table.placeholder
                heading="Email Receipts"
                subheading="Manage email receipt patterns for automatic expense creation from forwarded emails."
                :columns="\App\Livewire\Receipts\ReceiptsIndex::columnDefs()"
                :compact="false"
            >
                <x-slot:actions>
                    @include('livewire.receipts.partials.index-actions')
                </x-slot:actions>
                <x-slot:toolbar>
                    @include('livewire.receipts.partials.search')
                </x-slot:toolbar>
            </x-index-table.placeholder>
        @endplaceholder
    <x-index-table heading="Email Receipts" subheading="Manage email receipt patterns for automatic expense creation from forwarded emails.">
        <x-slot:actions>
            @include('livewire.receipts.partials.index-actions')
        </x-slot:actions>

        <x-slot:toolbar>
            @include('livewire.receipts.partials.search')
        </x-slot:toolbar>

        <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
            <flux:table.columns>
                <flux:table.column class="w-[24%] min-w-0">Vendor</flux:table.column>
                <flux:table.column class="w-[30%] min-w-0">From Address</flux:table.column>
                <flux:table.column class="w-[24%] min-w-0">Subject Match</flux:table.column>
                <flux:table.column class="w-[14%]">Type</flux:table.column>
                <flux:table.column class="w-[8%]"></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->receipts as $receipt)
                    <flux:table.row :key="$receipt->id">
                        <flux:table.cell variant="strong" class="cursor-pointer" wire:click="edit({{ $receipt->id }})">
                            {{ $receipt->vendor?->business_name ?? 'Unknown' }}
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">
                            {{ $receipt->from_address }}
                        </flux:table.cell>
                        <flux:table.cell class="text-xs max-w-48">
                            @php
                                $subjects = $receipt->from_subject ?? [];
                                if (! is_array($subjects)) { $subjects = [$subjects]; }
                            @endphp
                            @if(empty($subjects))
                                —
                            @else
                                <div class="space-y-0.5">
                                    @foreach($subjects as $subj)
                                        <div class="truncate">{{ $subj }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($receipt->receipt_type == 1)
                                <flux:badge size="sm" color="sky" inset="top bottom">Purchase</flux:badge>
                            @elseif($receipt->receipt_type == 2)
                                <flux:badge size="sm" color="amber" inset="top bottom">Refund</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            {{-- inset: the xs button is 24px, which would push these rows to
                                 49px — 4px taller than every other index table's rows. --}}
                            <flux:button wire:click="delete({{ $receipt->id }})" wire:confirm="Delete this receipt pattern?" size="xs" variant="ghost" icon="trash" inset="top bottom" class="align-middle" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if($this->receipts->isEmpty())
            <div class="py-8 text-center text-sm text-zinc-500">No receipt patterns found.</div>
        @endif
    </x-index-table>
    @endisland

    {{-- CREATE / EDIT MODAL — same island name as the table so row/Add clicks
         (which are island-scoped) still re-render the modal. --}}
    @island(name: 'receipts-table')
    <x-form-modal name="receipt_form_modal">
        <x-slot:header>
            <flux:heading size="lg">{{ $editing_id ? 'Edit Receipt' : 'New Receipt' }}</flux:heading>
            <flux:separator variant="subtle" />
        </x-slot:header>

        <form id="receipt_form_modal_form" wire:submit="store" class="space-y-4">
            <flux:select wire:model="vendor_id" variant="listbox" searchable placeholder="Choose Vendor..." label="Vendor">
                <x-slot name="search">
                    <flux:select.search placeholder="Search vendors..." />
                </x-slot>
                @foreach($this->vendors as $vendor)
                    <flux:select.option wire:key="vendor-{{ $vendor->id }}" :value="$vendor->id">
                        {{ $vendor->business_name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="from_address" label="From Address" placeholder="sender@example.com or @domain.com" description="Use @domain.com for wildcard domain matching." />

            <div>
                <flux:label>Subject Match</flux:label>
                <flux:description class="mb-2">Partial match — email subject must contain any one of these. Add multiple to support different subject lines for the same vendor.</flux:description>
                <div class="space-y-2">
                    @foreach($from_subjects as $idx => $_subject)
                        <div wire:key="from-subject-{{ $idx }}" class="flex items-start gap-2">
                            <div class="flex-1">
                                <flux:input wire:model="from_subjects.{{ $idx }}" placeholder="order has been received" />
                            </div>
                            <flux:button wire:click="removeSubject({{ $idx }})" type="button" size="sm" variant="ghost" icon="trash" />
                        </div>
                    @endforeach
                </div>
                <flux:button wire:click="addSubject" type="button" size="xs" variant="ghost" icon="plus" class="mt-2">Add subject</flux:button>
            </div>

            <flux:select wire:model="receipt_type" label="Receipt Type">
                <flux:select.option value="1">Purchase</flux:select.option>
                <flux:select.option value="2">Refund</flux:select.option>
            </flux:select>

            <flux:separator />

            <flux:heading size="sm">Parsing Options</flux:heading>
            <flux:subheading size="sm" class="!mt-0 mb-2">Optional — used to extract receipt content from the email body.</flux:subheading>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <flux:input wire:model="options.receipt_start" label="Receipt Start" placeholder="Text marker for start" size="sm" />
                <flux:input wire:model="options.receipt_end" label="Receipt End" placeholder="Text marker for end" size="sm" />
                <flux:input wire:model="options.query_fields" label="Query Fields" placeholder="InvoiceId,OrderNumber" size="sm" />
                <flux:input wire:model="options.receipt_image_regex" label="Image Regex" placeholder="Regex to extract image URL" size="sm" />
                <flux:select wire:model="options.document_model" label="Document Model" size="sm" placeholder="None">
                    <flux:select.option value="">None</flux:select.option>
                    <flux:select.option value="prebuilt-receipt">prebuilt-receipt</flux:select.option>
                    <flux:select.option value="prebuilt-invoice">prebuilt-invoice</flux:select.option>
                </flux:select>
                <flux:select wire:model="options.ocr" label="OCR" size="sm" placeholder="No">
                    <flux:select.option value="">No</flux:select.option>
                    <flux:select.option value="yes">Yes</flux:select.option>
                </flux:select>
                <flux:select wire:model="options.ocr_type" label="OCR Type" size="sm" placeholder="None">
                    <flux:select.option value="">None</flux:select.option>
                    <flux:select.option value="html_to_pdf">html_to_pdf</flux:select.option>
                    <flux:select.option value="pdf_to_text">pdf_to_text</flux:select.option>
                </flux:select>
                <flux:select wire:model="options.image_extension" label="Image Extension" size="sm" placeholder="Default">
                    <flux:select.option value="">Default</flux:select.option>
                    <flux:select.option value="pdf">PDF</flux:select.option>
                    <flux:select.option value="png">PNG</flux:select.option>
                </flux:select>
            </div>
        </form>

        <x-slot:footer>
            <flux:spacer />
            <flux:button type="submit" form="receipt_form_modal_form" variant="primary">
                {{ $editing_id ? 'Update' : 'Create' }}
            </flux:button>
        </x-slot:footer>
    </x-form-modal>
    @endisland
</div>
