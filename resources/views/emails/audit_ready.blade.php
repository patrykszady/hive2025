<x-mail::message>
# {{ $audit->label() }} is ready

The audit package for **{{ $companyName }}** is on Hive: the policy certificates in one PDF and the Excel report (By Vendor and All Checks). The links below need no sign-in; this email can be forwarded as it is.

@if($pdfUrl)
<x-mail::button :url="$pdfUrl">
Download the certificates PDF
</x-mail::button>
@endif
@if($xlsxUrl)
<x-mail::button :url="$xlsxUrl">
Download the Excel report
</x-mail::button>
@endif

@if($attach)
Both files are attached to this email as well.
@else
The files are too large to attach to an email; use the links above.
@endif

@if($audit->audit_type === 'workers')
**EWCCV coverage as of {{ $audit->coverage_date?->format('m/d/Y') }}** ({{ $counts['total'] }} subs with a workers comp policy on file):

- Covered: {{ $counts['covered'] }}
- Not found on EWCCV: {{ $counts['not_found'] }}
@if($counts['pending'] + $counts['unchecked'] > 0)
- Not checked on EWCCV (the certificate on file stands): {{ $counts['pending'] + $counts['unchecked'] }}
@endif
@endif

@if($missingFiles !== [])
The certificates PDF could not be built because these policy files are missing: {{ implode(', ', $missingFiles) }}.
@endif

<x-mail::button :url="route('vendor_docs.audit', ['start_date' => $audit->start_date->toDateString(), 'end_date' => $audit->end_date->toDateString(), 'audit_type' => $audit->audit_type, 'bank_account_ids' => $audit->bank_account_ids])">
Open the audit
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
