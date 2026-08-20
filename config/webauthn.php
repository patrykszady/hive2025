<?php

/*
 * LOCAL DEV: use http://localhost:8000, never http://127.0.0.1:8000.
 *
 * WebAuthn requires the Relying Party ID to be a registrable DOMAIN, and an
 * IP address is not one. On 127.0.0.1 the browser refuses the ceremony before
 * any request is sent (NotAllowedError / AttestationCancelled, with no server
 * log line at all) — and omitting rp.id does not help, because the default is
 * then the origin's host, which is still an IP. `localhost` is the one origin
 * the spec exempts from both this and the HTTPS requirement.
 *
 * APP_URL must also be localhost: /users/* is behind auth, and a login
 * redirect built from an APP_URL of 127.0.0.1 silently drags the browser back
 * to the origin that cannot work.
 */
$applicationUrl = rtrim((string) env('APP_URL', ''), '/');
$applicationHost = parse_url($applicationUrl, PHP_URL_HOST) ?: null;

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party
    |--------------------------------------------------------------------------
    |
    | We will use your application information to inform the device who is the
    | relying party. While only the name is enough, you can further set the
    | a custom domain as ID and even an icon image data encoded as BASE64.
    |
    */

    'relying_party' => [
        'name' => env('WEBAUTHN_NAME', config('app.name')),
        'id' => env('WEBAUTHN_ID', $applicationHost),
    ],

    /*
    |--------------------------------------------------------------------------
    | Origins
    |--------------------------------------------------------------------------
    |
    | By default, only your application domain is used as a valid origin for
    | all ceremonies. If you are using your app as a backend for an app or
    | UI you may set additional origins to check against the ceremonies.
    |
    | For multiple origins, separate them using comma, like `foo,bar`.
    */

    'origins' => env('WEBAUTHN_ORIGINS', $applicationUrl ?: null),

    /*
    |--------------------------------------------------------------------------
    | Challenge configuration
    |--------------------------------------------------------------------------
    |
    | When making challenges your application needs to push at least 16 bytes
    | of randomness. Since we need to later check them, we'll also store the
    | bytes for a small amount of time inside this current request session.
    |
    | @see https://www.w3.org/TR/webauthn-2/#sctn-cryptographic-challenges
    |
    */

    'challenge' => [
        'bytes' => 16,
        'timeout' => 60,
        'key' => '_webauthn',
    ],
];
