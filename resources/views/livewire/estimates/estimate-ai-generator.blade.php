<div>
    <flux:modal name="estimate-ai-generator-modal" class="w-full max-w-4xl space-y-6" :dismissible="false">
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

                {{-- Skeleton Loading Animation --}}
                <div wire:loading wire:target="generate" class="space-y-4" x-data="skeletonLoader()" x-init="start()">
                    {{-- AI Thinking Indicator --}}
                    <div class="flex items-center gap-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border border-indigo-200 dark:border-indigo-800">
                        <div class="relative">
                            <flux:icon.sparkles class="w-5 h-5 text-indigo-500 animate-pulse" />
                            <div class="absolute inset-0 animate-ping">
                                <flux:icon.sparkles class="w-5 h-5 text-indigo-400 opacity-50" />
                            </div>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300" x-text="statusMessages[currentStatus]">Analyzing your request...</p>
                            <p class="text-xs text-indigo-500 dark:text-indigo-400">This usually takes 10-20 seconds</p>
                        </div>
                    </div>

                    {{-- Skeleton Table --}}
                    <div class="border rounded-lg dark:border-zinc-700 overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-zinc-600 dark:text-zinc-300">Item</th>
                                    <th class="px-3 py-2 text-left font-medium text-zinc-600 dark:text-zinc-300">Category</th>
                                    <th class="px-3 py-2 text-center font-medium text-zinc-600 dark:text-zinc-300">Qty</th>
                                    <th class="px-3 py-2 text-right font-medium text-zinc-600 dark:text-zinc-300">Cost</th>
                                    <th class="px-3 py-2 text-right font-medium text-zinc-600 dark:text-zinc-300">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y dark:divide-zinc-700">
                                <template x-for="(item, index) in visibleItems" :key="index">
                                    <tr class="animate-fade-in" :class="{ 'opacity-50': index === visibleItems.length - 1 && isGenerating }">
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-2">
                                                <flux:icon.check class="w-4 h-4 text-green-500" x-show="index < visibleItems.length - 1 || !isGenerating" />
                                                <flux:icon.arrow-path class="w-4 h-4 text-indigo-500 animate-spin" x-show="index === visibleItems.length - 1 && isGenerating" />
                                                <span class="font-medium" x-text="item.name"></span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2 text-zinc-500" x-text="item.category"></td>
                                        <td class="px-3 py-2 text-center" x-text="item.qty"></td>
                                        <td class="px-3 py-2 text-right" x-text="'$' + item.cost.toFixed(2)"></td>
                                        <td class="px-3 py-2 text-right font-medium" x-text="'$' + (item.qty * item.cost).toFixed(2)"></td>
                                    </tr>
                                </template>
                                {{-- Skeleton rows for upcoming items --}}
                                <template x-for="i in Math.max(0, 8 - visibleItems.length)" :key="'skeleton-' + i">
                                    <tr class="opacity-30">
                                        <td class="px-3 py-2">
                                            <div class="h-4 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse" :style="'width: ' + (60 + Math.random() * 40) + '%'"></div>
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="h-4 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse w-20"></div>
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <div class="h-4 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse w-8 mx-auto"></div>
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="h-4 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse w-16 ml-auto"></div>
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="h-4 bg-zinc-200 dark:bg-zinc-700 rounded animate-pulse w-20 ml-auto"></div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="bg-zinc-100 dark:bg-zinc-800">
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-right font-semibold">Running Total:</td>
                                    <td class="px-3 py-2 text-right font-bold text-lg" x-text="'$' + runningTotal.toFixed(2)"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <script>
                    function skeletonLoader() {
                        return {
                            visibleItems: [],
                            isGenerating: true,
                            currentStatus: 0,
                            runningTotal: 0,
                            statusMessages: [
                                'Analyzing your request...',
                                'Reviewing line item catalog...',
                                'Matching work scope to items...',
                                'Calculating quantities...',
                                'Optimizing estimate...',
                                'Finalizing line items...'
                            ],
                            sampleItems: [
                                { name: 'Demo', category: 'Demolition', qty: 1, cost: 2250 },
                                { name: 'Shower Rough Plumbing', category: 'Plumbing', qty: 1, cost: 1050 },
                                { name: 'Cement Boards', category: 'Drywall', qty: 6, cost: 55 },
                                { name: '6" Recessed', category: 'Electrical', qty: 4, cost: 235 },
                                { name: 'Wall Tile', category: 'Tiles', qty: 80, cost: 26 },
                                { name: 'Floor Tile', category: 'Tiles', qty: 45, cost: 23 },
                                { name: 'Exhaust Fan', category: 'Electrical', qty: 1, cost: 750 },
                                { name: 'Shower Base', category: 'Tiles', qty: 1, cost: 2100 },
                                { name: 'Bathroom Install', category: 'Services', qty: 1, cost: 1450 },
                                { name: 'Painting', category: 'Painting', qty: 1, cost: 850 },
                            ],
                            start() {
                                this.visibleItems = [];
                                this.isGenerating = true;
                                this.currentStatus = 0;
                                this.runningTotal = 0;
                                this.addItemsGradually();
                                this.cycleStatus();
                            },
                            addItemsGradually() {
                                const addNext = () => {
                                    if (this.visibleItems.length < this.sampleItems.length) {
                                        const item = this.sampleItems[this.visibleItems.length];
                                        this.visibleItems.push(item);
                                        this.runningTotal += item.qty * item.cost;
                                        setTimeout(addNext, 1500 + Math.random() * 1000);
                                    } else {
                                        this.isGenerating = false;
                                    }
                                };
                                setTimeout(addNext, 800);
                            },
                            cycleStatus() {
                                const cycle = () => {
                                    this.currentStatus = (this.currentStatus + 1) % this.statusMessages.length;
                                    setTimeout(cycle, 2500);
                                };
                                setTimeout(cycle, 2500);
                            }
                        };
                    }
                </script>

                <style>
                    @keyframes fade-in {
                        from { opacity: 0; transform: translateY(-8px); }
                        to { opacity: 1; transform: translateY(0); }
                    }
                    .animate-fade-in {
                        animation: fade-in 0.3s ease-out forwards;
                    }
                </style>
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
