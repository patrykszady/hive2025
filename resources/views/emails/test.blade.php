<x-mail::message>
# Test Mail

If you are reading this, outbound mail is working.

This message is sent by `App\Mail\TestMail` and exists to verify delivery and
authentication (SPF, DKIM, DMARC) without involving real customer data.

**Sent:** {{ now()->toDayDateTimeString() }}
**From:** {{ config('mail.from.address') }}
**Mailer:** {{ config('mail.default') }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
