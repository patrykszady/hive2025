<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sworn Statement of Contractor</title>
    <style>
        @page { size: Letter; margin: 0.35in; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 8.5pt; color: #111; line-height: 1.26; }
        p { margin: 4px 0; text-align: justify; }
        .ss-title { font-size: 11pt; font-weight: 700; text-align: center; margin: 0 0 8px; letter-spacing: 0.02em; text-transform: uppercase; }
        .ss-head { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .ss-head td { vertical-align: top; font-size: 8.5pt; }
        .fill { display: inline-block; border-bottom: 1px solid #333; padding: 0 5px 1px; min-width: 78px; font-weight: 600; text-align: center; }
        /* Empty blanks get a &nbsp; so their underline sits at the same depth as filled ones. */
        .fill:empty::after { content: "\00a0"; }
        .fill.wide { min-width: 200px; }
        .fill.sm { min-width: 46px; }
        .ln { display: flex; align-items: flex-end; margin: 3px 0; }
        .ln .lbl { white-space: nowrap; padding-right: 6px; }
        .ln .lbl.sfx { padding-right: 0; padding-left: 8px; }
        .ln .uline { flex: 1 1 auto; border-bottom: 1px solid #333; min-width: 30px; min-height: 1.15em; font-weight: 600; padding: 0 6px 1px; text-align: center; }
        .recital { font-size: 7.4pt; font-style: italic; margin: 5px 0 4px; }
        .ss-table { width: 100%; border-collapse: collapse; margin: 4px 0; font-size: 7.7pt; }
        .ss-table th, .ss-table td { border: 1px solid #333; padding: 2px 4px; text-align: left; }
        .ss-table th { background: #f0f0f0; font-size: 6.8pt; text-transform: uppercase; text-align: center; }
        .ss-table td.num { text-align: right; white-space: nowrap; }
        .ss-table tr.filler td { height: 11px; }
        .ss-table tr { page-break-inside: avoid; }
        .ss-tail { page-break-inside: avoid; }
        .ss-table td.total-label { font-weight: 700; text-align: center; font-size: 8.5pt; }
        .ss-summary { width: 100%; border-collapse: collapse; margin: 6px 0 2px; font-size: 7.9pt; }
        .ss-summary td { border: 1px solid #333; padding: 2px 5px; }
        .ss-summary td.lbl-cell { font-weight: 700; text-transform: uppercase; font-size: 7pt; width: 30%; }
        .ss-summary td.num { text-align: right; white-space: nowrap; width: 20%; }
        .ss-notary { width: 100%; border-collapse: collapse; margin-top: 10px; page-break-inside: avoid; }
        .ss-notary td { vertical-align: bottom; font-size: 8pt; }
        .sig-line { border-bottom: 1px solid #333; min-height: 18px; }
    </style>
</head>
<body>
@php
    // Blaze-safe: closures live in this single top-level block (never in a
    // @php(...) directly after @if — see project memory on the compiler bug).
    $money = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
    $stateName = strtoupper((string) ($project->state ?? '')) === 'IL' ? 'Illinois' : (string) ($project->state ?? '');
    $premises = trim(implode(', ', array_filter([
        (string) ($project->address ?? ''),
        (string) ($project->city ?? ''),
        trim((string) ($project->state ?? '') . ' ' . (string) ($project->zip_code ?? '')),
    ])));
    $rowCount = count($lines) + 1; // + GC line
    $fillerRows = max(0, 6 - $rowCount);
@endphp

    <div class="ss-title">Sworn Statement of Contractor<br>to Owner and to Title Insurance Company</div>

    <table class="ss-head">
        <tr>
            <td style="width: 55%;">
                State of <span class="fill sm">{{ $stateName }}</span><br>
                County of <span class="fill">{{ mb_strtoupper($projectCounty ?? '') }}</span> <span style="font-size: 10pt;">&#125;</span> ss
            </td>
            <td style="width: 45%; font-size: 8pt;">
                Page <span class="fill sm">1</span> of <span class="fill sm">{{ $totalPages ?? 1 }}</span> Pages<br>
                Guarantee No. <span class="fill"></span><br>
                Escrow No. <span class="fill"></span>
            </td>
        </tr>
    </table>

    <div class="ln"><span class="lbl">The affiant,</span><span class="uline">{{ mb_strtoupper($affiantName ?? '') }}</span><span class="lbl sfx">being first duly sworn, on oath deposes and says that he/she is</span></div>
    <div class="ln"><span class="uline" style="flex: 0 0 26%;">{{ mb_strtoupper($affiantPosition ?? '') }}</span><span class="lbl sfx">of</span><span class="uline">{{ $contractor->business_name }}</span><span class="lbl sfx">that</span><span class="uline" style="flex: 0 0 8%;">it</span><span class="lbl sfx">has contract with</span></div>
    <div class="ln"><span class="uline">{{ $ownerName }}</span><span class="lbl sfx">owner, for</span><span class="uline">general construction &mdash; {{ $project->project_name }}</span></div>
    <div class="ln"><span class="lbl">following described premise in</span><span class="uline" style="flex: 0 0 18%;">{{ mb_strtoupper($projectCounty ?? '') }}</span><span class="lbl sfx">County, {{ $stateName }}, to-wit:</span><span class="uline">{{ $premises }}</span></div>

    <p class="recital">
        That, for the purposes of said contract, the following persons have been contracted with, and have furnished, or are furnishing and
        preparing materials for, and have done or are doing labor on said improvement. That there is due and to become due them, respectively,
        the amounts set opposite their names for materials or labor as stated. That this statement is a full, true and complete statement of all
        such persons, the amounts paid and the amounts due or to become due to each.
    </p>

    <table class="ss-table">
        <thead>
            <tr>
                <th style="width: 26%;">1<br>Name and Address</th>
                <th style="width: 16%;">2<br>Kind of Work</th>
                <th style="width: 12%;">3<br>Amount of Contract</th>
                <th style="width: 10%;">4<br>Retention (inc. Current)</th>
                <th style="width: 12%;">5<br>Net Previously Paid</th>
                <th style="width: 12%;">6<br>Amount This Payment</th>
                <th style="width: 12%;">7<br>Balance to Become Due</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $gcLine['name'] }}</td>
                <td>{{ $gcLine['kind'] }}</td>
                <td class="num">{{ $money($gcLine['contract']) }}</td>
                <td class="num">{{ $money(0) }}</td>
                <td class="num">{{ $money($gcLine['paid']) }}</td>
                <td class="num">{{ $money($gcLine['this_payment']) }}</td>
                <td class="num">{{ $money($gcLine['balance']) }}</td>
            </tr>
            @foreach($lines as $line)
                <tr>
                    <td>{{ $line['name'] }}</td>
                    <td>{{ $line['kind'] }}</td>
                    <td class="num">{{ $money($line['contract']) }}</td>
                    <td class="num">{{ $money(0) }}</td>
                    <td class="num">{{ $money($line['paid']) }}</td>
                    <td class="num">{{ $money($line['this_payment']) }}</td>
                    <td class="num">{{ $money($line['balance']) }}</td>
                </tr>
            @endforeach
            @for($i = 0; $i < $fillerRows; $i++)
                <tr class="filler">
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            @endfor
            <tr>
                <td class="total-label">TOTAL</td>
                <td></td>
                <td class="num" style="font-weight: 700;">{{ $money($summary['adjusted_total']) }}</td>
                <td class="num" style="font-weight: 700;">{{ $money(0) }}</td>
                <td class="num" style="font-weight: 700;">{{ $money($summary['prev_paid']) }}</td>
                <td class="num" style="font-weight: 700;">{{ $money($summary['this_payment']) }}</td>
                <td class="num" style="font-weight: 700;">{{ $money($summary['balance_due']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="ss-tail">
    <table class="ss-summary">
        <tr>
            <td class="lbl-cell">Amount of Original Contract</td>
            <td class="num">{{ $money($summary['original_contract']) }}</td>
            <td class="lbl-cell">Work Completed to Date</td>
            <td class="num">{{ $money($summary['work_completed']) }}</td>
        </tr>
        <tr>
            <td class="lbl-cell">Extras to Contract</td>
            <td class="num">{{ $money($summary['extras']) }}</td>
            <td class="lbl-cell">Less &nbsp;&nbsp;% Retained</td>
            <td class="num">{{ $money(0) }}</td>
        </tr>
        <tr>
            <td class="lbl-cell">Total Contract and Extras</td>
            <td class="num">{{ $money($summary['contract_total']) }}</td>
            <td class="lbl-cell">Net Previously Paid</td>
            <td class="num">{{ $money($summary['prev_paid']) }}</td>
        </tr>
        <tr>
            <td class="lbl-cell">Credits to Contract</td>
            <td class="num">{{ $money($summary['credits']) }}</td>
            <td class="lbl-cell">Net Amount of This Payment</td>
            <td class="num">{{ $money($summary['this_payment']) }}</td>
        </tr>
        <tr>
            <td class="lbl-cell">Adjusted Total Contract</td>
            <td class="num">{{ $money($summary['adjusted_total']) }}</td>
            <td class="lbl-cell">Balance to Become Due</td>
            <td class="num">{{ $money($summary['balance_due']) }}</td>
        </tr>
    </table>

    <p style="font-size: 7.8pt;">
        {{-- 100 − retainage%. No retainage is held on these draws, so payments
             may reach (but never exceed) the full value of work completed. --}}
        It is understood that the total amount paid to date plus the amount requested in this application shall not exceed
        <span class="fill sm">100</span>% of the cost of work completed to date.
    </p>

    <table class="ss-notary">
        <tr>
            <td style="width: 44%;"></td>
            <td style="width: 56%; padding-left: 24px;">
                <div class="ln" style="margin-top: 12px;"><span class="lbl">SIGNED</span><span class="uline"></span></div>
                <div class="ln" style="margin-top: 16px;"><span class="lbl">ADDRESS</span><span class="uline left">{{ trim(implode(', ', array_filter([(string) ($contractor->address ?? ''), (string) ($contractor->city ?? ''), trim((string) ($contractor->state ?? '') . ' ' . (string) ($contractor->zip_code ?? ''))]))) }}</span></div>
            </td>
        </tr>
    </table>

    <div class="ln" style="margin-top: 18px; width: 72%;">
        <span class="lbl">Subscribed and sworn to before me this</span>
        <span class="uline" style="flex: 0 0 42px;"></span>
        <span class="lbl sfx">day of</span>
        <span class="uline"></span>
        <span class="lbl sfx">, 20</span>
        <span class="uline" style="flex: 0 0 36px;"></span>
    </div>

    <div style="margin-top: 22px; width: 44%;">
        <div class="sig-line"></div>
        <div style="text-align: center; font-size: 7.5pt;">Notary Public</div>
    </div>
    </div>{{-- /ss-tail --}}
</body>
</html>
