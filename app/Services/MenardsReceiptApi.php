<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Talks to menards.com the way its own front-end does: a form login followed by
 * the receipt-lookup JSON endpoints.
 *
 * WHY THIS EXISTS
 *
 * The original scraper drove a headless Chromium through the rendered page —
 * selecting a card from #paymentOptionSelect, reading div[id^="txrecRow-"] rows,
 * clicking #downloadReceipt and catching the file through CDP. That put a whole
 * browser between us and the data, and the browser is the part menards.com's
 * bot protection reacts to: from 2026-08-17 every run got an unrendered shell
 * while plain HTTP clients kept getting HTTP 200 from the same host.
 *
 * A HAR capture of a normal sign-in showed the page is only a shell over three
 * JSON calls, and that the login itself is an ordinary urlencoded form POST with
 * `"captchaEnabled":false`. So none of the browser was ever necessary:
 *
 *   GET  /main/login.html                              -> loginuuid + _csrf in the form
 *   POST /main/checkcredentials.html                   -> 302, session cookies
 *   GET  /main/security/csrf-token.ajx                 -> X-CSRF-TOKEN for the API
 *   GET  /main/my-account/receipt-lookup/initialize.ajx-> paymentOptions[]
 *   POST /main/my-account/receipt-lookup/receipts.ajx  -> paged transactions
 *   POST /main/my-account/receipt-lookup/download.ajx  -> {"receipt": "<base64 pdf>"}
 *
 * No rendering, no JavaScript, no captcha, no download manager — and nothing for
 * a browser-fingerprint check to look at, because there is no browser.
 */
class MenardsReceiptApi
{
    protected const BASE = 'https://www.menards.com';

    protected const LOGIN_PAGE = self::BASE . '/main/login.html';

    protected const LOGIN_POST = self::BASE . '/main/checkcredentials.html';

    protected const CSRF = self::BASE . '/main/security/csrf-token.ajx';

    protected const INITIALIZE = self::BASE . '/main/my-account/receipt-lookup/initialize.ajx';

    protected const RECEIPTS = self::BASE . '/main/my-account/receipt-lookup/receipts.ajx';

    protected const DOWNLOAD = self::BASE . '/main/my-account/receipt-lookup/download.ajx';

    protected const RECEIPT_PAGE = self::BASE . '/main/receiptLookup.html';

    /** Menards pages are large (the login page alone is ~2.4 MB of HTML). */
    protected const TIMEOUT = 60;

    /** Cookies persist across every call in a session — this is the jar. */
    protected array $cookies = [];

    /** The token the JSON endpoints want in an X-CSRF-TOKEN header. */
    protected ?string $apiCsrf = null;

    protected bool $authenticated = false;

    public function __construct(protected string $email, protected string $password) {}

    // ── HTTP plumbing ───────────────────────────────────────────────────────

    /**
     * A request carrying the current cookie jar.
     *
     * `withoutRedirecting()` matters on the login POST: the 302 IS the success
     * signal, and following it would discard the status we need to check.
     */
    protected function client(bool $follow = true): PendingRequest
    {
        $request = Http::timeout(self::TIMEOUT)
            ->withHeaders([
                'Accept-Language' => 'en-US,en;q=0.9',
                // A generic UA. This is not an attempt to look like a specific
                // browser — the endpoints are served to ordinary HTTP clients —
                // but a blank UA gets refused by a lot of CDNs.
                'User-Agent' => 'HiveContractors-ReceiptSync/1.0 (+https://hive.contractors)',
            ]);

        if ($this->cookies !== []) {
            $request = $request->withHeaders([
                'Cookie' => collect($this->cookies)->map(fn ($v, $k) => "{$k}={$v}")->implode('; '),
            ]);
        }

        return $follow ? $request : $request->withoutRedirecting();
    }

    /** Merge Set-Cookie from a response into the jar. */
    protected function captureCookies(\Illuminate\Http\Client\Response $response): void
    {
        foreach ($response->headers()['Set-Cookie'] ?? [] as $line) {
            $pair = explode(';', $line, 2)[0] ?? '';

            if (! str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);

            // Menards clears cookies by setting them to a sentinel; honour that
            // rather than keeping a dead value that makes us look half-logged-in.
            if ($value === '' || strtolower($value) === 'deleted') {
                unset($this->cookies[$name]);

                continue;
            }

            $this->cookies[$name] = $value;
        }
    }

    // ── Authentication ──────────────────────────────────────────────────────

