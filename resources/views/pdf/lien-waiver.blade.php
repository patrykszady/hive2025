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
        /* Traditional IL form styles */
        .il-form { font-size: 10.5pt; line-height: 1.7; }
        .il-form .fill { display: inline-block; border-bottom: 1px solid #333; padding: 0 6px; min-width: 120px; font-weight: 600; }
        .il-form .fill.wide { min-width: 320px; }
        .il-form .fill.full { display: block; width: 100%; }
        .il-title { font-size: 13pt; font-weight: 700; text-align: center; text-decoration: underline; margin: 14px 0 16px; letter-spacing: 0.04em; }
        .il-small { font-size: 8.5pt; }
        .il-affidavit-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9.5pt; }
        .il-affidavit-table th, .il-affidavit-table td { border: 1px solid #333; padding: 5px 6px; text-align: left; }
        .il-affidavit-table th { background: #f0f0f0; font-size: 8.5pt; text-transform: uppercase; text-align: center; }
        .il-affidavit-table td.num { text-align: right; }
        .il-affidavit-table td.blank { height: 20px; }
        .il-sig-line { border-bottom: 1px solid #333; display: inline-block; min-width: 300px; vertical-align: bottom; }
        .il-page-break { page-break-before: always; break-before: page; }
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

    @php
        // Direct view() renders (tests, previews) may omit the generator-computed
        // context — default them so the template stands alone.
        $amountWords = $amountWords ?? null;
        $affidavit = $affidavit ?? ['contract_total' => 0.0, 'prior_paid' => 0.0, 'this_payment' => (float) ($waiver->amount ?? 0), 'balance_due' => 0.0];
        $payerNameIl = $payerVendor?->business_name ?? ($payerOverride['name'] ?? 'the Owner / General Contractor');
        $ownerNameIl = $project->client?->name ?? ($payerOverride['name'] ?? 'the Owner');
        $projectAddressIl = trim(($project->address ?? '') . ', ' . ($project->city ?? '') . ', ' . ($project->state ?? '') . ' ' . ($project->zip_code ?? ''), ', ');
        $vendorAddressIl = trim(($vendor->address ?? '') . ', ' . ($vendor->city ?? '') . ', ' . ($vendor->state ?? '') . ' ' . ($vendor->zip_code ?? ''), ', ');
        $workFurnishedIl = 'labor, services, material, fixtures, apparatus and machinery';
        $firstSignature = $signatures->first();
        $isPaidInFullIl = $waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal;
    @endphp

    @if($isIllinois)
        {{-- ============ TRADITIONAL ILLINOIS FORM (matches office layout) ============ --}}
        <div class="il-form">
            <p style="margin: 0;">
                STATE OF ILLINOIS<br>
                COUNTY OF <span class="fill">{{ !empty($projectCounty ?? null) ? strtoupper($projectCounty) : '' }}</span>
            </p>

            <div class="il-title">
                @if($waiver->type?->isFinal())
                    FINAL WAIVER OF LIEN
                @else
                    WAIVER OF LIEN TO DATE
                @endif
            </div>

            <p>
                WHEREAS the undersigned has been employed by
                <span class="fill wide">{{ $payerNameIl }}</span><br>
                to furnish <span class="fill wide">{{ $workFurnishedIl }}</span><br>
                for the premises known as <span class="fill wide">{{ $projectAddressIl ?: '' }}</span><br>
                of which <span class="fill wide">{{ $ownerNameIl }}</span> is the owner.
            </p>

            <p>
                THE undersigned, for and in consideration of
                <span class="fill wide">{{ $isPaidInFullIl ? 'payment in full of the contract price' : ($amountWords ?? '') }}</span>
                <span class="il-small">(write out amount)</span><br>
                (<span class="fill">{{ $isPaidInFullIl ? 'PAID IN FULL' : $displayAmount }}</span>) Dollars,
                and other good and valuable considerations, the receipt whereof is hereby acknowledged,
                do(es) hereby waive and release any and all lien or claim of, or right to, lien, under the
                statutes of the State of ILLINOIS, relating to mechanics&rsquo; liens, with respect to and on said
                above-described premises, and the improvements thereon, and on the material, fixtures,
                apparatus or machinery furnished, and on the moneys, funds or other considerations due or to
                become due from the owner, on account of all labor, services, material, fixtures, apparatus
                or machinery,
                @if($waiver->type?->isFinal())
                    heretofore furnished or which may be furnished at any time hereafter,
                @else
                    heretofore furnished to this date
                @endif
                by the undersigned for the above-described premises, INCLUDING EXTRAS.*
            </p>

            @if($waiver->type?->isConditional())
                <p>
                    <strong>CONDITION:</strong> This waiver is conditional and becomes effective only upon
                    the undersigned&rsquo;s actual receipt of the payment stated above.
                </p>
            @endif

            <table style="width: 100%; border-collapse: collapse; margin-top: 18px;">
                <tr>
                    <td style="width: 50%; vertical-align: bottom; padding-right: 16px;">
                        <strong>DATE</strong> <span class="fill">{{ optional($waiver->through_date)->format('m/d/Y') ?? now()->format('m/d/Y') }}</span>
                    </td>
                    <td style="width: 50%; vertical-align: bottom;">
                        <strong>COMPANY NAME</strong> <span class="fill wide">{{ $vendor->business_name }}</span>
                    </td>
                </tr>
                <tr>
                    <td></td>
                    <td style="padding-top: 8px;">
                        <strong>ADDRESS</strong> <span class="fill wide">{{ $vendorAddressIl }}</span>
                    </td>
                </tr>
            </table>

            <div style="margin-top: 22px;" class="{{ $isDraft ? 'draft-signature-zone' : '' }}">
                <strong>SIGNATURE AND TITLE</strong>
                @if($firstSignature)
                    <span class="il-sig-line" style="text-align: center;">
                        @if($firstSignature->signature_type === 'type')
                            <span class="typed-signature" style="font-size: 26px;">{{ $firstSignature->signer_name }}</span>
                        @else
                            <img src="{{ $firstSignature->signature_data }}" style="max-height: 44px; vertical-align: bottom;" alt="signature" />
                        @endif
                        <span style="font-size: 9pt; color: #444;">
                            &nbsp;{{ $firstSignature->signer_name }}@if($firstSignature->signer_title), {{ $firstSignature->signer_title }}@endif
                        </span>
                    </span>
                @else
                    <span class="il-sig-line">&nbsp;</span>
                @endif
            </div>

            <p class="il-small" style="margin-top: 14px;">
                *EXTRAS INCLUDE BUT ARE NOT LIMITED TO CHANGE ORDERS, BOTH ORAL AND WRITTEN, TO THE CONTRACT
            </p>
        </div>

        {{-- ============ CONTRACTOR'S AFFIDAVIT (page 2) ============ --}}
        <div class="il-form il-page-break" style="line-height: 1.55;">
            <p style="margin: 0;">
                STATE OF ILLINOIS<br>
                COUNTY OF <span class="fill">{{ !empty($projectCounty ?? null) ? strtoupper($projectCounty) : '' }}</span>
            </p>

            <div class="il-title">CONTRACTOR&rsquo;S AFFIDAVIT</div>

            <p><strong>TO WHOM IT MAY CONCERN:</strong></p>

            <p>
                THE UNDERSIGNED, (NAME) <span class="fill">{{ $firstSignature?->signer_name ?? '' }}</span>
                BEING DULY SWORN, DEPOSES AND SAYS THAT HE OR SHE IS
                (POSITION) <span class="fill">{{ $firstSignature?->signer_title ?? '' }}</span>
                OF (COMPANY NAME) <span class="fill wide">{{ $vendor->business_name }}</span>
                WHO IS THE CONTRACTOR FURNISHING <span class="fill">general construction</span> WORK ON THE
                BUILDING LOCATED AT <span class="fill wide">{{ $projectAddressIl ?: '' }}</span>
                OWNED BY <span class="fill wide">{{ $ownerNameIl }}</span>
            </p>

            <p>
                That the total amount of the contract including extras* is
                <span class="fill">${{ number_format($affidavit['contract_total'], 2) }}</span>
                on which he or she has received payment of
                <span class="fill">${{ number_format($affidavit['prior_paid'], 2) }}</span>
                prior to this payment. That all waivers are true, correct and genuine and delivered
                unconditionally and that there is no claim either legal or equitable to defeat the validity
                of said waivers. That the following are the names and addresses of all parties who have
                furnished material or labor, or both, for said work and all parties having contracts or
                subcontracts for specific portions of said work or for material entering in the construction
                thereof and the amount due or to become due to each, and that the items mentioned include all
                labor and material required to complete said work according to plans and specifications:
            </p>

            <table class="il-affidavit-table">
                <thead>
                    <tr>
                        <th style="width: 26%;">Names</th>
                        <th style="width: 22%;">What For</th>
                        <th style="width: 13%;">Contract Price</th>
                        <th style="width: 13%;">Amount Paid</th>
                        <th style="width: 13%;">This Payment</th>
                        <th style="width: 13%;">Balance Due</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $vendor->business_name }}</td>
                        <td>General construction — labor &amp; material</td>
                        <td class="num">${{ number_format($affidavit['contract_total'], 2) }}</td>
                        <td class="num">${{ number_format($affidavit['prior_paid'], 2) }}</td>
                        <td class="num">${{ number_format($affidavit['this_payment'], 2) }}</td>
                        <td class="num">${{ number_format($affidavit['balance_due'], 2) }}</td>
                    </tr>
                    @for($i = 0; $i < 2; $i++)
                        <tr><td class="blank"></td><td></td><td></td><td></td><td></td><td></td></tr>
                    @endfor
                    <tr>
                        <td colspan="2"><strong>TOTAL LABOR AND MATERIAL INCLUDING EXTRAS* TO COMPLETE</strong></td>
                        <td class="num"><strong>${{ number_format($affidavit['contract_total'], 2) }}</strong></td>
                        <td class="num"><strong>${{ number_format($affidavit['prior_paid'], 2) }}</strong></td>
                        <td class="num"><strong>${{ number_format($affidavit['this_payment'], 2) }}</strong></td>
                        <td class="num"><strong>${{ number_format($affidavit['balance_due'], 2) }}</strong></td>
                    </tr>
                </tbody>
            </table>

            <p>
                That there are no other contracts for said work outstanding, and that there is nothing due or
                to become due to any person for material, labor or other work of any kind done or to be done
                upon or in connection with said work other than above stated.
            </p>

            <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                <tr>
                    <td style="width: 40%; vertical-align: bottom;">
                        <strong>DATE</strong> <span class="fill">{{ optional($waiver->through_date)->format('m/d/Y') ?? now()->format('m/d/Y') }}</span>
                    </td>
                    <td style="width: 60%; vertical-align: bottom;" class="{{ $isDraft ? 'draft-signature-zone' : '' }}">
                        <strong>SIGNATURE</strong>
                        @if($firstSignature)
                            <span class="il-sig-line" style="text-align: center;">
                                @if($firstSignature->signature_type === 'type')
                                    <span class="typed-signature" style="font-size: 26px;">{{ $firstSignature->signer_name }}</span>
                                @else
                                    <img src="{{ $firstSignature->signature_data }}" style="max-height: 44px; vertical-align: bottom;" alt="signature" />
                                @endif
                            </span>
                        @else
                            <span class="il-sig-line">&nbsp;</span>
                        @endif
                    </td>
                </tr>
            </table>

            <p style="margin-top: 12px;">
                SUBSCRIBED AND SWORN TO BEFORE ME THIS <span class="fill" style="min-width: 60px;"></span> DAY OF
                <span class="fill" style="min-width: 140px;"></span>, 20<span class="fill" style="min-width: 40px;"></span>
            </p>

            <table style="width: 100%; border-collapse: collapse; margin-top: 6px; page-break-inside: avoid;">
                <tr>
                    <td style="width: 50%; vertical-align: top;" class="il-small">
                        *EXTRAS INCLUDE BUT ARE NOT LIMITED TO CHANGE<br>
                        ORDERS BOTH ORAL AND WRITTEN, TO THE CONTRACT
                    </td>
                    <td style="width: 50%; vertical-align: top; text-align: center;">
                        _____________________________________________<br>
                        NOTARY PUBLIC
                    </td>
                </tr>
            </table>
        </div>

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
    @else
        <h1>{{ $waiver->typeLabel() }}</h1>
        <p class="subtitle">
            Jurisdiction: {{ $waiver->jurisdiction }}
            &nbsp;·&nbsp;
            <span class="badge">{{ $waiver->type?->shortLabel() }}</span>
        </p>

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
    @endif {{-- end non-Illinois branch --}}
</body>
</html>
