<div class="w-full max-w-none">
    <x-island-card heading="Insurance Agents" :separator="true">
        <div class="space-y-4">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Business</flux:table.column>
                    <flux:table.column>Names</flux:table.column>
                    <flux:table.column>Emails</flux:table.column>
                    <flux:table.column>Addresses</flux:table.column>
                    <flux:table.column align="right">Vendor Docs</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse($this->agents as $business)
                        <flux:table.row :key="'business-'.$loop->index">
                            <flux:table.cell>{{ $business->business_name }}</flux:table.cell>
                            <flux:table.cell>
                                @if($business->agents->isNotEmpty())
                                    <div class="space-y-1">
                                        @foreach($business->agents as $agent)
                                            <div>{{ $agent->name ?: '-' }}</div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-zinc-500">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($business->agents->isNotEmpty())
                                    <div class="space-y-1">
                                        @foreach($business->agents as $agent)
                                            <div>
                                                @if($agent->email)
                                                    <a href="mailto:{{ $agent->email }}" class="text-blue-600 hover:underline">{{ $agent->email }}</a>
                                                @else
                                                    <span class="text-zinc-500">-</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-zinc-500">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($business->agents->isNotEmpty())
                                    <div class="space-y-1">
                                        @foreach($business->agents as $agent)
                                            <div>{{ $agent->address ?: '-' }}</div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-zinc-500">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="right">
                                @if($business->agents->isNotEmpty())
                                    <div class="space-y-1 text-right">
                                        @foreach($business->agents as $agent)
                                            <div>
                                                <button
                                                    type="button"
                                                    wire:click='openVendorDocsModal(@js($business->business_name), @js([$agent->id]), @js($agent->name ?: ""))'
                                                    class="text-blue-600 hover:underline"
                                                >
                                                    {{ $agent->vendor_docs_count }}
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-zinc-500">-</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">No agents found.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </x-island-card>

    <flux:modal wire:model="showVendorDocsModal" name="agent-vendor-docs" class="w-full max-w-5xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Vendor Docs</flux:heading>
                <flux:subheading>
                    {{ $selectedBusinessName }}
                    @if($selectedAgentName !== '')
                        · {{ $selectedAgentName }}
                    @endif
                </flux:subheading>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Number</flux:table.column>
                    <flux:table.column>Vendor</flux:table.column>
                    <flux:table.column>Effective</flux:table.column>
                    <flux:table.column>Expiration</flux:table.column>
                    <flux:table.column>File</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse($this->selectedVendorDocs as $doc)
                        <flux:table.row :key="'vendor-doc-'.$doc->id">
                            <flux:table.cell>{{ $doc->type_label }}</flux:table.cell>
                            <flux:table.cell>{{ $doc->number ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $doc->vendor?->business_name ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ optional($doc->effective_date)->format('m/d/Y') ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ optional($doc->expiration_date)->format('m/d/Y') ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @if($doc->doc_filename)
                                    <a href="{{ route('vendor_docs.show', ['filename' => $doc->doc_filename]) }}" class="text-blue-600 hover:underline" target="_blank">Open</a>
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500">No vendor docs found for this agent group.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:modal>
</div>
