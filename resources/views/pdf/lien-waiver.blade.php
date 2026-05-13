<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $waiver->typeLabel() }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap');

        @page { size: Letter; margin: 0.6in; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11pt; color: #111; line-height: 1.45; }
        h1 { font-size: 14pt; text-align: center; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.05em; }
        h2 { font-size: 11pt; text-transform: uppercase; letter-spacing: 0.04em; margin: 18px 0 6px; border-bottom: 1px solid #888; padding-bottom: 2px; }
        .subtitle { text-align: center; font-size: 10pt; color: #555; margin-bottom: 18px; }
        .meta { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
        .meta td { padding: 4px 6px; vertical-align: top; font-size: 10pt; border: 1px solid #ddd; }
        .meta td.label { background: #f5f5f5; width: 28%; font-weight: 600; }
        .legal { text-align: justify; }
        .legal p { margin: 8px 0; }
        .signature-block { margin-top: 28px; display: flex; gap: 30px; }
        .signature-col { flex: 1; }
        .signature-line { border-top: 1px solid #333; margin-top: 60px; padding-top: 4px; font-size: 9pt; color: #555; }
        .signature-img { max-height: 60px; max-width: 220px; }
        .audit { margin-top: 18px; font-size: 8pt; color: #777; }
        .watermark { position: fixed; top: 50%; left: 0; right: 0; text-align: center;
            font-size: 132pt; color: rgba(220, 38, 38, 0.11); transform: translateY(-50%) rotate(-48deg);
            font-weight: 800; letter-spacing: 0.16em; pointer-events: none; }
        .draft-header, .draft-footer {
            position: fixed;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10pt;
            color: #b91c1c;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            z-index: 20;
        }
        .draft-header { top: 0.08in; }
        .draft-footer { bottom: 0.08in; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9pt;
            background: #eef; color: #225; font-weight: 600; }
        .totals { margin-top: 8px; font-size: 12pt; }
        .totals strong { font-size: 13pt; }
        .typed-signature {
            font-family: 'Dancing Script', 'Brush Script MT', 'Segoe Script', 'Lucida Handwriting', cursive;
            font-size: 38px;
            font-weight: 700;
            color: #333;
            white-space: nowrap;
            letter-spacing: 0.01em;
        }
        .continued-note {
            margin-top: 16px;
            text-align: center;
            font-size: 10pt;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #444;
        }
        .claimant-page-break {
            page-break-before: always;
            break-before: page;
        }
        .draft-signature-warning {
            margin: 0 0 8px;
            color: #b91c1c;
            font-size: 9pt;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .draft-signature-zone {
            position: relative;
        }
        .draft-signature-zone::before {
            content: 'DRAFT - DO NOT SIGN';
            position: absolute;
            left: 10%;
            right: 10%;
            top: calc(50% - 12px);
            text-align: center;
            color: rgba(185, 28, 28, 0.9);
            font-size: 10pt;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            transform: rotate(-11deg);
            pointer-events: none;
        }
        .draft-signature-zone::after {
            content: '';
            position: absolute;
            left: 4%;
            right: 4%;
            top: 50%;
            border-top: 3px solid rgba(220, 38, 38, 0.75);
            transform: rotate(-11deg);
            pointer-events: none;
        }
    </style>
</head>
<body>
    @if($isDraft)
        <div class="watermark">DRAFT</div>
    @endif

    @php
        $jurisdictionCode = strtoupper((string) $waiver->jurisdiction);
        $isIllinois = in_array($jurisdictionCode, ['IL', 'US-IL'], true);
        $resolvedAmount = $waiver->amount;

        if ($resolvedAmount === null && isset($payment) && $payment?->amount !== null) {
            $resolvedAmount = $payment->amount;
        }

        $displayAmount = $resolvedAmount !== null
            ? '$' . number_format((float) $resolvedAmount, 2)
            : '—';
    @endphp

    @if($isIllinois)
        <h1>
            @if($waiver->type?->isFinal())
                FINAL WAIVER OF LIEN
            @else
                WAIVER OF LIEN TO DATE
            @endif
        </h1>
        <p class="subtitle">
            STATE OF ILLINOIS
            &nbsp;·&nbsp;
            770 ILCS 60/
            &nbsp;·&nbsp;
            <span class="badge">{{ $waiver->type?->shortLabel() }}</span>
        </p>
    @else
        <h1>{{ $waiver->typeLabel() }}</h1>
        <p class="subtitle">
            Jurisdiction: {{ $waiver->jurisdiction }}
            &nbsp;·&nbsp;
            <span class="badge">{{ $waiver->type?->shortLabel() }}</span>
        </p>
    @endif

    <table class="meta">
        <tr>
            <td class="label">Project / Job</td>
            <td>
                <strong>{{ $project->project_name ?? '—' }}</strong><br>
                @php
                    $estimateNumbers = $project->estimates->pluck('number')->filter()->values();
                @endphp
                @if($estimateNumbers->isNotEmpty())
                    <span style="font-size: 9pt; color: #555;">Estimate #{{ $estimateNumbers->implode(', #') }}</span><br>
                @endif
                {{ $project->address ?? '' }}
                @if($project->address_2) {{ $project->address_2 }} @endif<br>
                {{ trim(($project->city ?? '') . ', ' . ($project->state ?? '') . ' ' . ($project->zip_code ?? ''), ', ') }}
            </td>
        </tr>
        <tr>
            <td class="label">Owner / Customer</td>
            <td>{{ $project->client?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">{{ !empty($payerOverride) ? 'Property Owner' : 'Contractor (Maker of Payment)' }}</td>
            <td>
                @if($payerVendor)
                    <strong>{{ $payerVendor->business_name }}</strong><br>
                    {{ $payerVendor->address ?? '' }}<br>
                    {{ trim(($payerVendor->city ?? '') . ', ' . ($payerVendor->state ?? '') . ' ' . ($payerVendor->zip_code ?? ''), ', ') }}
                @elseif(!empty($payerOverride))
                    <strong>{{ $payerOverride['name'] }}</strong><br>
                    {{ $payerOverride['address'] ?? '' }}<br>
                    {{ $payerOverride['city_state_zip'] ?? '' }}
                @else
                    —
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Claimant<br><span style="font-weight: normal; font-size: 9pt; color: #555;">(Vendor Receiving Payment)</span></td>
            <td>
                <strong>{{ $vendor->business_name }}</strong><br>
                {{ $vendor->address ?? '' }}<br>
                {{ trim(($vendor->city ?? '') . ', ' . ($vendor->state ?? '') . ' ' . ($vendor->zip_code ?? ''), ', ') }}
            </td>
        </tr>
        <tr>
            <td class="label">Through Date</td>
            <td>{{ optional($waiver->through_date)->format('F j, Y') }}</td>
        </tr>
        @if($check)
            <tr>
                <td class="label">Payment Reference</td>
                <td>
                    {{ $check->check_type }}
                    @if($check->check_number) #{{ $check->check_number }} @endif
                    &nbsp;·&nbsp; dated {{ optional($check->date)->format('F j, Y') }}
                </td>
            </tr>
        @endif
        <tr>
            <td class="label">Amount of Payment</td>
            <td class="totals"><strong>{{ $waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal ? 'PAID IN FULL' : $displayAmount }}</strong></td>
        </tr>
        @if((float) $waiver->exceptions_amount > 0)
            <tr>
                <td class="label">Exceptions / Disputed Amount</td>
                <td>${{ number_format((float) $waiver->exceptions_amount, 2) }}</td>
            </tr>
        @endif
    </table>

    <h2>Notice &amp; Waiver</h2>
    <div class="legal">
        @if($isIllinois)
            @php
                $payerName = $payerVendor?->business_name ?? ($payerOverride['name'] ?? 'the Owner / General Contractor');
                $ownerName = $project->client?->name ?? 'the Owner';
                $projectAddress = trim(($project->address ?? '') . ', ' . ($project->city ?? '') . ', ' . ($project->state ?? '') . ' ' . ($project->zip_code ?? ''), ', ');
                $amountFormatted = $waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal
                    ? 'PAID IN FULL'
                    : $displayAmount;
                $workDescription = $waiver->type?->isFinal()
                    ? 'all labor, services, material, fixtures, apparatus, machinery, and extras'
                    : 'labor, services, material, fixtures, apparatus, and machinery';
            @endphp

            <p>
                <strong>STATE OF ILLINOIS</strong> &nbsp;)&nbsp;<br>
                <strong>COUNTY OF {{ !empty($projectCounty ?? null) ? strtoupper($projectCounty) : '__________________' }}</strong> &nbsp;)&nbsp;SS
            </p>

            <p>
                <strong>TO WHOM IT MAY CONCERN:</strong> Whereas the undersigned has been employed by
                <strong>{{ $payerName }}</strong> to furnish <strong>{{ $workDescription }}</strong> for the
                premises known as <strong>{{ $projectAddress ?: '—' }}</strong>, of which
                <strong>{{ $ownerName }}</strong> is the owner.
            </p>

            <p>
                Now, therefore, for and in consideration of the sum of <strong>{{ $amountFormatted }}</strong>
                @if($waiver->type?->isConditional())
                    (upon actual receipt thereof)
                @else
                    and other good and valuable consideration, the receipt whereof is hereby acknowledged,
                @endif
                the undersigned does hereby waive and release any and all lien or claim of, or right to,
                lien under the statutes of the State of Illinois relating to mechanics' liens, on the
                above-described premises and improvements thereon, and on the monies or other consideration
                due or to become due from the owner,
                @if($waiver->type?->isFinal())
                    on account of the contract, including approved extras, through final completion and
                    final payment.
                @else
                    on account of labor, services, material, fixtures, apparatus, or machinery furnished
                    to and including <strong>{{ optional($waiver->through_date)->format('F j, Y') }}</strong>,
                    <em>excluding</em> retainage, disputed amounts, and approved extras not yet invoiced.
                @endif
            </p>

            @if($waiver->type?->isConditional())
                <p>
                    <strong>CONDITION:</strong> This is a conditional waiver and release. It becomes
                    effective only upon actual receipt of funds in the amount stated above.
                </p>
            @endif

            <p>
                The undersigned further certifies that all lower-tier lien waivers required for this
                payment have been obtained to the extent of this payment request and that there is no
                known claim, legal or equitable, that would defeat this waiver to the stated extent.
            </p>

            @php
                $notesPayload = json_decode((string) $waiver->notes, true);
                $humanNote = is_array($notesPayload) ? ($notesPayload['note'] ?? null) : (string) $waiver->notes;
                $humanNote = is_string($humanNote) ? trim($humanNote) : null;
            @endphp
            @if(!empty($humanNote))
                <p><strong>Additional Notes:</strong> {{ $humanNote }}</p>
            @endif
        @else
            @if($waiver->type?->isConditional())
                <p>
                    <strong>NOTICE:</strong> THIS DOCUMENT WAIVES THE CLAIMANT'S LIEN, STOP PAYMENT NOTICE,
                    AND PAYMENT BOND RIGHTS EFFECTIVE ON RECEIPT OF PAYMENT. A PERSON SHOULD NOT RELY ON
                    THIS DOCUMENT UNLESS SATISFIED THAT THE CLAIMANT HAS RECEIVED PAYMENT.
                </p>
            @else
                <p>
                    <strong>NOTICE:</strong> THIS DOCUMENT WAIVES RIGHTS UNCONDITIONALLY AND STATES THAT
                    YOU HAVE BEEN PAID FOR GIVING UP THOSE RIGHTS. IF YOU HAVE NOT BEEN PAID, USE A
                    CONDITIONAL RELEASE FORM.
                </p>
            @endif

            <p>
                The undersigned claimant has been paid (or upon receipt of the payment identified above will have
                been paid) and, subject to the conditions stated herein,
                @if($waiver->type?->isFinal())
                    hereby waives and releases <strong>any and all</strong> mechanic's lien, stop payment notice,
                    or bond right that the claimant has on the property described above for labor, services, equipment,
                    or material furnished to the project through the date set forth above, including final payment.
                @else
                    hereby waives and releases any mechanic's lien, stop payment notice, or bond right that the
                    claimant has for labor, services, equipment, or material furnished to the project through
                    the date set forth above, <em>excluding</em> the disputed amount, retentions, and unbilled extras.
                @endif
            </p>

            @if($waiver->type?->isConditional())
                <p>
                    This waiver and release is effective only on the claimant's actual receipt of payment of the
                    sum identified above. If the claimant has previously given any waiver in exchange for the
                    payment described, that waiver is not affected by this document.
                </p>
            @endif

            @php
                $notesPayload = json_decode((string) $waiver->notes, true);
                $humanNote = is_array($notesPayload) ? ($notesPayload['note'] ?? null) : (string) $waiver->notes;
                $humanNote = is_string($humanNote) ? trim($humanNote) : null;
            @endphp
            @if(!empty($humanNote))
                <p><strong>Additional Notes:</strong> {{ $humanNote }}</p>
            @endif
        @endif
    </div>

    <div class="continued-note">CONTINUTED ON NEXT PAGE</div>

    <div class="claimant-page-break" style="margin-top: 0;">
        <strong>Claimant:</strong> {{ $vendor->business_name }}
    </div>

    @if($signatures->isNotEmpty())
        <div style="margin-top: 28px; border-top: 2px solid #e5e7eb; padding-top: 20px;" class="{{ $isDraft ? 'draft-signature-zone' : '' }}">
            <div style="font-weight: bold; font-size: 13pt; margin-bottom: 14px;">SIGNATURES</div>
            <div style="display: flex; flex-wrap: wrap; gap: 32px;">
                @foreach($signatures as $sig)
                    <div style="width: 320px;">
                        <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 8px; background: #fff; width: 304px; height: 130px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                            @if($sig->signature_type === 'type')
                                <span class="typed-signature">{{ $sig->signer_name }}</span>
                            @else
                                <img src="{{ $sig->signature_data }}" style="max-width: 100%; max-height: 100%; object-fit: contain;" alt="signature" />
                            @endif
                        </div>
                        <div style="margin-top: 8px; border-top: 2px solid #333; padding-top: 4px;">
                            <span style="font-weight: 600;">{{ $sig->signer_name }}</span>
                            @if($sig->signer_title)
                                <span style="font-weight: 400; color: #6b7280; margin-left: 8px;">{{ $sig->signer_title }}</span>
                            @endif
                        </div>
                        <div style="font-size: 9pt; color: #6b7280; margin-top: 4px;">
                            Signed electronically on {{ optional($sig->signed_at)->format('m/d/Y g:i A') }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="signature-block {{ $isDraft ? 'draft-signature-zone' : '' }}">
            <div class="signature-col">
                <div class="signature-line">Signature &amp; Title</div>
                <div class="signature-line">Printed Name</div>
                <div class="signature-line">Date</div>
            </div>
        </div>
    @endif

    @if($signatures->isNotEmpty())
        <div class="audit">
            <strong>Audit Trail:</strong><br>
            Document hash: {{ $waiver->document_hash }}<br>
            @foreach($signatures as $sig)
                Signed by {{ $sig->signer_name }}
                ({{ $sig->signer_email ?? 'no email' }})
                from IP {{ $sig->ip_address }}
                at {{ optional($sig->signed_at)->format('Y-m-d H:i:s T') }}<br>
            @endforeach
        </div>
    @endif

    @if($isIllinois)
        <div style="margin-top: 28px; border: 1px solid #888; padding: 10px 12px; font-size: 10pt;" class="{{ $isDraft ? 'draft-signature-zone' : '' }}">
            <strong>JURAT (Notary Acknowledgment)</strong><br><br>
            Subscribed and sworn to before me this ______ day of ______________________, 20____.<br><br>
            <div style="margin-top: 36px;">
                _____________________________________________<br>
                Notary Public &nbsp;·&nbsp; My commission expires: __________________<br>
                <em>(Seal)</em>
            </div>
        </div>
    @endif
</body>
</html>
