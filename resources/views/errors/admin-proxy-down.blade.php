<!DOCTYPE html>
<html lang="en">
{{--
    Rendered by AdminProxyController when ss-systems (the central admin
    this /admin/* proxies to) cannot be reached. Deliberately standalone —
    no layout, no Livewire, nothing that depends on the admin backend it is
    reporting the absence of. Ported from dawnsellshomes'/gsc's identical
    page; Hive's login screen uses indigo as its accent, so this wears that
    instead of Dawn's red.
--}}
@php
    $name = config('app.name', 'Hive Contractors');
@endphp
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin unavailable — {{ $name }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F2F5F9;
            color: #48586B;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            padding: 1.5rem;
        }
        .card {
            max-width: 28rem;
            text-align: center;
        }
        .eyebrow {
            font-size: 0.7rem;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: #8A99AA;
            margin: 0 0 0.75rem;
        }
        h1 {
            font-weight: 600;
            font-size: 1.75rem;
            line-height: 1.2;
            margin: 0 0 0.75rem;
            color: #0F1E2E;
        }
        p {
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0 0 1.5rem;
        }
        a {
            display: inline-block;
            font-size: 0.85rem;
            font-weight: 500;
            letter-spacing: 0.02em;
            color: #fff;
            background: #4F46E5;
            text-decoration: none;
            padding: 0.7rem 1.5rem;
            border-radius: 0.5rem;
        }
        a:hover { background: #4338CA; }
    </style>
</head>
<body>
    <div class="card">
        <p class="eyebrow">Admin</p>
        <h1>Admin is temporarily unavailable</h1>
        <p>
            We could not reach the admin service just now. Nothing else on
            the site is affected — please try again in a few minutes.
        </p>
        <a href="/">Return to the site</a>
    </div>
</body>
</html>
