<div>
    <flux:modal name="estimate-ai-generator-modal" class="w-full max-w-4xl space-y-6">
        <div>
            <flux:heading size="lg">AI Estimate Generator</flux:heading>
            <flux:text class="mt-2">Describe the work needed and optionally upload a floorplan to generate line items automatically.</flux:text>
        </div>

        @if(!$showPreview)
            {{-- INPUT FORM --}}
            <form wire:submit="generate" class="space-y-4">
                {{-- Section Selector --}}
                <flux:select wire:model="sectionId" label="Add to Section">
                    @foreach($sections as $section)
                        <flux:select.option value="{{ $section->id }}">
                            {{ $section->name ?: 'Unnamed Section' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Inquiry Input --}}
                <flux:textarea
                    wire:model="inquiry"
                    label="Describe the Work"
                    placeholder="e.g., Rip and replace this bathroom. Replace the tub, vanities, tiles. Install 3 new vanity light fixture locations, a new exhaust fan, and 3 recessed lights."
                    rows="4"
                    required
                />
                @error('inquiry')
                    <flux:text variant="danger">{{ $message }}</flux:text>
                @enderror

                {{-- Floorplan Upload --}}
                <div>
                    <flux:label>Floorplan (Optional, CSV preferred)</flux:label>
                    <div class="mt-2">
                        <input
                            type="file"
                            wire:model="floorplan"
                            accept=".pdf,.jpg,.jpeg,.png,.csv"
                            class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300 dark:hover:file:bg-zinc-700"
                        />
                    </div>
                    @if($floorplan)
                        <flux:text class="mt-2 text-green-600 dark:text-green-400">
                            <flux:icon.check class="inline w-4 h-4" />
                            {{ $floorplan->getClientOriginalName() }} uploaded
                        </flux:text>
                    @endif
                    @error('floorplan')
                        <flux:text variant="danger">{{ $message }}</flux:text>
                    @enderror
                </div>

                {{-- Error Message --}}
                @if($error)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>Error</flux:callout.heading>
                        <flux:callout.text>{{ $error }}</flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-3 flex-nowrap">
                    <flux:button variant="ghost" class="whitespace-nowrap inline-flex items-center" x-on:click="$flux.modal('estimate-ai-generator-modal').close()">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary" class="whitespace-nowrap inline-flex items-center" :disabled="$isGenerating">
                        <span wire:loading.remove wire:target="generate" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <flux:icon.sparkles class="w-4 h-4" />
                            Generate Estimate
                        </span>
                        <span wire:loading wire:target="generate" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <flux:icon.arrow-path class="w-4 h-4 animate-spin" />
                            Generating...
                        </span>
                    </flux:button>
                </div>
            </form>
        @else
            {{-- PREVIEW RESULTS --}}
            <div class="space-y-4">
                {{-- AI Reasoning --}}
                @if($reasoning)
                    <flux:callout variant="info" icon="light-bulb">
                        <flux:callout.heading>AI Analysis</flux:callout.heading>
                        <flux:callout.text>{{ $reasoning }}</flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Generated Line Items --}}
                <div>
                    <flux:heading size="sm" class="mb-2">Generated Line Items ({{ count($generatedItems) }})</flux:heading>
                    <div class="border rounded-lg dark:border-zinc-700 overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-zinc-600 dark:text-zinc-300">Item</th>
                                    <th class="px-3 py-2 text-left font-medium text-zinc-600 dark:text-zinc-300">Category</th>
                                    <th class="px-3 py-2 text-center font-medium text-zinc-600 dark:text-zinc-300">Qty</th>
                                    <th class="px-3 py-2 text-right font-medium text-zinc-600 dark:text-zinc-300">Cost</th>
                                    <th class="px-3 py-2 text-right font-medium text-zinc-600 dark:text-zinc-300">Total</th>
                                    <th class="px-3 py-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y dark:divide-zinc-700">
                                @foreach($generatedItems as $index => $item)
                                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                        <td class="px-3 py-2 font-medium">{{ $item['name'] ?? 'Unknown' }}</td>
                                        <td class="px-3 py-2 text-zinc-500">{{ $item['category'] ?? '-' }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <input
                                                type="number"
                                                step="0.1"
                                                min="0.1"
                                                value="{{ $item['quantity'] ?? 1 }}"
                                                wire:change="updateQuantity({{ $index }}, $event.target.value)"
                                                class="w-16 px-2 py-1 text-center border rounded dark:bg-zinc-800 dark:border-zinc-600"
                                            />
                                        </td>
                                        <td class="px-3 py-2 text-right">${{ number_format($item['cost'] ?? 0, 2) }}</td>
                                        <td class="px-3 py-2 text-right font-medium">
                                            ${{ number_format(($item['quantity'] ?? 1) * ($item['cost'] ?? 0), 2) }}
                                        </td>
                                        <td class="px-3 py-2">
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="x-mark"
                                                wire:click="removeItem({{ $index }})"
                                            />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-zinc-100 dark:bg-zinc-800">
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-right font-semibold">Estimated Total:</td>
                                    <td class="px-3 py-2 text-right font-bold text-lg">${{ number_format($estimatedTotal, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex justify-between">
                    <flux:button variant="ghost" class="whitespace-nowrap" wire:click="$set('showPreview', false)">
                        <flux:icon.arrow-left class="w-4 h-4 mr-2" />
                        Back to Edit
                    </flux:button>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" class="whitespace-nowrap" x-on:click="$flux.modal('estimate-ai-generator-modal').close()">
                            Cancel
                        </flux:button>
                        <flux:button variant="primary" class="whitespace-nowrap inline-flex items-center" wire:click="applyEstimate" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="applyEstimate" class="inline-flex items-center gap-2">
                                <flux:icon.check class="w-4 h-4" />
                                Apply {{ count($generatedItems) }} Items
                            </span>
                            <span wire:loading wire:target="applyEstimate" class="inline-flex items-center gap-2">
                                <flux:icon.arrow-path class="w-4 h-4 animate-spin" />
                                Applying...
                            </span>
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
