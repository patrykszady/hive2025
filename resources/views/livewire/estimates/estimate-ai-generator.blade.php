<div>
    <flux:modal name="estimate-ai-generator-modal" class="w-full max-w-5xl space-y-6" :dismissible="false">
        <div>
            <flux:heading size="lg">AI Estimate Generator</flux:heading>
            <flux:text class="mt-2">Describe the work and optionally upload a floorplan. Claude drafts line items from your catalog and your past estimates straight onto the estimate for you to review — leave out the client's contact details.</flux:text>
        </div>

        @if($showRules)
            {{-- HOW WE ESTIMATE: the company's rules, read on every draft --}}
            <div class="space-y-4">
                <div>
                    <flux:heading size="sm">How we estimate</flux:heading>
                    <flux:text class="mt-1">Claude reads these before every draft. Write them the way you would brief a new estimator: what to always include, what to leave out, how to size things.</flux:text>
                </div>

                @if($proposedRules->isNotEmpty())
                    <flux:callout variant="warning" icon="light-bulb">
                        <flux:callout.heading>Proposed from your corrections</flux:callout.heading>
                        <flux:callout.text>
                            <div class="space-y-2">
                                @foreach($proposedRules as $rule)
                                    <div class="flex items-start justify-between gap-3" wire:key="proposed-rule-{{ $rule->id }}">
                                        <div>
                                            <div>{{ $rule->text }}</div>
                                            @if($rule->evidence['note'] ?? null)
                                                <div class="text-xs opacity-75">{{ $rule->evidence['note'] }}</div>
                                            @endif
                                        </div>
                                        <div class="flex gap-1 shrink-0">
                                            <flux:button size="xs" variant="primary" icon="check" wire:click="approveRule({{ $rule->id }})">Approve</flux:button>
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="dismissRule({{ $rule->id }})">Dismiss</flux:button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <flux:card class="!p-0 overflow-hidden">
                    @forelse($activeRules as $rule)
                        <div class="flex items-start justify-between gap-3 px-4 py-3 border-b border-zinc-100 dark:border-zinc-700 last:border-b-0" wire:key="rule-{{ $rule->id }}">
                            <div class="text-sm">
                                {{ $rule->text }}
                                @if($rule->source === 'proposed')
                                    <flux:badge size="sm" color="indigo" class="ml-1">from corrections</flux:badge>
                                @endif
                            </div>
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Remove this rule?" />
                        </div>
                    @empty
                        <div class="px-4 py-6 text-sm text-zinc-500">No rules yet. Add the first one below.</div>
                    @endforelse
                </flux:card>

                <form wire:submit="addRule" class="flex items-end gap-2">
                    <flux:input wire:model="newRule" label="Add a rule" placeholder="e.g., Always include a Misc Framing line with a structural header." class="flex-1" />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                @error('newRule')
                    <flux:text variant="danger">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end">
                    <flux:button variant="ghost" icon="arrow-left" wire:click="$set('showRules', false)">Back</flux:button>
                </div>
            </div>
        @elseif(!$showPreview)
            <form wire:submit="generate" x-on:submit="$dispatch('draft-start')" class="space-y-4">
                {{-- The description, hidden while drafting so the table sits where it will stay --}}
                <div wire:loading.remove wire:target="generate" class="space-y-4">
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
                    <div class="flex items-center justify-between gap-3 flex-nowrap">
                        <flux:button variant="ghost" size="sm" icon="adjustments-horizontal" class="whitespace-nowrap" wire:click="$set('showRules', true)">
                            How we estimate
                            <flux:badge size="sm" color="zinc" class="ml-1">{{ $activeRules->count() }}</flux:badge>
                            @if($proposedRules->isNotEmpty())
                                <flux:badge size="sm" color="amber" class="ml-1">{{ $proposedRules->count() }} proposed</flux:badge>
                            @endif
                        </flux:button>
                        <div class="flex items-center gap-3">
                        <flux:button variant="ghost" class="whitespace-nowrap inline-flex items-center" x-on:click="$flux.modal('estimate-ai-generator-modal').close()">
                            Cancel
                        </flux:button>
                        <flux:button type="submit" variant="primary" icon="sparkles" class="whitespace-nowrap inline-flex items-center" :disabled="$isGenerating">
                            Generate Estimate
                        </flux:button>
                        </div>
                    </div>
                </div>

                {{-- Drafting: the banner and table the finished draft is then reviewed in --}}
                <div
                    wire:loading
                    wire:target="generate"
                    class="space-y-4"
                    x-data="draftStream()"
                    x-init="$nextTick(() => start())"
                    x-on:draft-start.window="start()"
                >
                    <div class="flex items-center gap-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border border-indigo-200 dark:border-indigo-800">
                        <div class="relative">
                            <flux:icon.sparkles class="w-5 h-5 text-indigo-500 animate-pulse" />
                            <div class="absolute inset-0 animate-ping">
                                <flux:icon.sparkles class="w-5 h-5 text-indigo-400 opacity-50" />
                            </div>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300" x-show="!streaming" x-text="statusMessages[currentStatus]">Reading your description…</p>
                            <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300" x-show="streaming" wire:stream="draft-status" x-ref="status"></p>
                            <p class="text-xs text-indigo-500 dark:text-indigo-400">Each line lands on {{ $sectionName }} as it is drafted. This usually takes 20-40 seconds.</p>
                        </div>
                    </div>

                    @include('livewire.estimates.partials.ai-draft-table', ['mode' => 'drafting'])
                </div>
            </form>
        @else
            {{-- DRAFT COMPLETE: the same banner and table, now on the estimate --}}
            <div class="space-y-4">
                <div class="flex items-center gap-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border border-indigo-200 dark:border-indigo-800">
                    <flux:icon.check-circle class="w-5 h-5 text-indigo-500" />
                    <div>
                        <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300">{{ count($generatedItems) }} {{ count($generatedItems) === 1 ? 'line item' : 'line items' }} drafted into {{ $sectionName }}</p>
                        <p class="text-xs text-indigo-500 dark:text-indigo-400">Click a line to edit it, change a quantity here, or remove what you don't need. Nothing else on the estimate changed.</p>
                    </div>
                </div>

                @if($error)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>The draft stopped early</flux:callout.heading>
                        <flux:callout.text>{{ $error }} The lines below were drafted before it stopped.</flux:callout.text>
                    </flux:callout>
                @endif

                <div x-data="draftPreview()">
                    @include('livewire.estimates.partials.ai-draft-table', ['mode' => 'review', 'items' => $generatedItems, 'total' => $estimatedTotal])
                </div>

                {{-- AI Reasoning --}}
                @if($reasoning)
                    <flux:callout variant="info" icon="light-bulb">
                        <flux:callout.heading>AI Analysis</flux:callout.heading>
                        <flux:callout.text>{{ $reasoning }}</flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Actions --}}
                <div class="flex items-center justify-between gap-3 flex-nowrap">
                    <flux:button variant="ghost" class="whitespace-nowrap" icon="trash" wire:click="discardDraft" wire:confirm="Remove every drafted line item from the estimate?">
                        Discard Draft
                    </flux:button>
                    <flux:button variant="primary" class="whitespace-nowrap" icon="check" wire:click="finish">
                        Done
                    </flux:button>
                </div>
            </div>
        @endif

        {{-- Defined at the root so both exist from the first paint: a <script>
             morphed in with a later render is never executed. --}}
        <script>
            function draftStream() {
                return {
                    streaming: false,
                    currentStatus: 0,
                    timer: null,
                    observer: null,
                    statusMessages: [
                        'Reading your description…',
                        'Reviewing your line item catalog…',
                        'Comparing with your past estimates…',
                        'Working out quantities…',
                    ],
                    start() {
                        this.streaming = false;
                        this.currentStatus = 0;
                        this.$refs.rows.innerHTML = '';
                        this.$refs.status.innerHTML = '';
                        this.$refs.total.textContent = '$0.00';

                        clearInterval(this.timer);
                        this.timer = setInterval(() => {
                            if (! this.streaming) {
                                this.currentStatus = (this.currentStatus + 1) % this.statusMessages.length;
                            }
                        }, 2500);

                        // The first streamed row ends the "thinking" messages.
                        if (! this.observer) {
                            this.observer = new MutationObserver(() => {
                                this.streaming = this.$refs.rows.children.length > 0;
                            });
                            this.observer.observe(this.$refs.rows, { childList: true });
                        }
                    },
                };
            }

            // Totals follow the quantity boxes as they are typed; the line itself is saved a moment later.
            function draftPreview() {
                return {
                    lineTotal(index) {
                        const item = this.$wire.generatedItems[index];

                        return item ? (Number(item.quantity) || 0) * (Number(item.cost) || 0) : 0;
                    },
                    total() {
                        return this.$wire.generatedItems.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.cost) || 0), 0);
                    },
                    money(amount) {
                        return '$' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
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
            /* The newest drafted row spins; every earlier one is ticked. */
            .draft-rows tr:last-child .draft-row-done { display: none; }
            .draft-rows tr:not(:last-child) .draft-row-current { display: none; }
        </style>
    </flux:modal>
</div>
