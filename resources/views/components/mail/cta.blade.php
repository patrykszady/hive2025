{{-- Shared sign-up CTA card for mailables (lavender). Localized via lang/mail.php.
     The hive mark requires the mailable to embed public/favicon.png as
     CID "hive-mark" (see LienWaiverSigningRequest::withSymfonyMessage).
     Plain inline styles only — !important gets stripped by mail clients. --}}
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 28px 0 4px;">
<tr>
<td bgcolor="#eef2ff" style="border: 1px solid #c7d2fe; background-color: #eef2ff; border-radius: 12px; padding: 28px 24px; text-align: center;">
<div style="margin: 0 auto 12px;">
<img src="cid:hive-mark" width="44" height="44" alt="Hive" style="display: inline-block;">
</div>
<p style="margin: 0 0 8px; font-size: 17px; font-weight: 700; color: #312e81; text-align: center;">{{ __('mail.cta_heading') }}</p>
<p style="margin: 0 0 18px; font-size: 14px; color: #4338ca; line-height: 1.6; text-align: center;">{{ __('mail.cta_body') }}</p>
<table align="center" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
<td style="padding: 0 5px;">
<a href="{{ route('registration') }}" style="display: inline-block; background-color: #4f46e5; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; padding: 10px 22px; border-radius: 8px;">{{ __('mail.cta_register') }}</a>
</td>
<td style="padding: 0 5px;">
<a href="{{ route('login') }}" style="display: inline-block; background-color: #ffffff; color: #4338ca; font-size: 14px; font-weight: 600; text-decoration: none; padding: 10px 22px; border-radius: 8px; border: 1px solid #c7d2fe;">{{ __('mail.cta_login') }}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
