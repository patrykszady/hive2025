<x-mail::message :show-header-brand="true">
@php
$banksUrl = rtrim((string) config('app.url'), '/') . '/banks';
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center; margin: 0 0 14px 0;">Bank Connection Error</h1>
<p style="margin-top: 10px; text-align: center; color: #3f3f46; font-size: 15px; line-height: 1.5;">
    A bank connection has encountered an error and may require attention.
</p>
</div>

<div style="height: 16px; line-height: 16px;">&nbsp;</div>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto; max-width: 480px;">
<tr><td style="padding: 3px 0;">
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td style="padding: 16px 20px; border: 1px solid #fca5a5; border-radius: 8px; background-color: #fff1f2;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="font-size: 13px; font-weight: 600; color: #991b1b; text-transform: uppercase; letter-spacing: 0.05em; padding-bottom: 8px;">
                Bank Connection Error
            </td>
        </tr>
        <tr>
            <td style="font-size: 15px; font-weight: 700; color: #1c1917; padding-bottom: 6px;">
                {{ $bank->name }}
            </td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #78350f; padding-bottom: 4px;">
                <strong>Error Code:</strong> {{ $errorCode }}
            </td>
        </tr>
        <tr>
            <td style="font-size: 13px; color: #44403c; line-height: 1.5;">
                {{ $errorMessage }}
            </td>
        </tr>
    </table>
</td>
</tr></table>
</td></tr>
</table>

<div style="height: 16px; line-height: 16px;">&nbsp;</div>

<p style="text-align: center; font-size: 14px; color: #52525b; line-height: 1.5; margin: 0;">
    Please visit the Banks page to reconnect or review the issue.
</p>

<div style="height: 16px; line-height: 16px;">&nbsp;</div>

<x-mail::button :url="$banksUrl" color="red">
    View Banks
</x-mail::button>

</x-mail::message>
