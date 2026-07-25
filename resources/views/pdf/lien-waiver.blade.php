<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    {{-- Deliberately blank: the <title> becomes the PDF's Title metadata,
         which Chrome prints as a header when the vendor prints the form. --}}
    <title></title>
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
        /* Traditional IL combined single-page form (matches office "Partial Waiver" layout) */
        .il-form { font-size: 8.5pt; line-height: 1.26; }
        .il-form p { margin: 4px 0; text-align: justify; }
        .il-form .fill { display: inline-block; border-bottom: 1px solid #333; padding: 0 5px 1px; min-width: 78px; font-weight: 600; text-align: center; }
        /* Empty blanks get a &nbsp; so their underline sits at the same depth as filled ones. */
        .il-form .fill:empty::after { content: "\00a0"; }
        /* Sign-here marker: highlighter-yellow asterisk. */
        .req-star { background: #fde047; padding: 0 2px; font-weight: 700; }
        .il-form .fill.wide { min-width: 170px; }
        .il-form .fill.sm { min-width: 46px; }
        .il-form .fill.md { min-width: 130px; }
        .il-form .fill.xs { min-width: 40px; }
        /* Fill-in lines whose underline stretches to the right margin */
        .il-ln { display: flex; align-items: flex-end; margin: 3px 0; }
        .il-ln .lbl { white-space: nowrap; padding-right: 6px; }
        .il-ln .lbl.sfx { padding-right: 0; padding-left: 8px; }
        .il-ln .uline { flex: 1 1 auto; border-bottom: 1px solid #333; min-width: 30px; min-height: 1.15em; font-weight: 600; padding: 0 6px 1px; text-align: center; }
        .il-ln .uline.left { text-align: left; }
        .il-ln .uline.sig { min-height: 19px; }
        .il-row { display: flex; gap: 20px; margin: 3px 0; align-items: flex-end; }
        .il-row .il-ln { margin: 0; }
        .il-row .col-date { flex: 0 0 30%; }
        .il-row .col-grow { flex: 1 1 auto; }
        .il-draftsign { color: #b91c1c; font-weight: 700; font-size: 7.5pt; letter-spacing: 0.06em; }
        .il-state-plain { margin: 0 0 2px; }
        .il-title { font-size: 12pt; font-weight: 700; text-align: center; text-decoration: underline; margin: 5px 0 6px; letter-spacing: 0.02em; }
        .il-small { font-size: 7.2pt; }
        .il-tw { margin: 5px 0 2px; }
        .il-divider { border-top: 1.5px solid #333; margin: 9px 0 7px; }
        .il-affidavit-table { width: 100%; border-collapse: collapse; margin: 7px 0; font-size: 7.9pt; }
        .il-affidavit-table th, .il-affidavit-table td { border: 1px solid #333; padding: 3px 5px; text-align: left; }
        .il-affidavit-table th { background: #f0f0f0; font-size: 7pt; text-transform: uppercase; text-align: center; }
        .il-affidavit-table td.num { text-align: right; white-space: nowrap; }
        .il-affidavit-table tr.blank td { height: 10px; }
        .il-affidavit-table td.note { font-size: 7.5pt; text-align: justify; font-weight: 400; line-height: 1.25; }
        .il-notary { width: 100%; border-collapse: collapse; margin-top: 8px; page-break-inside: avoid; }
        .il-notary td { vertical-align: bottom; font-size: 8pt; }
        .il-notary td.notary-sig { width: 46%; text-align: center; }
        .il-notary .notary-line { border-bottom: 1px solid #333; height: 26px; margin: 0 6px; }
        .il-extras-note { margin: 5px 0 0; }
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
    {{-- No DRAFT watermark: unsigned waivers are print-and-sign documents,
         mailed to vendors for wet signature and notarization. --}}

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

        $amountNumberIl = $resolvedAmount !== null
            ? number_format((float) $resolvedAmount, 2)
            : '';
    @endphp

    @php
        // Direct view() renders (tests, previews) may omit the generator-computed
        // context — default them so the template stands alone.
        $amountWords = $amountWords ?? null;
        $affidavit = $affidavit ?? ['contract_total' => 0.0, 'original_contract' => 0.0, 'extras' => 0.0, 'amount_paid' => 0.0, 'this_payment' => (float) ($waiver->amount ?? 0), 'balance_due' => 0.0];
        $isSubWaiver = $isSubWaiver ?? $waiver->isSubWaiver();
        $payerNameIl = $payerVendor?->business_name ?? ($payerOverride['name'] ?? 'the Owner / General Contractor');
        $ownerNameIl = $project->client?->name ?? ($payerOverride['name'] ?? 'the Owner');
        $projectAddressIl = trim(($project->address ?? '') . ', ' . ($project->city ?? '') . ', ' . ($project->state ?? '') . ' ' . ($project->zip_code ?? ''), ', ');
        // A bare state (defaulted "IL") isn't an address — leave the line blank.
        $vendorHasAddress = trim((string) ($vendor->address ?? '')) !== '' || trim((string) ($vendor->city ?? '')) !== '' || trim((string) ($vendor->zip_code ?? '')) !== '';
        $vendorAddressIl = $vendorHasAddress ? trim(($vendor->address ?? '') . ', ' . ($vendor->city ?? '') . ', ' . ($vendor->state ?? '') . ' ' . ($vendor->zip_code ?? ''), ', ') : '';
        $vendorCityStateZipIl = $vendorHasAddress ? trim(($vendor->city ?? '') . ', ' . ($vendor->state ?? '') . ' ' . ($vendor->zip_code ?? ''), ', ') : '';
        $workFurnishedIl = 'labor, services, material, fixtures, apparatus and machinery';
        // No e-sign flow: documents render with blank signature blanks and are
        // wet-signed on paper; the executed copy comes back via the scan ingest.
        $firstSignature = null;
        $signatures = collect();
        $isPaidInFullIl = $waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal;

        if ($isPaidInFullIl) {
            $considerationWordsIl = 'payment in full of the contract price';
            $considerationNumberIl = 'PAID IN FULL';
        } else {
            $considerationWordsIl = $amountWords ?? '';
            $considerationNumberIl = $amountNumberIl;
        }

        // Affidavit dollar cells: zeros print explicitly ($0.00 this payment /
        // balance due is an affirmative sworn statement, not a blank to fill).
        $affAmt = fn ($v) => $v === null ? '' : '$' . number_format((float) $v, 2);

        // The affidavit's trade wording. Waivers created from the sworn
        // statement carry the GCSS row's Kind of Work in their notes; otherwise
        // the claimant vendor's saved default work type fills in.
        $waiverNotesArr = json_decode((string) ($waiver->notes ?? ''), true) ?: [];
        $workKind = trim((string) ($waiverNotesArr['kind_of_work'] ?? ''));
        if ($workKind === '' && $isSubWaiver) {
            $workKind = trim((string) ($vendor->workType?->name ?? ''));
        }
        $workKindSuffix = 'labor & material (incl. extras)';
        $isRetailClaimant = ! empty($waiverNotesArr['retail']) || ($vendor->business_type ?? null) === 'Retail';
        // Open contract: a sub waiver that is NOT final but whose contract
        // equals the amount paid reads as "paid in full" on paper while the
        // contract is still running — mark those figures OPEN. Derived from
        // the affidavit numbers (covers no-bid fallbacks automatically);
        // legacy waivers with an explicit notes flag keep it.
        $isOpenContract = ! empty($waiverNotesArr['open'])
            || ($isSubWaiver
                && ! ($waiver->type?->isFinal() ?? false)
                && (float) ($affidavit['contract_total'] ?? 0) > 0
                && abs((float) ($affidavit['contract_total'] ?? 0) - (float) ($affidavit['amount_paid'] ?? 0)) < 0.01);

        if ($isRetailClaimant) {
            // Retail vendors supply goods/services — kind as typed, no suffix.
            $whatForIl = $workKind;
        } elseif ($workKind !== '') {
            $whatForIl = $workKind . ' — ' . $workKindSuffix;
        } elseif ($isSubWaiver) {
            $whatForIl = ucfirst($workKindSuffix);
        } else {
            $whatForIl = 'General construction — ' . $workKindSuffix;
        }

        $furnishingIl = $isSubWaiver ? strtolower($workKind) : 'general construction';

        // "to furnish ___" on the waiver names the claimant's work type when
        // known; the traditional catch-all covers the GC and untyped vendors.
        $workFurnishedIl = $workKind !== ''
            ? $workKind
            : 'labor, services, material, fixtures, apparatus and machinery';
    @endphp

    @if($isIllinois)
        {{-- ===== TRADITIONAL ILLINOIS COMBINED FORM — matches office "Partial Waiver" layout ===== --}}
        @php
            $countyIl = !empty($projectCounty ?? null) ? strtoupper($projectCounty) : '';
            // The signing DATE stays blank until the vendor signs in front of a
            // notary; once a signature is captured it shows the signed date.
            $dateIl = $isSigned
                ? optional($firstSignature?->signed_at ?? $waiver->updated_at)->format('m/d/Y')
                : '';
        @endphp
        <div class="il-form">
            {{-- -------------------- WAIVER OF LIEN TO DATE -------------------- --}}
            <div class="il-state-plain">STATE OF ILLINOIS<br>COUNTY OF <span class="fill">{{ $countyIl }}</span></div>

            <div class="il-title">{{ $waiver->type?->isFinal() ? 'FINAL WAIVER OF LIEN' : 'WAIVER OF LIEN TO DATE' }}</div>

            <div class="il-ln"><span class="lbl">WHEREAS the undersigned has been employed by</span><span class="uline">{{ $payerNameIl }}</span></div>
            <div class="il-ln"><span class="lbl">to furnish</span><span class="uline">{{ $workFurnishedIl }}</span></div>
            <div class="il-ln"><span class="lbl">for the premises known as</span><span class="uline">{{ $projectAddressIl ?: '' }}</span></div>
            <div class="il-ln"><span class="lbl">of which</span><span class="uline">{{ $ownerNameIl }} is the owner</span></div>

            <div class="il-ln"><span class="lbl">THE undersigned, for and in consideration of</span><span class="uline">{{ $considerationWordsIl }}</span><span class="lbl sfx il-small">(write out amount)</span></div>

            <p style="margin-top:4px;">
                (<span class="fill">{{ $considerationNumberIl }}</span>) Dollars,
                and other good and valuable considerations, the receipt whereof is hereby acknowledged,
                do(es) hereby waive and release any and all lien or claim of, or right to, lien, under the
                statutes of the State of ILLINOIS, relating to mechanics&rsquo; liens, with respect to and on said
                above-described premises, and the improvements thereon, and on the material, fixtures,
                apparatus or machinery furnished, and on the moneys, funds or other considerations due or to
                become due from the owner, on account of all labor, services, material, fixtures, apparatus
                or machinery,
                @if($waiver->type?->isFinal()) heretofore furnished or which may be furnished at any time hereafter,
                @else heretofore furnished to this date @endif
                by the undersigned for the above-described premises, INCLUDING EXTRAS.*
            </p>

            <div class="il-row">
                <div class="il-ln col-date"><span class="lbl">DATE<span class="req-star">*</span></span><span class="uline">{{ $dateIl }}</span></div>
                <div class="il-ln col-grow"><span class="lbl">COMPANY NAME</span><span class="uline left">{{ $vendor->business_name }}</span></div>
            </div>
            <div class="il-row">
                <div class="col-date"></div>
                <div class="il-ln col-grow"><span class="lbl">ADDRESS</span><span class="uline left">{{ $vendorAddressIl }}</span></div>
            </div>
            <div class="il-ln">
                <span class="lbl">SIGNATURE AND TITLE<span class="req-star">*</span></span>
                <span class="uline left sig">
                    @if($firstSignature)
                        @if($firstSignature->signature_type === 'type')
                            <span class="typed-signature" style="font-size:15px;">{{ $firstSignature->signer_name }}</span>
                        @else
                            <img src="{{ $firstSignature->signature_data }}" style="max-height:26px;vertical-align:bottom;" alt="signature" />
                        @endif
                        @if($firstSignature->signer_title)<span style="font-size:8pt;color:#444;">, {{ $firstSignature->signer_title }}</span>@endif
                    @else
                        &nbsp;
                    @endif
                </span>
            </div>

            <p class="il-small il-extras-note">*EXTRAS INCLUDE BUT ARE NOT LIMITED TO CHANGE ORDERS, BOTH ORAL AND WRITTEN, TO THE CONTRACT</p>

            {{-- Every claimant swears its own affidavit: the GC's recites the
                 owner contract, a sub's recites its sub-contract with the GC.
                 The all-subs listing lives on the standalone GCSS statement.
                 Retail vendors (suppliers, rentals, haulers) sign the waiver
                 only — they have no contract math to swear to. --}}
            @if(! $isRetailClaimant)
            <div class="il-divider"></div>

            {{-- -------------------- CONTRACTOR'S AFFIDAVIT -------------------- --}}
            <div class="il-title">CONTRACTOR&rsquo;S AFFIDAVIT</div>

            <div class="il-state-plain">STATE OF ILLINOIS<br>COUNTY OF <span class="fill">{{ strtoupper((string) $projectCounty) }}</span></div>

            <p class="il-tw"><strong>TO WHOM IT MAY CONCERN:</strong></p>

            <p style="margin-top:0;">
                THE UNDERSIGNED, (NAME)<span class="req-star">*</span> <span class="fill wide">{{ $firstSignature?->signer_name ?? '' }}</span>
                BEING DULY SWORN, DEPOSES AND SAYS THAT HE OR SHE IS (POSITION)<span class="req-star">*</span>
                <span class="fill">{{ $firstSignature?->signer_title ?? '' }}</span>
                OF (COMPANY NAME) <span class="fill wide">{{ $vendor->business_name }}</span>
                WHO IS THE CONTRACTOR FURNISHING <span class="fill">{{ $furnishingIl }}</span> WORK ON THE
                BUILDING LOCATED AT <span class="fill wide">{{ $projectAddressIl ?: '' }}</span>
                OWNED BY <span class="fill wide">{{ $ownerNameIl }}</span>.
            </p>

            <p>
                That the total amount of the contract including extras* is
                <span class="fill">{{ $affAmt($affidavit['contract_total']) }}</span>@if(($affidavit['extras'] ?? 0) > 0) (original contract ${{ number_format($affidavit['original_contract'], 2) }} plus extras of ${{ number_format($affidavit['extras'], 2) }})@endif
                on which he or she has received payment of
                <span class="fill">{{ $affAmt($affidavit['amount_paid']) }}</span>
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
                        <th style="width: 27%;">Names</th>
                        <th style="width: 21%;">What For</th>
                        <th style="width: 13%;">Contract Price</th>
                        <th style="width: 13%;">Amount Paid</th>
                        <th style="width: 13%;">This Payment</th>
                        <th style="width: 13%;">Balance Due</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $vendor->business_name }}</td>
                        <td>{{ $whatForIl }}</td>
                        <td class="num">{!! $affAmt($affidavit['contract_total']) . ($isOpenContract ? '<br>OPEN' : '') !!}</td>
                        <td class="num">{{ $affAmt($affidavit['amount_paid']) }}</td>
                        <td class="num">{{ $affAmt($affidavit['this_payment']) }}</td>
                        <td class="num">{!! $affAmt($affidavit['balance_due']) . ($isOpenContract ? '<br>OPEN' : '') !!}</td>
                    </tr>
                    @for($i = 0; $i < 3; $i++)
                        <tr class="blank"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>
                    @endfor
                    <tr>
                        <td colspan="6" class="note">
                            <strong>TOTAL LABOR AND MATERIAL INCLUDING EXTRAS* TO COMPLETE</strong><br>
                            That there are no other contracts for said work outstanding, and that there is
                            nothing due or to become due to any person for material, labor or other work of any kind done or to be
                            done upon or in connection with said work other than above stated.
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="il-row">
                <div class="il-ln col-date"><span class="lbl">DATE</span><span class="uline">{{ $dateIl }}</span></div>
                <div class="il-ln col-grow">
                    <span class="lbl">SIGNATURE</span>
                    <span class="uline left sig">
                        @if($firstSignature)
                            @if($firstSignature->signature_type === 'type')
                                <span class="typed-signature" style="font-size:15px;">{{ $firstSignature->signer_name }}</span>
                            @else
                                <img src="{{ $firstSignature->signature_data }}" style="max-height:26px;vertical-align:bottom;" alt="signature" />
                            @endif
                        @else
                            &nbsp;
                        @endif
                    </span>
                </div>
            </div>

            <p style="margin:18px 0 0;">
                SUBSCRIBED AND SWORN TO BEFORE ME THIS <span class="fill sm"></span> DAY OF
                <span class="fill md"></span>, 20<span class="fill xs"></span>
            </p>

            <table class="il-notary">
                <tr>
                    <td class="il-small">*EXTRAS INCLUDE BUT ARE NOT LIMITED TO CHANGE ORDERS, BOTH ORAL AND WRITTEN, TO THE CONTRACT</td>
                    <td class="notary-sig">
                        <div class="notary-line"></div>
                        NOTARY PUBLIC
                    </td>
                </tr>
            </table>
            @endif {{-- end affidavit (skipped for retail claimants) --}}
        </div>

        @if($signatures->isNotEmpty())
            <div class="audit" style="margin-top:4px;font-size:6.8pt;line-height:1.25;">
                <strong>Audit Trail:</strong> Doc hash {{ strlen((string) $waiver->document_hash) > 28 ? substr((string) $waiver->document_hash, 0, 16) . '…' . substr((string) $waiver->document_hash, -8) : (string) $waiver->document_hash }}.
                @foreach($signatures as $sig)
                    Signed by {{ $sig->signer_name }} ({{ $sig->signer_email ?? 'no email' }}) from IP {{ $sig->ip_address }} at {{ optional($sig->signed_at)->format('Y-m-d H:i:s T') }}.
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
                {{ $vendorHasAddress ? ($vendor->address ?? '') : '' }}<br>
                {{ $vendorCityStateZipIl }}
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
            <td class="totals"><strong>
                @if($waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal)
                    PAID IN FULL
                @else
                    {{ $displayAmount }}
                @endif
            </strong></td>
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
