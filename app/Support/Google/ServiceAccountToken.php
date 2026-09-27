<?php

namespace App\Support\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A Google OAuth2 access token for a service account, obtained through the
 * JWT-bearer grant (RFC 7523) — no google/apiclient dependency. Ported
 * verbatim from dawnsellshomes' identical class, this app's Search Console
 * client's one credential source (GSC_CREDENTIALS): there is no per-owner
 * OAuth grant here, and no earlier hand-rolled JWT to extract this from —
 * this is the first thing on this app to talk to Search Console at all.
 *
 * Every site that reads Search Console needs a different scope
 * (webmasters for sitemap submission and search-analytics, the same scope
 * covers URL Inspection too), so instances are built per scope via
 * forScope() rather than as a singleton — two different scopes must never
 * share a cached token.
 *
 * The access token is cached until shortly before Google's own expiry
 * (typically 3600s), keyed on the credentials path + scope, so a command
 * that asks for a token many times in one run (or across runs within the
 * hour) does not re-sign a fresh JWT and round-trip to Google every time.
 */
class ServiceAccountToken
{
    protected ?string $lastError = null;

    public function __construct(
        protected readonly ?string $credentialsPath,
        protected readonly string $scope,
    ) {}

    public static function forScope(string $scope): self
    {
        return new self(config('services.google.search_console_credentials'), $scope);
    }

    /** A credential is present — a readable file, or the key itself — cheap, no network call. */
    public function isConfigured(): bool
    {
        return $this->rawCredential() !== null;
    }

    /**
     * GSC_CREDENTIALS holds either a path to the service-account JSON file
     * or the JSON itself (plain, or base64-encoded so it fits on one .env
     * line). The inline forms exist so production never needs a key file
     * placed on the server by hand: the environment is already private,
     * a command log that copied a file there would not be.
     */
    protected function rawCredential(): ?string
    {
        $value = trim((string) $this->credentialsPath);

        if ($value === '') {
            return null;
        }

        if (is_file($value)) {
            $raw = @file_get_contents($value);

            return $raw === false ? null : $raw;
        }

        if (str_starts_with($value, '{')) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) && str_starts_with(ltrim($decoded), '{') ? $decoded : null;
    }

    /**
     * A valid access token for this scope, from cache when not yet expired,
     * otherwise freshly minted and cached. Null (with lastError() set) when
     * the file is missing, unreadable, malformed, or Google refuses the
     * exchange — never throws, so a caller can always turn this into an
     * owner-facing message instead of a stack trace.
     */
    public function get(): ?string
    {
        $this->lastError = null;

        if (! $this->isConfigured()) {
            $this->lastError = 'GSC_CREDENTIALS not set or the file does not exist.';

            return null;
        }

        $cacheKey = $this->cacheKey();
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $fetched = $this->fetch();
        if ($fetched === null) {
            return null;
        }

        Cache::put($cacheKey, $fetched['access_token'], now()->addSeconds(max($fetched['expires_in'] - 120, 60)));

        return $fetched['access_token'];
    }

    /** Why the last get() returned null. Null after a successful call. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array{access_token: string, expires_in: int}|null
     */
    protected function fetch(): ?array
    {
        $raw = $this->rawCredential();
        $sa = $raw !== null ? json_decode($raw, true) : null;

        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            $this->lastError = 'The service account in GSC_CREDENTIALS is missing client_email or private_key.';

            return null;
        }

        $b64 = fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $now = time();
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'
            .$b64(json_encode([
                'iss' => $sa['client_email'],
                'scope' => $this->scope,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now, 'exp' => $now + 3600,
            ]));

        if (! openssl_sign($unsigned, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
            $this->lastError = 'Could not sign the JWT with the service account\'s private key.';

            return null;
        }

        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$b64($signature),
        ]);

        if (! $response->successful()) {
            $this->lastError = 'Google refused the token exchange ('.$response->status().'): '
                .mb_substr((string) ($response->json('error_description') ?? $response->body()), 0, 300);

            return null;
        }

        $accessToken = $response->json('access_token');
        if (! $accessToken) {
            $this->lastError = 'Google did not return an access token.';

            return null;
        }

        return [
            'access_token' => $accessToken,
            'expires_in' => (int) ($response->json('expires_in') ?? 3600),
        ];
    }

    protected function cacheKey(): string
    {
        return 'google-sa-token:'.sha1(($this->credentialsPath ?? '').'|'.$this->scope);
    }
}
