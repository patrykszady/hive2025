<div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
    {{-- Header --}}
    <div class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-4 py-6">
        <div class="max-w-5xl mx-auto text-center">
            <flux:heading size="xl">Contract Signature</flux:heading>
            @if($valid && $estimate)
                <flux:subheading class="mt-1">
                    {{ $estimate->vendor?->business_name }} — Estimate #{{ $estimate->number }}
                </flux:subheading>
            @endif
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 py-6 space-y-6">
        @if(! $valid)
            {{-- Invalid / Not Finalized --}}
            <flux:card class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-zinc-100 dark:bg-zinc-700 rounded-full flex items-center justify-center">
                    <flux:icon.exclamation-triangle class="size-8 text-zinc-400" />
                </div>
                <flux:heading size="lg">Link Not Valid</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                    This signing link is invalid or the estimate has not been finalized yet.
                </flux:text>
            </flux:card>
        @elseif($alreadySigned)
            {{-- Already Signed --}}
            <flux:card class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center">
                    <flux:icon.check class="size-8 text-green-600 dark:text-green-400" />
                </div>
                <flux:heading size="lg">Contract Signed</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                    This contract has been signed by <strong>{{ $estimate->signature?->signer_name }}</strong>.
                </flux:text>
            </flux:card>
        @else
            {{-- Embedded Estimate/Contract PDF --}}
            @if($pdfUrl)
                <div class="w-full rounded-lg overflow-hidden border border-zinc-300 dark:border-zinc-600 bg-white" style="height: 80vh;">
                    <iframe
                        src="{{ $pdfUrl }}"
                        class="w-full h-full"
                        style="border: none;"
                    ></iframe>
                </div>
            @endif

            {{-- Signature Section --}}
            <flux:card class="space-y-4" x-data="signaturePad()">
                <flux:heading size="lg">SIGNATURE</flux:heading>
                <flux:text>
                    I, the applicant, certify that I have the proper authority to sign this contract, and that all information provided is complete and accurate to the best of my knowledge.
                </flux:text>

                {{-- Signer Name Input --}}
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <flux:text class="text-red-600 font-semibold shrink-0">
                        * Please type your name as consent to electronically sign this contract.
                    </flux:text>
                    <flux:input
                        wire:model="signerName"
                        placeholder="Full Name"
                        class="sm:max-w-xs"
                    />
                </div>
                @error('signerName')
                    <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                @enderror

                {{-- Toggle: Enable Type Signature --}}
                <div class="flex items-center gap-3">
                    <flux:text class="font-semibold">Enable Type Signature</flux:text>
                    <flux:switch
                        wire:model.live="typeSignature"
                        x-on:change="toggleMode()"
                    />
                </div>

                {{-- Canvas Signature Area --}}
                <div class="relative border border-zinc-300 dark:border-zinc-600 rounded-lg bg-white dark:bg-zinc-800">
                    {{-- Typed name & date overlay --}}
                    <div
                        x-show="isTypeMode"
                        class="absolute top-3 left-4 pointer-events-none z-10"
                        x-cloak
                    >
                        <div class="text-zinc-700 dark:text-zinc-300" x-text="typedName" style="font-family: 'Brush Script MT', 'Segoe Script', cursive; font-size: 24px;"></div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400" x-text="typedDate"></div>
                    </div>

                    <canvas
                        x-ref="signatureCanvas"
                        class="w-full rounded-lg"
                        style="height: 150px; touch-action: none; cursor: crosshair;"
                    ></canvas>

                    {{-- X mark and signature line --}}
                    <div class="absolute bottom-4 left-4 right-4 pointer-events-none">
                        <div class="flex items-end gap-2">
                            <span class="text-xl font-bold text-zinc-400 dark:text-zinc-500">X</span>
                            <div class="flex-1 border-b-2 border-zinc-300 dark:border-zinc-600"></div>
                        </div>
                    </div>
                </div>
                @error('signatureData')
                    <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                @enderror

                {{-- Actions --}}
                <div class="flex items-center justify-between">
                    <flux:button variant="ghost" x-on:click="clearSignature()">
                        Clear Signature
                    </flux:button>
                    <flux:button variant="primary" wire:loading.attr="disabled" x-on:click="captureAndSign()">
                        <span wire:loading.remove wire:target="sign">Sign Contract</span>
                        <span wire:loading wire:target="sign">Signing...</span>
                    </flux:button>
                </div>
            </flux:card>
        @endif
    </div>

    @persist('toast')
        <flux:toast />
    @endpersist

    @if($valid && ! $alreadySigned)
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

                    init() {
                        const canvas = this.$refs.signatureCanvas;
                        this.sizeCanvas(canvas);

                        pad = new SignaturePad(canvas, {
                            backgroundColor: 'rgba(255, 255, 255, 0)',
                            penColor: 'rgb(0, 0, 0)',
                            minWidth: 1,
                            maxWidth: 2.5,
                        });

                        this.$watch('isTypeMode', (val) => {
                            val ? pad.off() : pad.on();
                        });

                        $wire.$watch('signerName', (name) => {
                            this.typedName = name;
                            this.typedDate = name ? new Date().toLocaleDateString('en-US', {
                                month: 'long',
                                day: '2-digit',
                                year: 'numeric'
                            }) : '';
                        });

                        if (this.isTypeMode) pad.off();

                        const resizeObserver = new ResizeObserver(() => this.sizeCanvas(canvas));
                        resizeObserver.observe(canvas.parentElement);
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

                    toggleMode() {
                        this.isTypeMode = !this.isTypeMode;
                    },

                    clearSignature() {
                        if (pad) pad.clear();
                        this.typedName = '';
                        this.typedDate = '';
                        $wire.set('signerName', '');
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
                            ctx.font = '24px "Brush Script MT", "Segoe Script", cursive';
                            ctx.fillStyle = '#333';
                            ctx.fillText(this.typedName, 16, 36);
                            ctx.font = '12px sans-serif';
                            ctx.fillStyle = '#666';
                            ctx.fillText(this.typedDate, 16, 54);
                            ctx.restore();

                            $wire.set('signatureData', canvas.toDataURL('image/png'));
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
    @endif
</div>
