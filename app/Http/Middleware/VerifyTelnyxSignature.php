<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify the Ed25519 signature Telnyx attaches to every webhook so forged
 * requests cannot drive our call-control logic (hang up live calls, trigger
 * voicemail, send SMS, etc.).
 *
 * Telnyx signs `"{timestamp}|{raw_body}"` with its account Ed25519 private
 * key and sends the base64 signature in the `telnyx-signature-ed25519`
 * header plus the unix `telnyx-timestamp` header. We verify against the
 * public key from Mission Control (config `services.telnyx.public_key`).
 *
 * When no public key is configured (e.g. local dev) verification is skipped
 * so existing environments keep working; set the key in production to enforce.
 */
class VerifyTelnyxSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $publicKeyBase64 = config('services.telnyx.public_key');

        // No key configured → skip (dev/local). Production should set it.
        if (empty($publicKeyBase64)) {
            return $next($request);
        }

        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            Log::channel('telnyx')->error('libsodium unavailable; cannot verify Telnyx signature');

            return response()->json(['status' => 'error', 'message' => 'signature verification unavailable'], 500);
        }

        $signatureBase64 = $request->header('telnyx-signature-ed25519');
        $timestamp = $request->header('telnyx-timestamp');

        if (empty($signatureBase64) || empty($timestamp)) {
            Log::channel('telnyx')->warning('Rejected Telnyx webhook: missing signature headers');

            return response()->json(['status' => 'error', 'message' => 'missing signature'], 403);
        }

        // Reject stale timestamps to prevent replay attacks.
        $tolerance = (int) config('services.telnyx.webhook_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            Log::channel('telnyx')->warning('Rejected Telnyx webhook: timestamp outside tolerance', [
                'timestamp' => $timestamp,
                'tolerance' => $tolerance,
            ]);

            return response()->json(['status' => 'error', 'message' => 'stale timestamp'], 403);
        }

        $signedPayload = $timestamp . '|' . $request->getContent();

        $signature = base64_decode($signatureBase64, true);
        $publicKey = base64_decode($publicKeyBase64, true);

        if ($signature === false || $publicKey === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            Log::channel('telnyx')->warning('Rejected Telnyx webhook: malformed signature or key');

            return response()->json(['status' => 'error', 'message' => 'invalid signature'], 403);
        }

        $valid = sodium_crypto_sign_verify_detached($signature, $signedPayload, $publicKey);

        if (! $valid) {
            Log::channel('telnyx')->warning('Rejected Telnyx webhook: signature mismatch');

            return response()->json(['status' => 'error', 'message' => 'invalid signature'], 403);
        }

        return $next($request);
    }
}