    /**
     * Sign in. Throws on failure — the caller should treat that as fatal rather
     * than retryable, because a rejected credential will not fix itself.
     */
    public function login(): void
    {
        $page = $this->client()->get(self::LOGIN_PAGE);
        $this->captureCookies($page);

        if (! $page->successful()) {
            throw new RuntimeException("Login page returned HTTP {$page->status()}.");
        }

        $html = $page->body();

        // loginuuid and _csrf are rendered into the form and are session-bound;
        // posting without them (or with stale ones) is what produced the
        // "Internal Service Error 500" pages in the old browser flow.
        $loginUuid = $this->extractInput($html, 'loginuuid');
        $csrf = $this->extractInput($html, '_csrf');

        if (! $loginUuid || ! $csrf) {
            throw new RuntimeException(
                'Login form tokens missing (loginuuid=' . ($loginUuid ? 'ok' : 'missing')
                . ', _csrf=' . ($csrf ? 'ok' : 'missing') . ') — the login page did not render as expected.'
            );
        }

        $response = $this->client(follow: false)
            ->asForm()
            ->withHeaders([
                'Origin' => self::BASE,
                'Referer' => self::LOGIN_PAGE,
            ])
            ->post(self::LOGIN_POST, [
                'loginuuid' => $loginUuid,
                'guest-auth' => '',
                '_csrf' => $csrf,
                'username' => $this->email,
                'password' => $this->password,
            ]);

        $this->captureCookies($response);

        // Success is a 302 away from the login page. A 200 means the login page
        // was re-rendered — i.e. the credentials were refused.
        $location = $response->header('Location');

        if ($response->status() !== 302 || ! $location || str_contains($location, 'login.html')) {
            throw new RuntimeException(
                "Login rejected — HTTP {$response->status()}"
                . ($location ? ", redirected to {$location}" : ', no redirect')
                . '. Check the stored credentials.'
            );
        }

        $this->authenticated = true;

        // Landing on the receipt page primes the session the API calls expect
        // and hands us the API CSRF token.
        $landing = $this->client()->withHeaders(['Referer' => self::LOGIN_PAGE])->get(self::RECEIPT_PAGE);
        $this->captureCookies($landing);

        $this->apiCsrf = $this->extractMeta($landing->body(), '_csrf');

        $tokenCall = $this->client()->withHeaders(['Referer' => self::RECEIPT_PAGE])->get(self::CSRF);
        $this->captureCookies($tokenCall);

        // The dedicated endpoint answers 204 with the token in a header; the meta
        // tag on the page is the fallback when it does not.
        $this->apiCsrf = $tokenCall->header('X-CSRF-TOKEN') ?: $this->apiCsrf;

        if (! $this->apiCsrf) {
            throw new RuntimeException('Signed in, but no API CSRF token was issued.');
        }

        Log::channel('menards')->info('Menards API: signed in', [
            'cookies' => count($this->cookies),
        ]);
    }

    // ── Receipt endpoints ───────────────────────────────────────────────────

    /**
     * The cards on the account.
     *
     * @return array<int, array{tenderId: int, cardTypeName: string, maskedCardNumber: string}>
     */
    public function paymentOptions(): array
    {
        $response = $this->apiGet(self::INITIALIZE);

        return $response['paymentOptions'] ?? [];
    }

    /**
     * One page of transactions for a card. Pages are zero-based.
     *
     * @return array{total: int, transactions: array<int, array<string, mixed>>}
     */
    public function transactions(int $tenderId, int $page = 0): array
    {
        $response = $this->apiPost(self::RECEIPTS, [
            'skuUpc' => '',
            'selectedPaymentOption' => $tenderId,
            'pageNumber' => $page,
            'includeTotalAvailable' => true,
        ]);

        $data = $response['transactionData'] ?? [];

        return [
            'total' => (int) ($data['totalAvailable'] ?? 0),
            'transactions' => $data['transactions'] ?? [],
        ];
    }

    /**
     * The receipt PDF for one transaction, as raw bytes.
     *
     * The endpoint wants the whole transaction object back, plus an `id` of
     * "{store}-{workstation}-{sequence}-{transactionDate}" that the front-end
     * composes client-side and the server does not send.
     */
    public function downloadReceipt(array $transaction, int $tenderId): string
    {
        $payload = $transaction + ['id' => self::transactionId($transaction)];

        $response = $this->apiPost(self::DOWNLOAD, [
            'transactions' => [$payload],
            'selectedPaymentOption' => $tenderId,
        ]);

        $base64 = $response['receipt'] ?? null;

        if (! $base64) {
            throw new RuntimeException('Download returned no receipt payload.');
        }

        $pdf = base64_decode($base64, true);

        if ($pdf === false || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Download returned something that is not a PDF.');
        }

        return $pdf;
    }

    /** The composite id the download endpoint keys on. */
    public static function transactionId(array $t): string
    {
        return sprintf(
            '%s-%s-%s-%s',
            $t['storeNumber'] ?? '',
            $t['workstationId'] ?? '',
            $t['sequenceNumber'] ?? '',
            $t['transactionDate'] ?? ''
        );
    }

    // ── Internals ───────────────────────────────────────────────────────────

    protected function apiGet(string $url): array
    {
        $this->assertAuthenticated();

        $response = $this->client()
            ->withHeaders(['Referer' => self::RECEIPT_PAGE, 'X-CSRF-TOKEN' => $this->apiCsrf])
            ->get($url);

        $this->captureCookies($response);

        return $this->decode($response, $url);
    }

    protected function apiPost(string $url, array $body): array
    {
        $this->assertAuthenticated();

        $response = $this->client()
            ->withHeaders([
                'Origin' => self::BASE,
                'Referer' => self::RECEIPT_PAGE,
                'X-CSRF-TOKEN' => $this->apiCsrf,
            ])
            ->post($url, $body);

        $this->captureCookies($response);

        return $this->decode($response, $url);
    }

    protected function decode(\Illuminate\Http\Client\Response $response, string $url): array
    {
        if (! $response->successful()) {
            throw new RuntimeException("{$url} returned HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            // An HTML body here means the session lapsed and we were handed the
            // login page — say that, rather than "invalid JSON".
            $hint = str_contains($response->body(), '<html') ? ' (got HTML — the session expired)' : '';

            throw new RuntimeException("{$url} did not return JSON{$hint}.");
        }

        return $json;
    }

    protected function assertAuthenticated(): void
    {
        if (! $this->authenticated) {
            throw new RuntimeException('Call login() before using the receipt endpoints.');
        }
    }

    protected function extractInput(string $html, string $name): ?string
    {
        return preg_match(
            '/<input[^>]*name=["\']' . preg_quote($name, '/') . '["\'][^>]*value=["\']([^"\']*)["\']/i',
            $html,
            $m
        ) ? $m[1] : null;
    }

    protected function extractMeta(string $html, string $name): ?string
    {
        return preg_match(
            '/<meta[^>]*name=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']*)["\']/i',
            $html,
            $m
        ) ? $m[1] : null;
    }
}
