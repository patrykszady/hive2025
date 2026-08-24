@php
    $banksUrl = rtrim((string) config('app.url'), '/') . '/banks';
@endphp
BANK CONNECTION ERROR

A bank connection has encountered an error and may require attention.

Bank: {!! $bank->name !!}
Error code: {!! $errorCode !!}

{!! $errorMessage !!}

Please visit the Banks page to reconnect or review the issue:
{!! $banksUrl !!}

--
{!! config('app.name') !!}
