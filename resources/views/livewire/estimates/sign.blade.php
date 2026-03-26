<div x-init="window.scrollTo(0, 0)">
    {{-- Detail Card Header --}}
    @php
        $project = $estimate->project;
        $client = $project?->client;
        $vendor = $estimate->vendor;
        $estimateTotal = $estimate->estimate_sections->sum('total');
    @endphp
    <flux:card class="max-w-3xl mb-6">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0 flex-1 space-y-2">
                {{-- Project Address --}}
                @if($project?->short_address)
                    <flux:heading size="lg">{{ $project->short_address }}</flux:heading>
                @endif

                {{-- Project Name --}}
                @if($project?->project_name)
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $project->project_name }}</flux:text>
                @endif

                {{-- Detail rows --}}
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                    {{-- Client --}}
                    @if($client?->name)
                        <div class="flex items-center gap-1.5">
                            <flux:icon.user class="size-4 text-zinc-400" />
                            <span>{{ $client->name }}</span>
                        </div>
                    @endif

                    {{-- Estimate Number --}}
                    <div class="flex items-center gap-1.5">
                        <flux:icon.document-text class="size-4 text-zinc-400" />
                        @if($isVendorSigner)
                            <a href="{{ route('estimates.show', $estimate) }}" class="text-blue-600 dark:text-blue-400 hover:underline" target="_blank">
                                Estimate #{{ $estimate->number }}
                            </a>
                        @else
                            <span>Estimate #{{ $estimate->number }}</span>
                        @endif
                    </div>

                    {{-- Total --}}
                    @if($estimateTotal)
                        <div class="flex items-center gap-1.5">
                            <flux:icon.currency-dollar class="size-4 text-zinc-400" />
                            <span>{{ money($estimateTotal) }}</span>
                        </div>
                    @endif

                    {{-- Duration --}}
                    @if($estimate->start_date || $estimate->end_date)
                        <div class="flex items-center gap-1.5">
                            <flux:icon.calendar class="size-4 text-zinc-400" />
                            <span>
                                {{ $estimate->start_date?->format('M j, Y') ?? '—' }}
                                –
                                {{ $estimate->end_date?->format('M j, Y') ?? '—' }}
                            </span>
                        </div>
                    @endif
                </div>

                {{-- Vendor --}}
                <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">{{ $vendor?->business_name }}</flux:text>
            </div>

            {{-- Preview / Export PDF --}}
            <div class="shrink-0">
                <flux:button variant="ghost" size="sm" icon="arrow-down-tray" href="{{ route('estimate.sign.pdf', $estimate) }}" target="_blank" title="Preview / Download PDF">
                    PDF
                </flux:button>
            </div>
        </div>
    </flux:card>

    <div class="max-w-3xl space-y-4">
        @if($step === 'vendor-must-sign')
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
            <div x-data="signaturePad()">

            {{-- Estimate Sections --}}
            @foreach($estimate->estimate_sections as $sectionIndex => $section)
                <flux:card>
                    <div class="flex justify-between mb-2">
                        <flux:heading size="lg" class="font-extrabold">{{ $section->name }}</flux:heading>
                    </div>

                    <table class="min-w-full">
                        <thead class="border-b border-zinc-300 dark:border-zinc-600">
                            <tr>
                                <th class="hidden sm:table-cell px-3 py-3 text-right text-sm font-semibold text-zinc-900 dark:text-zinc-100"></th>
                                <th class="py-3 pl-4 pr-3 text-left text-sm font-semibold text-zinc-900 dark:text-zinc-100 sm:pl-6 sm:w-1/2">Item</th>
                                <th class="hidden sm:table-cell px-3 py-3 text-right text-sm font-semibold text-zinc-900 dark:text-zinc-100">Qty</th>
                                <th class="hidden sm:table-cell px-3 py-3 text-right text-sm font-semibold text-zinc-900 dark:text-zinc-100">Unit</th>
                                <th class="hidden sm:table-cell px-3 py-3 text-right text-sm font-semibold text-zinc-900 dark:text-zinc-100">Cost</th>
                                <th class="py-3 pl-3 pr-4 text-right text-sm font-semibold text-zinc-900 dark:text-zinc-100 sm:pr-6">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($section->estimate_line_items->sortBy('order') as $itemIndex => $item)
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <td class="hidden sm:table-cell px-3 py-4 text-right text-zinc-500 align-top text-sm">{{ $sectionIndex + 1 }}.{{ $itemIndex + 1 }}</td>
                                    <td class="pl-4 pr-3 py-4 sm:pl-6">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $item->name }}</div>
                                        <div class="text-xs font-bold text-indigo-700 dark:text-indigo-400">{{ $item->category }}/{{ $item->sub_category }}</div>
                                    </td>
                                    <td class="hidden sm:table-cell px-3 py-4 text-right text-zinc-500 align-top text-sm">{{ $item->unit_type !== 'no_unit' ? $item->quantity : '' }}</td>
                                    <td class="hidden sm:table-cell px-3 py-4 text-right text-zinc-500 align-top text-sm">{{ $item->unit_type !== 'no_unit' ? $item->unit_type : '' }}</td>
                                    <td class="hidden sm:table-cell px-3 py-4 text-right text-zinc-500 align-top text-sm">{{ $item->unit_type !== 'no_unit' ? money($item->cost) : '' }}</td>
                                    <td class="py-4 pl-3 pr-4 text-right text-zinc-800 dark:text-zinc-200 align-top text-sm font-medium sm:pr-6">{{ money($item->total) }}</td>
                                </tr>
                                @if($item->desc)
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <td class="hidden sm:table-cell"></td>
                                        <td class="pb-4 pl-4 pr-3 sm:pl-6 text-sm" colspan="5">
                                            <span class="text-zinc-700 dark:text-zinc-300" style="white-space: pre-line;">{{ $item->desc }}</span>
                                            @if($item->notes)
                                                <hr class="my-1 border-zinc-200 dark:border-zinc-600">
                                                <span class="text-zinc-500 italic" style="white-space: pre-line;">{{ $item->notes }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>

                    <div class="flex justify-end pt-3 mt-2 border-t border-zinc-200 dark:border-zinc-700">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Section Total: {{ money($section->total) }}</span>
                    </div>

                    @if($loop->last)
                        <div class="flex justify-end pt-4 mt-2 border-t-2 border-zinc-300 dark:border-zinc-600">
                            <flux:heading size="lg">Total: {{ money($estimate->estimate_sections->sum('total')) }}</flux:heading>
                        </div>
                    @endif
                </flux:card>
            @endforeach

            {{-- Contract Content --}}
            @if($contractHtml)
                <flux:card class="prose prose-sm max-w-none dark:prose-invert">
                    {!! $contractHtml !!}
                </flux:card>
                <div x-ref="contractEnd" class="h-1"></div>
            @endif

            {{-- Scroll prompt --}}
            <div x-show="!hasScrolledContract" x-cloak>
                <flux:card class="text-center">
                    <flux:icon.arrow-down class="size-6 mx-auto mb-2 text-zinc-400 animate-bounce" />
                    <flux:text class="text-zinc-500">Please scroll through the entire contract before signing.</flux:text>
                </flux:card>
            </div>

            <flux:card class="space-y-4" x-show="hasScrolledContract" x-transition x-cloak>
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
                    @if($isVendorSigner)
                        I, the Contractor, certify that I have the proper authority to sign this Contract, and that all information provided is complete and accurate to the best of my knowledge.
                    @else
                        I, the Applicant, acknowledge that I have reviewed this Contract in its entirety and agree to the terms, conditions, and payment schedule outlined within.
                    @endif
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
            </div>

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
                    @if($estimate->signed_contract_path)
                        <div class="mt-4">
                            <flux:button variant="primary" icon="arrow-down-tray" wire:click="downloadSignedContract">
                                Download Signed Contract
                            </flux:button>
                        </div>
                    @endif
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
                    {{-- Vendor signature statuses --}}
                    @php
                        $vendorTz = $estimate->vendor?->timezone ?: config('app.timezone');
                        $requiredVendorIds = $estimate->required_vendor_signer_ids;
                        // Show explicitly required signers, or just those who have already signed
                        $vendorSignerIds = $requiredVendorIds->isNotEmpty()
                            ? $requiredVendorIds
                            : $this->existingSignatures
                                ->filter(fn ($s) => $estimate->vendor?->users?->pluck('id')->contains($s->user_id))
                                ->pluck('user_id');
                    @endphp
                    @foreach($vendorSignerIds as $vendorSignerId)
                        @php
                            $vendorSignerUser = $estimate->vendor?->users?->firstWhere('id', $vendorSignerId);
                            $vendorSig = $this->existingSignatures->firstWhere('user_id', $vendorSignerId);
                        @endphp
                        @if($vendorSignerUser)
                            <div class="flex items-center justify-between py-3">
                                <div>
                                    <flux:text class="font-medium">{{ trim($vendorSignerUser->first_name . ' ' . $vendorSignerUser->last_name) }}</flux:text>
                                    <flux:text class="text-xs text-zinc-500">{{ $estimate->vendor?->short_name ?? 'Contractor' }}</flux:text>
                                    @if($vendorSig)
                                        <flux:text class="text-xs text-zinc-500">
                                            Signed {{ $vendorSig->signed_at?->setTimezone($vendorTz)->format('M j, Y \a\t g:i A') }}
                                        </flux:text>
                                    @endif
                                </div>
                                @if($vendorSig)
                                    <flux:badge color="green">Signed</flux:badge>
                                @else
                                    <flux:badge color="yellow">Pending</flux:badge>
                                @endif
                            </div>
                        @endif
                    @endforeach

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
                                        Signed {{ $sig->signed_at?->setTimezone($vendorTz)->format('M j, Y \a\t g:i A') }}
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

            {{-- Signing Email Tracking (vendor signers only) --}}
            @if($isVendorSigner && $estimate->isVendorSigned() && !$estimate->isFullySigned())
                <flux:card>
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="md">Signing Email Tracking</flux:heading>
                        @if($this->canResendSigningEmail)
                            <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="resendSigningInvites" wire:loading.attr="disabled" wire:target="resendSigningInvites">
                                Resend Signature Email
                            </flux:button>
                        @endif
                    </div>

                    @if($this->signingEmailEvents->isNotEmpty())
                        @php
                            // Group consecutive events of the same type + recipient
                            $grouped = collect();
                            foreach ($this->signingEmailEvents as $event) {
                                $key = $event->event_type . '|' . json_encode($event->recipient_emails);
                                $last = $grouped->last();
                                if ($last && $last['key'] === $key) {
                                    $last['count']++;
                                    $last['latest_at'] = $last['latest_at'] ?? $event->event_at;
                                    $grouped->pop();
                                    $grouped->push($last);
                                } else {
                                    $grouped->push([
                                        'key' => $key,
                                        'event' => $event,
                                        'count' => 1,
                                        'latest_at' => $event->event_at,
                                    ]);
                                }
                            }
                        @endphp
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach($grouped as $group)
                                <div class="flex items-center justify-between py-2.5">
                                    <div class="flex items-center gap-3">
                                        <flux:badge
                                            size="sm"
                                            :color="match($group['event']->event_type) {
                                                'sent' => 'zinc',
                                                'delivered' => 'sky',
                                                'opened' => 'blue',
                                                'clicked' => 'green',
                                                'bounced' => 'red',
                                                default => 'zinc',
                                            }">
                                            {{ ucfirst($group['event']->event_type) }}@if($group['count'] > 1) &times;{{ $group['count'] }}@endif
                                        </flux:badge>
                                        <flux:text class="text-sm">
                                            @if(is_array($group['event']->recipient_emails))
                                                {{ implode(', ', $group['event']->recipient_emails) }}
                                            @endif
                                        </flux:text>
                                    </div>
                                    <flux:text class="text-xs text-zinc-500 shrink-0 ml-4">
                                        {{ $group['latest_at']?->diffForHumans() }}
                                    </flux:text>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <flux:text class="text-sm text-zinc-500">No email tracking events recorded yet.</flux:text>
                    @endif
                </flux:card>
            @endif
        @endif
    </div>

    <flux:toast />

    @if($step === 'sign')
        <script src="{{ asset('js/signature_pad.umd.min.js') }}"></script>

        @script
        <script>
            Alpine.data('signaturePad', () => {
                let pad = null;
                const CANVAS_HEIGHT = 150;

                return {
                    hasScrolledContract: false,
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

                    checkContractScroll() {
                        // No longer used — using IntersectionObserver instead
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
                        // Watch for user scrolling past the contract end sentinel
                        this.$nextTick(() => {
                            const sentinel = this.$refs.contractEnd;
                            if (sentinel) {
                                const observer = new IntersectionObserver((entries) => {
                                    if (entries[0].isIntersecting) {
                                        this.hasScrolledContract = true;
                                        observer.disconnect();
                                    }
                                }, { threshold: 1.0 });
                                observer.observe(sentinel);
                            } else {
                                // No contract, allow signing immediately
                                this.hasScrolledContract = true;
                            }
                        });

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

                            // Measure text to create a tightly-cropped image
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            ctx.save();
                            ctx.scale(ratio, ratio);

                            const nameFont = '48px "Brush Script MT", "Segoe Script", cursive';
                            const padding = 16;

                            ctx.font = nameFont;
                            const nameMetrics = ctx.measureText(this.typedName);
                            const nameWidth = nameMetrics.width;

                            ctx.restore();

                            // Create a right-sized canvas for the typed signature
                            const cropW = Math.ceil(nameWidth + padding * 2);
                            const cropH = Math.ceil(48 + padding * 2); // name font + padding
                            const cropCanvas = document.createElement('canvas');
                            cropCanvas.width = cropW * ratio;
                            cropCanvas.height = cropH * ratio;
                            const cropCtx = cropCanvas.getContext('2d');
                            cropCtx.scale(ratio, ratio);

                            cropCtx.font = nameFont;
                            cropCtx.fillStyle = '#333';
                            cropCtx.fillText(this.typedName, padding, padding + 42); // baseline ~42px for 48px font

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
    @endif
</div>
