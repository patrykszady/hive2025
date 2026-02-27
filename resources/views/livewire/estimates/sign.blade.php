<div class="min-h-screen bg-zinc-100 dark:bg-zinc-900" x-init="window.scrollTo(0, 0)">
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
                <flux:heading size="lg">Estimate Not Available</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                    This estimate is invalid or has not been finalized yet.
                </flux:text>
            </flux:card>

        @elseif($step === 'not-authorized')
            {{-- Logged in but not a signer --}}
            <flux:card class="max-w-md mx-auto text-center">
                <div class="w-14 h-14 mx-auto mb-4 bg-amber-100 dark:bg-amber-900/30 rounded-full flex items-center justify-center">
                    <flux:icon.exclamation-triangle class="size-7 text-amber-600 dark:text-amber-400" />
                </div>
                <flux:heading size="lg">Cannot Sign This Estimate</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                    Your account is not listed as a signer on this estimate. Please log in with a different account if you believe this is a mistake.
                </flux:text>
                <div class="mt-5">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:button type="submit" variant="primary">
                            Log Out
                        </flux:button>
                    </form>
                </div>
            </flux:card>

        @elseif($step === 'vendor-must-sign')
            {{-- Client user, but vendor hasn't signed yet --}}
            <flux:card class="max-w-md mx-auto text-center">
                <div class="w-14 h-14 mx-auto mb-4 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center">
                    <flux:icon.clock class="size-7 text-blue-600 dark:text-blue-400" />
                </div>
                <flux:heading size="lg">Waiting for Contractor</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                    {{ $estimate->vendor?->short_name ?? 'Your contractor' }} hasn't signed this estimate yet.
                    You'll receive an email once it's ready for your signature.
                </flux:text>
            </flux:card>

        @elseif($step === 'sign')
            {{-- PDF + Signature Pad --}}
            @if($pdfUrl)
                <div class="w-full rounded-lg overflow-hidden border border-zinc-300 dark:border-zinc-600 bg-white" style="height: 80vh;">
                    <iframe
                        src="{{ $pdfUrl }}"
                        class="w-full h-full"
                        style="border: none;"
                    ></iframe>
                </div>
            @endif

            <flux:card class="space-y-4" x-data="signaturePad()">
                <div>
                    <flux:heading size="lg">Signature</flux:heading>
                    <flux:badge color="{{ $isVendorSigner ? 'indigo' : 'blue' }}" class="mt-2">
                        Signing as {{ $matchedUserName }}
                        @if($isVendorSigner)
                            <span class="ml-1 opacity-75">(Contractor)</span>
                        @endif
                    </flux:badge>
                </div>

                <flux:text>
                    I, the {{ $isVendorSigner ? 'Contractor' : 'Applicant' }}, certify that I have the proper authority to sign this Contract, and that all information provided is complete and accurate to the best of my knowledge.
                </flux:text>

                {{-- Signer Name Input --}}
                <div class="space-y-1">
                    <flux:field>
                        <flux:label>Name</flux:label>
                        <flux:input
                            wire:model.live.debounce.1000ms="signerName"
                            placeholder="Full Name"
                        />
                        <flux:description>Type your name as consent to electronically sign this Contract.</flux:description>
                        <flux:error name="signerName" />
                    </flux:field>
                </div>

                @if($nameVerified)
                {{-- Toggle: Enable Type Signature --}}
                <div class="flex items-center gap-3">
                    <flux:text class="font-semibold">Enable Type Signature</flux:text>
                    <flux:switch
                        wire:model.live="typeSignature"
                        x-on:change="toggleMode()"
                    />
                </div>
                @endif

                {{-- Canvas Signature Area (always in DOM for Alpine, hidden until name verified) --}}
                <div x-show="$wire.nameVerified" x-cloak>
                <div wire:ignore class="relative border border-zinc-300 dark:border-zinc-600 rounded-lg bg-white dark:bg-zinc-800">
                    {{-- Block drawing when in type mode --}}
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
                                {{-- Typed name on the signature line --}}
                                <div x-show="isTypeMode && typedName" class="flex items-baseline justify-between px-1" x-cloak>
                                    <span class="text-zinc-700 dark:text-zinc-300 truncate" x-text="typedName" style="font-family: 'Brush Script MT', 'Segoe Script', cursive; font-size: 24px; line-height: 1;"></span>
                                    <span class="text-[10px] text-zinc-400 dark:text-zinc-500 whitespace-nowrap ml-2" x-text="typedDate"></span>
                                </div>
                                {{-- Date/time on the signature line for manual mode --}}
                                <div x-show="!isTypeMode && manualDate" class="flex justify-end px-1" x-cloak>
                                    <span class="text-[10px] text-zinc-400 dark:text-zinc-500 whitespace-nowrap" x-text="manualDate"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @error('signatureData')
                    <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                @enderror

                {{-- Actions --}}
                <div class="flex items-center justify-between pt-4">
                    <flux:button variant="ghost" x-on:click="clearSignature()">
                        Clear Signature
                    </flux:button>
                    <flux:button variant="primary" wire:loading.attr="disabled" x-on:click="captureAndSign()">
                        <span wire:loading.remove wire:target="sign">Sign Contract</span>
                        <span wire:loading wire:target="sign">Signing...</span>
                    </flux:button>
                </div>
                </div>
            </flux:card>

        @elseif($step === 'done')
            {{-- Done --}}
            <flux:card class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center">
                    <flux:icon.check class="size-8 text-green-600 dark:text-green-400" />
                </div>

                @if($estimate->isFullySigned())
                    <flux:heading size="lg">Contract Fully Signed</flux:heading>
                    <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                        All required signatures have been collected. Thank you!
                    </flux:text>
                @elseif($isVendorSigner)
                    <flux:heading size="lg">Your Signature Has Been Recorded</flux:heading>
                    <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                        Signing invitations have been emailed to the project's client users.
                    </flux:text>
                @else
                    <flux:heading size="lg">Your Signature Has Been Recorded</flux:heading>
                    <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                        Thank you for signing. This contract still requires additional signatures.
                    </flux:text>
                @endif
            </flux:card>

            {{-- Signature Status Table --}}
            <flux:card>
                <flux:heading size="md" class="mb-4">Signature Status</flux:heading>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    {{-- Vendor signature status --}}
                    @php
                        $vendorSig = $this->existingSignatures->first(fn ($s) =>
                            $estimate->vendor?->users?->pluck('id')->contains($s->user_id)
                        );
                    @endphp
                    <div class="flex items-center justify-between py-3">
                        <div>
                            <flux:text class="font-medium">{{ $estimate->vendor?->short_name ?? 'Contractor' }}</flux:text>
                            @if($vendorSig)
                                <flux:text class="text-xs text-zinc-500">
                                    Signed by {{ $vendorSig->signer_name }} on {{ $vendorSig->signed_at?->format('M j, Y \a\t g:i A') }}
                                </flux:text>
                            @endif
                        </div>
                        @if($vendorSig)
                            <flux:badge color="green">Signed</flux:badge>
                        @else
                            <flux:badge color="yellow">Pending</flux:badge>
                        @endif
                    </div>

                    {{-- Client user signature statuses --}}
                    @foreach($this->requiredSigners as $signer)
                        @php
                            $sig = $this->existingSignatures->firstWhere('user_id', $signer->id);
                        @endphp
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <flux:text class="font-medium">{{ trim($signer->first_name . ' ' . $signer->last_name) }}</flux:text>
                                @if($sig)
                                    <flux:text class="text-xs text-zinc-500">
                                        Signed {{ $sig->signed_at?->format('M j, Y \a\t g:i A') }}
                                    </flux:text>
                                @endif
                            </div>
                            @if($sig)
                                <flux:badge color="green">Signed</flux:badge>
                            @else
                                <flux:badge color="yellow">Pending</flux:badge>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif
    </div>

    @persist('toast')
        <flux:toast />
    @endpersist

    @if($valid && $step === 'sign')
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
                        // Defer pad creation until name is verified and canvas is visible
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
                            // Entering type mode — clear manual drawing, populate typed name
                            if (pad) pad.clear();
                            this.manualDate = '';
                            const name = $wire.signerName || '';
                            this.typedName = name;
                            this.typedDate = name ? this.formatDate() : '';
                        } else {
                            // Leaving type mode — clear typed overlay
                            this.typedName = '';
                            this.typedDate = '';
                        }
                        $wire.set('signatureData', '');
                    },

                    clearSignature() {
                        if (pad) pad.clear();
                        this.manualDate = '';
                        $wire.set('signatureData', '');
                        $wire.set('signatureData', '')
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
