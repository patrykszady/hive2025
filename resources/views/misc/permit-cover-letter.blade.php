{{-- The cover letter GS Construction puts in front of every plan set it
     files for a permit — the Word template "GS Construciton COVER LETTER"
     (public/), page for page: letterhead with the logo, the job's title,
     who it is prepared for, the packet's contents, the village's adopted
     codes, and the smoke/CO detector note every permit carries.
     Rendered by App\Support\Permits\PermitPacketBuilder (Letter, 1" margins). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — Cover Letter</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Gelasio stands in for the template's Sylfaen; Patrick Hand for the
         hand-written sticky note. Each falls back to a system face. --}}
    <link href="https://fonts.googleapis.com/css2?family=Gelasio:wght@400;700&family=Patrick+Hand&display=swap" rel="stylesheet">
    <style>
        @page { size: Letter; margin: 0; }
        html, body { margin: 0; padding: 0; }
        body { font-family: Verdana, 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #262626; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .page { position: relative; width: 8.5in; height: 11in; box-sizing: border-box; padding: 0.55in 1in 1in 1in; page-break-after: avoid; }
        .serif { font-family: Gelasio, Sylfaen, Georgia, 'DejaVu Serif', serif; }

        /* Letterhead: the business card — the full badge over the owners' names, the office email, phone and website. */
        .letterhead { text-align: center; }
        .letterhead img { width: 2.1in; height: auto; display: block; margin: 0 auto 0.1in; }
        .letterhead .names { font-weight: bold; font-size: 11.5pt; color: #1b1f5e; letter-spacing: 0.02em; margin: 0 0 3pt; }
        .letterhead .line { font-size: 9.5pt; line-height: 1.5; color: #262626; }
        .letterhead .line b { font-weight: bold; }

        .title { text-align: center; font-weight: bold; font-size: 14pt; color: #262626; margin: 0.38in 0 0.28in; letter-spacing: 0.02em; }
        .section { text-align: center; margin: 0.26in 0 0; }
        .section h2 { font-weight: bold; font-size: 12pt; color: #595959; margin: 0 0 0.06in; }
        .section p { margin: 0; line-height: 1.55; }

        /* The sticky note from the template: a tilted white card with a hard black shadow, written by hand. */
        .note { position: absolute; left: 0.5in; top: 3.75in; width: 2.2in; box-sizing: border-box; padding: 0.14in 0.18in 0.16in; background: #fff; border: 1px solid #111; box-shadow: 0.11in 0.11in 0 #000; transform: rotate(-12deg); transform-origin: center; font-family: 'Patrick Hand', 'Segoe Print', 'Comic Sans MS', Verdana, sans-serif; font-size: 11.5pt; line-height: 1.22; color: #111; }
        .note .heading { font-size: 12pt; letter-spacing: 0.03em; margin-bottom: 2pt; }
        .note p { margin: 0 0 1.5pt; }
        .amendments { text-align: center; margin-top: 0.08in; font-size: 10pt; }
    </style>
</head>
<body>
<div class="page">
    <div class="letterhead" data-letterhead>
        @if ($letterhead['logoDataUrl'])
            <img src="{{ $letterhead['logoDataUrl'] }}" alt="{{ $vendorName }}">
        @endif
        <div class="names serif">{{ $letterhead['names'] }}</div>
        @if ($letterhead['email'])
            <div class="line">{{ $letterhead['email'] }}</div>
        @endif
        @if ($letterhead['phone'])
            <div class="line">{{ $letterhead['phone'] }}</div>
        @endif
        @if ($letterhead['website'])
            <div class="line">www.<b>{{ Str::before(Str::after($letterhead['website'], 'www.'), '.') }}</b>.{{ Str::after(Str::after($letterhead['website'], 'www.'), '.') }}</div>
        @endif
    </div>

    <div class="title serif" data-cover-title>{{ $title }}</div>

    {{-- Every permit carries the detector note (IRC R314/R315): beside the
         "prepared for" block, at the left margin, as the template has it. --}}
    <div class="note" data-cover-note>
        <p class="heading">SMOKE DETECTOR SYSTEM:</p>
        <p>-To be installed per IRC 314.2</p>
        <p>-S/D &amp; C/M on every level of residence and within 15’ of each bedroom.</p>
        <p>-S/D in each bedroom</p>
        <p>-Wirelessly connected with 1 wired master on a circuit with at least 1 light or outlet.</p>
    </div>

    <div class="section" data-cover-prepared-for>
        <h2 class="serif">ESPECIALLY PREPARED FOR</h2>
        <p>&nbsp;</p>
        @foreach ($preparedFor as $line)
            <p>{{ $line }}</p>
        @endforeach
    </div>

    <div class="section" data-cover-contents>
        <h2 class="serif">CONTENTS</h2>
        @foreach ($contents as $line)
            <p>{{ $line }}</p>
        @endforeach
    </div>

    <div class="section" data-cover-codes>
        <h2 class="serif">CODES</h2>
        @foreach ($codes as $line)
            <p>{{ $line }}</p>
        @endforeach
        <p class="amendments">*All codes with applicable local amendments*</p>
    </div>
</div>
</body>
</html>
