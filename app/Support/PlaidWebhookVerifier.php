<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies the `Plaid-Verification` JWT Plaid attaches to every webhook
 * request, per Plaid's documented webhook-verification scheme:
 * https://plaid.com/docs/api/webhooks/#verifying-webhooks
 *
 * The JWT header names a key id (`kid`); the matching public JWK is fetched
 * from Plaid's `/webhook_verification_key/get` endpoint and cached (Plaid
 * rotates keys rarely). The JWT is ES256 (ECDSA P-256 + SHA-256) and PHP's
 * openssl extension verifies raw signatures against a PEM-encoded key, not a
 * JWK, so this builds that PEM by hand — no JWT/JOSE package is a dependency
 * of this app, and none is added for this.
 */
class PlaidWebhookVerifier
{
    private const CACHE_PREFIX = 'plaid_webhook_key:';

    /** Reject a webhook whose `iat` is further than this from now (seconds). */
    private const IAT_TOLERANCE = 300;

    public function __construct(
        private readonly string $clientId,
        private readonly string $secret,
        private readonly string $baseUrl,
    ) {
    }

    public static function fromConfig(): self
    {
        $environment = trim((string) config('services.plaid.env', ''));

        return new self(
            (string) config('services.plaid.client_id'),
            (string) config('services.plaid.secret'),
            $environment !== '' ? 'https://'.$environment.'.plaid.com' : '',
        );
    }

    /**
     * @param  string  $jwt  The raw `Plaid-Verification` header value.
     * @param  string  $rawBody  The exact request body bytes (before json_decode).
     */
    public function verify(string $jwt, string $rawBody): bool
    {
        if ($this->clientId === '' || $this->secret === '' || $this->baseUrl === '') {
            Log::channel('plaid_skips')->error('Plaid webhook verification skipped: Plaid is not configured.');

            return false;
        }

        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return false;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($signatureB64);

        if (! is_array($header) || ! is_array($payload)) {
            return false;
        }

        if (($header['alg'] ?? null) !== 'ES256') {
            Log::channel('plaid_skips')->warning('Rejected Plaid webhook: unexpected JWT alg', [
                'alg' => $header['alg'] ?? null,
            ]);

            return false;
        }

        $kid = (string) ($header['kid'] ?? '');

        if ($kid === '') {
            return false;
        }

        $iat = $payload['iat'] ?? null;

        if (! is_int($iat) || abs(time() - $iat) > self::IAT_TOLERANCE) {
            Log::channel('plaid_skips')->warning('Rejected Plaid webhook: iat outside tolerance', [
                'iat' => $iat,
            ]);

            return false;
        }

        $expectedBodyHash = (string) ($payload['request_body_sha256'] ?? '');

        if ($expectedBodyHash === '' || ! hash_equals($expectedBodyHash, hash('sha256', $rawBody))) {
            Log::channel('plaid_skips')->warning('Rejected Plaid webhook: body hash mismatch');

            return false;
        }

        $jwk = $this->fetchKey($kid);

        if ($jwk === null) {
            return false;
        }

        if ($this->verifySignature($headerB64.'.'.$payloadB64, $signature, $jwk)) {
            return true;
        }

        // The key may have rotated since it was cached — fetch once more
        // before giving up, per Plaid's documented advice.
        Cache::forget(self::CACHE_PREFIX.$kid);
        $freshJwk = $this->fetchKey($kid);

        if ($freshJwk === null) {
            return false;
        }

        return $this->verifySignature($headerB64.'.'.$payloadB64, $signature, $freshJwk);
    }

    /**
     * @return array{crv: string, x: string, y: string}|null
     */
    private function fetchKey(string $kid): ?array
    {
        return Cache::rememberForever(self::CACHE_PREFIX.$kid, function () use ($kid): ?array {
            try {
                $response = Http::post($this->baseUrl.'/webhook_verification_key/get', [
                    'client_id' => $this->clientId,
                    'secret' => $this->secret,
                    'key_id' => $kid,
                ]);
            } catch (\Throwable $e) {
                Log::channel('plaid_skips')->error('Plaid webhook key fetch failed', [
                    'message' => $e->getMessage(),
                ]);

                return null;
            }

            if (! $response->successful()) {
                Log::channel('plaid_skips')->warning('Plaid webhook key fetch returned an error', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $key = $response->json('key');

            if (! is_array($key) || ($key['crv'] ?? null) !== 'P-256' || empty($key['x']) || empty($key['y'])) {
                return null;
            }

            return $key;
        });
    }

    /**
     * @param  array{x: string, y: string}  $jwk
     */
    private function verifySignature(string $signedInput, string $joseSignature, array $jwk): bool
    {
        if (strlen($joseSignature) !== 64) {
            return false;
        }

        $pem = $this->pemFromJwk($jwk);
        $derSignature = $this->joseToDer(substr($joseSignature, 0, 32), substr($joseSignature, 32, 32));

        $publicKey = openssl_pkey_get_public($pem);

        if ($publicKey === false) {
            return false;
        }

        return openssl_verify($signedInput, $derSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Builds a PEM-encoded SubjectPublicKeyInfo for a P-256 (prime256v1) key
     * from its raw JWK x/y coordinates — the DER prefix below is the fixed
     * AlgorithmIdentifier for id-ecPublicKey + prime256v1 (RFC 5480).
     */
    private function pemFromJwk(array $jwk): string
    {
        $x = $this->base64UrlDecode($jwk['x']);
        $y = $this->base64UrlDecode($jwk['y']);

        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $der = $prefix."\x04".$x.$y;

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /** Converts a raw JOSE ECDSA signature (r||s, 32 bytes each) to ASN.1 DER. */
    private function joseToDer(string $r, string $s): string
    {
        $rDer = $this->derInteger($r);
        $sDer = $this->derInteger($s);
        $content = $rDer.$sDer;

        return "\x30".$this->derLength(strlen($content)).$content;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->derLength(strlen($bytes)).$bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $hex = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($hex)).$hex;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;

        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
