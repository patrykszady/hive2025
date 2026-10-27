{{-- The permit packet for this estimate's job: GS cover letter + this
     estimate + the plans, one PDF (App\Livewire\Estimates\EstimatePermitPacket). --}}
<div>
<flux:modal name="estimate-permit-packet-modal" class="w-full max-w-lg">
    <div class="space-y-5" data-permit-packet>
        <div>
            <flux:heading size="lg">Permit packet</flux:heading>
            <flux:text class="mt-1">The GS cover letter, this estimate as the Scope of Work &amp; Contract, and the plans — one PDF for the building department.</flux:text>
        </div>

        <flux:field>
            <flux:label>Plans</flux:label>
            <input
                type="file"
                wire:model="plansUpload"
                accept=".pdf"
                class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-white/10 dark:file:text-zinc-200"
            />
            <flux:text wire:loading wire:target="plansUpload" size="sm" class="mt-1">Uploading…</flux:text>
            @if ($this->packet?->plans_name)
                <flux:text size="sm" class="mt-1" data-permit-plans>{{ $this->packet->plans_name }}</flux:text>
            @endif
            <flux:error name="plansUpload" />
        </flux:field>

        <div class="grid items-end gap-4 {{ $pickingTown ? 'sm:grid-cols-2' : '' }}">
            <flux:field>
                <flux:label>Title</flux:label>
                <flux:input wire:model="title" placeholder="BASEMENT REMODEL" />
                <flux:error name="title" />
            </flux:field>

            @if ($pickingTown)
                <flux:field data-permit-town-picker>
                    <flux:label>Permit issued by</flux:label>
                    <flux:select wire:model.live="town" variant="listbox">
                        @foreach ($townOptions as $option)
                            <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>
            @endif
        </div>

        @if ($pickingTown)
            <flux:text size="sm" class="text-zinc-500" data-permit-town-note>The lot sits in {{ $townOptions[0] }}; the mail goes to {{ $townOptions[1] }}. Pick the building department that issues the permit.</flux:text>
        @endif

        {{-- TOWN + CODES: one line unless someone opens them. --}}
        <div class="text-sm">
            @if ($editingCodes)
                <flux:field>
                    <flux:label>Codes</flux:label>
                    <flux:textarea wire:model="codesText" rows="8" class="font-mono text-xs" />
                    <flux:error name="codesText" />
                    <flux:description data-permit-codes-origin>
                        @if ($this->codesSource())
                            <a href="{{ $this->codesSource() }}" target="_blank" rel="noopener" class="underline">{{ $this->codesOrigin() }}</a>
                        @else
                            {{ $this->codesOrigin() }}
                        @endif
                    </flux:description>
                </flux:field>
            @else
                <flux:text size="sm" class="text-zinc-500" data-permit-codes-toggle>
                    @if (! $pickingTown && $this->jurisdiction() !== '')
                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $this->jurisdiction() }}</span> ·
                    @endif
                    @if ($lookingUp)
                        <span data-permit-codes-lookup wire:poll.4s="checkLookup">looking up {{ $this->jurisdiction() }}'s codes on the village's website…</span>
                    @else
                        {{ count(array_filter(preg_split('/\r?\n/', $codesText))) }} codes on the letter
                    @endif
                    · <button type="button" wire:click="$set('editingCodes', true)" class="underline hover:text-zinc-800 dark:hover:text-zinc-200">edit</button>
                </flux:text>
            @endif
        </div>

        <details class="text-sm">
            <summary class="cursor-pointer text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">Engineer notes (optional)</summary>
            <div class="mt-2">
                @if ($this->packet?->notes_name)
                    <div class="flex items-center gap-2">
                        <flux:text size="sm">{{ $this->packet->notes_name }}</flux:text>
                        <flux:button size="xs" variant="ghost" wire:click="removeNotes">Remove</flux:button>
                    </div>
                @else
                    <input
                        type="file"
                        wire:model="notesUpload"
                        accept=".pdf"
                        class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-white/10 dark:file:text-zinc-200"
                    />
                    <flux:text wire:loading wire:target="notesUpload" size="sm" class="mt-1">Uploading…</flux:text>
                    <flux:error name="notesUpload" />
                @endif
            </div>
        </details>

        @if ($error)
            <flux:text size="sm" class="text-red-600 dark:text-red-400" data-permit-error>{{ $error }}</flux:text>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="primary" icon="arrow-down-tray" wire:click="generate" wire:loading.attr="disabled" wire:target="generate,plansUpload,notesUpload">Download packet</flux:button>
            <flux:button variant="ghost" wire:click="previewCoverLetter" wire:loading.attr="disabled" wire:target="previewCoverLetter">Cover letter only</flux:button>
            @if ($this->packet?->generated_at)
                <flux:text size="sm" class="ml-auto" data-permit-packet-last>
                    Last built {{ $this->packet->generated_at->timezone('America/Chicago')->format('M j, g:i A') }}
                    · <button type="button" wire:click="download" class="underline">download again</button>
                </flux:text>
            @endif
        </div>
    </div>
</flux:modal>
</div>
