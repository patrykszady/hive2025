<div class="max-w-4xl py-8 space-y-6" wire:transition>
    @php($waiver = $this->waiver)

    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">{{ $waiver->typeLabel() }}</flux:heading>
            <p class="text-sm text-zinc-500">
                Project:
                @if($waiver->project)
                    <a href="{{ route('projects.show', $waiver->project) }}" class="font-semibold text-zinc-700 no-underline hover:no-underline hover:text-indigo-600 dark:text-zinc-300 dark:hover:text-indigo-400">
                        {{ $waiver->project->project_name ?? '—' }}
                        @if($waiver->project->address)
                            &middot; {{ $waiver->project->address }}
                        @endif
                    </a>
                @else
                    <strong>—</strong>
                @endif
            </p>
        </div>
        <flux:badge :color="$waiver->status?->color() ?? 'zinc'">
            {{ $waiver->status?->label() }}
        </flux:badge>
    </div>

    <x-details.card title="Waiver Details">
        <x-slot:details>
            @php($payerOverrideName = data_get(json_decode((string) $waiver->notes, true), 'payer.name'))
            <x-details.row 
                title="{{ $payerOverrideName ? 'Property Owner (Payer)' : 'Contractor (Payer)' }}"
                :content="$payerOverrideName ?: ($waiver->payerVendor?->business_name ?? '—')"
            />

            @php($payerOverrideAddress = data_get(json_decode((string) $waiver->notes, true), 'payer.address'))
            @php($payerOverrideCityStateZip = data_get(json_decode((string) $waiver->notes, true), 'payer.city_state_zip'))
            @php($payerAddressLine1 = $payerOverrideName ? trim((string) $payerOverrideAddress) : trim(implode(', ', array_filter([$waiver->payerVendor?->address]))) )
            @php($payerAddressLine2 = $payerOverrideName ? trim((string) $payerOverrideCityStateZip) : trim(implode(', ', array_filter([$waiver->payerVendor?->city, trim(implode(' ', array_filter([$waiver->payerVendor?->state, $waiver->payerVendor?->zip_code])))]))))
            <x-details.row title="Payer Address">
                <div>{{ $payerAddressLine1 !== '' ? $payerAddressLine1 : '—' }}</div>
                <div class="text-zinc-500 dark:text-zinc-400 text-sm">{{ $payerAddressLine2 !== '' ? $payerAddressLine2 : '—' }}</div>
            </x-details.row>

            <x-details.row 
                title="Owner / Customer"
                :content="$waiver->project?->client?->name ?? '—'"
            />

            @php($projectAddressLine1 = trim(implode(', ', array_filter([$waiver->project?->address, $waiver->project?->address_2]))))
            @php($projectAddressLine2 = trim(implode(', ', array_filter([$waiver->project?->city, trim(implode(' ', array_filter([$waiver->project?->state, $waiver->project?->zip_code])))]))))
            <x-details.row title="Project Address">
                <div>{{ $projectAddressLine1 !== '' ? $projectAddressLine1 : '—' }}</div>
                <div class="text-zinc-500 dark:text-zinc-400 text-sm">{{ $projectAddressLine2 !== '' ? $projectAddressLine2 : '—' }}</div>
            </x-details.row>

            @php($estimateNumbers = $waiver->project?->estimates?->pluck('number')->filter()->values() ?? collect())
            <x-details.row 
                title="Estimate Number(s)"
                :content="$estimateNumbers->isNotEmpty()
                    ? (($waiver->project?->project_name ? $waiver->project->project_name . ' — ' : '') . '#' . $estimateNumbers->implode(', #'))
                    : ($waiver->project?->project_name ?? '—')"
            />

            <x-details.row 
                title="Vendor (Claimant)"
                :content="$waiver->vendor?->business_name ?? '—'"
            />

            @php($claimantAddressLine1 = trim((string) $waiver->vendor?->address))
            @php($claimantAddressLine2 = trim(implode(', ', array_filter([$waiver->vendor?->city, trim(implode(' ', array_filter([$waiver->vendor?->state, $waiver->vendor?->zip_code])))]))))
            <x-details.row title="Claimant Address">
                <div>{{ $claimantAddressLine1 !== '' ? $claimantAddressLine1 : '—' }}</div>
                <div class="text-zinc-500 dark:text-zinc-400 text-sm">{{ $claimantAddressLine2 !== '' ? $claimantAddressLine2 : '—' }}</div>
            </x-details.row>

            <x-details.row title="Payment Amount">
                @if($waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal)
                    PAID IN FULL
                @elseif($waiver->amount !== null)
                    ${{ number_format((float) $waiver->amount, 2) }}
                @elseif($waiver->payment?->amount !== null)
                    ${{ number_format((float) $waiver->payment->amount, 2) }}
                @else
                    —
                @endif
            </x-details.row>

            <x-details.row 
                title="Through Date"
                :content="optional($waiver->through_date)->format('F j, Y')"
            />

            @if((float) $waiver->exceptions_amount > 0)
                <x-details.row 
                    title="Exceptions / Disputed Amount"
                    :content="'$' . number_format((float) $waiver->exceptions_amount, 2)"
                />
            @endif

            @if($waiver->check)
                <x-details.row title="Check / Reference">
                    {{ $waiver->check->check_type }}
                    @if($waiver->check->check_number) #{{ $waiver->check->check_number }} @endif
                    @if($waiver->check->date)
                        · {{ optional($waiver->check->date)->format('M j, Y') }}
                    @endif
                </x-details.row>
            @endif

            <x-details.row 
                title="Jurisdiction"
                :content="$waiver->jurisdiction"
            />

            <x-details.row 
                title="Document Hash"
                :content="$waiver->document_hash ?? '—'"
                :copyable="true"
            />
        </x-slot:details>

        <x-slot:header_buttons>
            <flux:button size="sm" icon="arrow-down-tray" href="{{ route('lien-waivers.download', $waiver) }}">
                Download PDF
            </flux:button>
        </x-slot:header_buttons>
    </x-details.card>

    @if($this->canSign)
        <x-island-card heading="Sign Lien Waiver">
            <div x-data="signaturePad()">
                <flux:text class="mb-4">
                    By signing below you, on behalf of <strong>{{ $waiver->vendor?->business_name }}</strong>,
                    acknowledge the terms of this {{ $waiver->type?->shortLabel() }} waiver and release.
                </flux:text>

                <div class="mb-4 flex flex-nowrap items-start gap-4">
                    <div class="min-w-0 flex-1">
                        <flux:field>
                            <flux:label>Name</flux:label>
                            <flux:input
                                wire:model.live.debounce.1000ms="signerName"
                                placeholder="Full Legal Name"
                            />
                            <flux:description class="italic">Type your name as consent to electronically sign this Lien Waiver.</flux:description>
                            <flux:error name="signerName" />
                        </flux:field>
                    </div>

                    <div class="min-w-0 flex-1">
                        <flux:field>
                            <flux:label>Title</flux:label>
                            <flux:input wire:model="signerTitle" />
                            <flux:error name="signerTitle" />
                        </flux:field>
                    </div>
                </div>

                @if($nameVerified)
                {{-- Toggle: Enable Type Signature --}}
                <div class="flex items-center gap-3 mt-4">
                    <flux:text class="font-semibold">Enable Type Signature</flux:text>
                    <flux:switch
                        wire:model.live="typeSignature"
                        x-on:change="toggleMode()"
                    />
                </div>
                @endif

                {{-- Canvas Signature Area --}}
                <div x-show="$wire.nameVerified" x-cloak class="mt-4">
                    <div wire:ignore class="relative border border-zinc-300 dark:border-zinc-600 rounded-lg bg-white dark:bg-zinc-800">
                        <div
                            x-show="isTypeMode"
                            class="absolute inset-0 z-20 cursor-default rounded-lg"
                            x-cloak
                        ></div>

                        <canvas
                            x-ref="signatureCanvas"
                            class="w-full rounded-lg"
                            style="height: 150px; touch-action: none; cursor: crosshair;"
                        ></canvas>

                        {{-- X mark and signature line --}}
                        <div class="absolute bottom-4 left-4 right-4 pointer-events-none">
                            <div class="flex items-end gap-2">
                                <span class="text-xl font-bold text-zinc-400 dark:text-zinc-500">X</span>
                                <div class="flex-1 border-b-2 border-zinc-300 dark:border-zinc-600">
                                    <div x-show="isTypeMode && typedName" class="flex items-baseline justify-between px-1" x-cloak>
                                        <span class="text-zinc-700 dark:text-zinc-300 truncate" x-text="typedName" style="font-family: 'Brush Script MT', 'Segoe Script', cursive; font-size: 24px; line-height: 1;"></span>
                                        <span class="text-[10px] text-zinc-400 dark:text-zinc-500 whitespace-nowrap ml-2" x-text="typedDate"></span>
                                    </div>
                                    <div x-show="!isTypeMode && manualDate" class="flex justify-end px-1" x-cloak>
                                        <span class="text-[10px] text-zinc-400 dark:text-zinc-500 whitespace-nowrap" x-text="manualDate"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @error('signatureData')
                        <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                    @enderror

                    <div class="flex items-center justify-between pt-4">
                        <flux:button variant="ghost" type="button" x-on:click="clearSignature()">
                            Clear Signature
                        </flux:button>
                        <flux:button variant="primary" wire:loading.attr="disabled" x-on:click="captureAndSign()">
                            <span wire:loading.remove wire:target="sign">Sign Lien Waiver</span>
                            <span wire:loading wire:target="sign">Signing...</span>
                        </flux:button>
                    </div>
                </div>
            </div>
        </x-island-card>
    @endif

    @if($waiver->signatures->isNotEmpty())
        <x-island-card heading="Signatures">
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach($waiver->signatures as $sig)
                    <li class="py-3 flex items-center justify-between">
                        <div>
                            <p class="font-medium">{{ $sig->signer_name }}
                                @if($sig->signer_title)
                                    <span class="text-zinc-500 text-xs">— {{ $sig->signer_title }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-zinc-500">
                                {{ optional($sig->signed_at)->format('M j, Y g:i A') }}
                                · IP {{ $sig->ip_address }}
                            </p>
                        </div>
                        @if($sig->signature_type === 'draw')
                            <img src="{{ $sig->signature_data }}" class="max-h-12" alt="signature">
                        @else
                            <span class="text-2xl" style="font-family:'Brush Script MT', cursive;">
                                {{ $sig->signer_name }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-island-card>
    @endif

    <script src="{{ asset('js/signature_pad.umd.min.js') }}"></script>

    @script
    <script>
        Alpine.data('signaturePad', () => {
            let pad = null;
            const CANVAS_HEIGHT = 150;

            return {
                isTypeMode: $wire.typeSignature || false,
                typedName: '',
                typedDate: '',
                manualDate: '',

                formatDate() {
                    return new Date().toLocaleString('en-US', {
                        month: 'long',
                        day: '2-digit',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                        timeZoneName: 'short'
                    });
                },

                sizeCanvas(canvas) {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const width = canvas.parentElement.clientWidth;
                    canvas.width = width * ratio;
                    canvas.height = CANVAS_HEIGHT * ratio;
                    canvas.getContext('2d').scale(ratio, ratio);
                    canvas.style.width = width + 'px';
                    canvas.style.height = CANVAS_HEIGHT + 'px';
                    if (pad) pad.clear();
                },

                init() {
                    $wire.$watch('nameVerified', (verified) => {
                        if (verified && !pad) {
                            this.$nextTick(() => {
                                const canvas = this.$refs.signatureCanvas;
                                if (canvas) {
                                    this.sizeCanvas(canvas);
                                    pad = new SignaturePad(canvas, {
                                        backgroundColor: 'rgba(255, 255, 255, 0)',
                                        penColor: 'rgb(0, 0, 0)',
                                        minWidth: 1,
                                        maxWidth: 2.5,
                                    });
                                    pad.addEventListener('endStroke', () => {
                                        this.manualDate = pad.isEmpty() ? '' : this.formatDate();
                                    });
                                    const resizeObserver = new ResizeObserver(() => this.sizeCanvas(canvas));
                                    resizeObserver.observe(canvas.parentElement);
                                }
                            });
                        }
                    });

                    $wire.$watch('signerName', (name) => {
                        this.typedName = name;
                        this.typedDate = name ? this.formatDate() : '';
                    });
                },

                toggleMode() {
                    this.isTypeMode = !this.isTypeMode;
                    if (this.isTypeMode) {
                        if (pad) pad.clear();
                        this.manualDate = '';
                        const name = $wire.signerName || '';
                        this.typedName = name;
                        this.typedDate = name ? this.formatDate() : '';
                    } else {
                        this.typedName = '';
                        this.typedDate = '';
                    }
                    $wire.set('signatureData', '');
                },

                clearSignature() {
                    if (pad) pad.clear();
                    this.manualDate = '';
                    $wire.set('signatureData', '');
                },

                captureAndSign() {
                    if (this.isTypeMode) {
                        const canvas = this.$refs.signatureCanvas;
                        const ctx = canvas.getContext('2d');
                        const ratio = Math.max(window.devicePixelRatio || 1, 1);

                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        ctx.save();
                        ctx.scale(ratio, ratio);

                        const nameFont = '48px "Brush Script MT", "Segoe Script", cursive';
                        const padding = 16;

                        ctx.font = nameFont;
                        const nameMetrics = ctx.measureText(this.typedName);
                        const nameWidth = nameMetrics.width;

                        ctx.restore();

                        const cropW = Math.ceil(nameWidth + padding * 2);
                        const cropH = Math.ceil(48 + padding * 2);
                        const cropCanvas = document.createElement('canvas');
                        cropCanvas.width = cropW * ratio;
                        cropCanvas.height = cropH * ratio;
                        const cropCtx = cropCanvas.getContext('2d');
                        cropCtx.scale(ratio, ratio);

                        cropCtx.font = nameFont;
                        cropCtx.fillStyle = '#333';
                        cropCtx.fillText(this.typedName, padding, padding + 42);

                        $wire.set('signatureData', cropCanvas.toDataURL('image/png'));
                    } else {
                        if (pad && !pad.isEmpty()) {
                            $wire.set('signatureData', pad.toDataURL('image/png'));
                        }
                    }

                    $nextTick(() => $wire.sign());
                },
            };
        });
    </script>
    @endscript
</div>
