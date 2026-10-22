<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use TwoCaptcha\TwoCaptcha;

/**
 * Buys an hCaptcha token for the Imperva wall in front of menards.com.
 *
 * WHY THIS EXISTS, AND WHAT IT IS NOT
 *
 * The server-side Chrome cannot sign in unattended: a brand-new profile is
 * challenged on its very first request (verified 2026-08-23 — a wiped profile
 * hit the wall immediately), so this is not accumulated reputation, it is
 * Imperva scoring a headed Chrome driven on Xvfb from a datacenter IP. With
 * "no human interaction" as the requirement, a solved token is the only
 * remaining route for THIS browser.
 *
 * READ THIS BEFORE TRUSTING IT
 *
 * The same idea was already tried here with the Puppeteer scraper and did NOT
 * work — not because the solve failed, but because it succeeded and Imperva
 * refused the session anyway. MenardsSessionController records the evidence:
 * "hCaptcha solved — token length: 1034" followed by "Imperva wall persisted
 * after 3 challenge attempts". Imperva scores the whole browser, not the
 * token.
 *
 * What is genuinely untested is this browser: a real google-chrome-stable with
 * a persistent profile and no CDP port, which is a different fingerprint from
 * the Puppeteer/stealth setup that failed. That is the entire bet. If the wall
 * persists after a valid token, the answer is not more solving — it is that
 * this environment cannot pass, and the session bridge is the design that
 * already accounts for that.
 *
 * Cost is real (per solve), so callers must not loop.
 */
class MenardsCaptchaSolver
{
    /** Imperva's challenge is a normal hCaptcha widget, not enterprise-with-rqdata. */
    protected const SOFT_ID = 0;

    /** How long one NoneCap request blocks for its answer (their maximum). */
    protected const NONECAP_WAIT_SECONDS = 90;

    public function configured(): bool
    {
        return $this->noneCapKey() !== '' || $this->apiKey() !== '';
    }

    protected function apiKey(): string
    {
        return trim((string) config('services.menards.twocaptcha_key'));
    }

    protected function noneCapKey(): string
    {
        return trim((string) config('services.menards.nonecap_key'));
    }

    /**
     * @param  string|null  $userAgent  the browser's own user agent, so the token is earned as that browser
     * @return array{ok: bool, token?: string, error?: string, seconds?: float}
     */
    public function solve(string $siteKey, string $pageUrl, ?string $userAgent = null): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'Neither NONECAP_API_KEY nor TWOCAPTCHA_API_KEY is set on this server.'];
        }

        if ($siteKey === '' || $pageUrl === '') {
            return ['ok' => false, 'error' => 'A sitekey and a page url are both required.'];
        }

        if ($this->noneCapKey() !== '') {
            return $this->solveWithNoneCap($siteKey, $pageUrl, $userAgent);
        }

        $started = microtime(true);

        try {
            // Options through the constructor: defaultTimeout and
            // pollingInterval are PRIVATE in the SDK (v1.2), so assigning them
            // as properties throws "Cannot access private property" — which is
            // exactly how the first live solve died.
            //
            // Generous but bounded: a human-farm solve is typically 15-40s, and
            // an unbounded wait would hold the login lock long enough for the
            // next hourly ensure to pile in behind it.
            $solver = new TwoCaptcha([
                'apiKey' => $this->apiKey(),
                'defaultTimeout' => 180,
                'pollingInterval' => 5,
            ]);

            $result = $solver->hcaptcha([
                'sitekey' => $siteKey,
                'url' => $pageUrl,
            ]);

            $token = trim((string) ($result->code ?? ''));
            $seconds = round(microtime(true) - $started, 1);

            if ($token === '') {
                return ['ok' => false, 'error' => '2captcha returned an empty token.', 'seconds' => $seconds];
            }

            Log::channel('menards')->info('Menards captcha: token purchased', [
                'length' => strlen($token),
                'seconds' => $seconds,
            ]);

            return ['ok' => true, 'token' => $token, 'seconds' => $seconds];
        } catch (\Throwable $e) {
            $seconds = round(microtime(true) - $started, 1);

            // Report the provider's own words. "ERROR_ZERO_BALANCE" and
            // "ERROR_CAPTCHA_UNSOLVABLE" are entirely different problems and
            // collapsing them into "solve failed" is how the last version of
            // this became undebuggable.
            Log::channel('menards')->error('Menards captcha: solve failed', [
                'error' => $e->getMessage(),
                'seconds' => $seconds,
            ]);

            return ['ok' => false, 'error' => $e->getMessage(), 'seconds' => $seconds];
        }
    }

    /**
     * NoneCap solves through OUR proxy with OUR browser's user agent: the
     * 2captcha tokens of September were earned on a worker's IP and Imperva
     * refused every one ("Submit the token from the same network address the
     * solve used" — NoneCap's own docs). Whether Imperva also wants the same
     * browser is what this trial finds out.
     *
     * @return array{ok: bool, token?: string, error?: string, seconds?: float}
     */
    protected function solveWithNoneCap(string $siteKey, string $pageUrl, ?string $userAgent): array
    {
        $started = microtime(true);
        $proxy = \App\Support\MenardsProxy::fromConfig();

        $body = array_filter([
            'type' => 'hcaptcha',
            'sitekey' => $siteKey,
            'url' => $pageUrl,
            'user_agent' => $userAgent ? trim($userAgent) : null,
            'proxy' => $proxy ? sprintf('http://%s:%s@%s:%d', rawurlencode($proxy->username), rawurlencode($proxy->password), $proxy->host, $proxy->port) : null,
        ]);

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($this->noneCapKey())
                ->acceptJson()
                ->timeout(self::NONECAP_WAIT_SECONDS + 15)
                ->post('https://api.nonecap.com/v1/solves?wait='.self::NONECAP_WAIT_SECONDS, $body);
        } catch (\Throwable $e) {
            $seconds = round(microtime(true) - $started, 1);
            Log::channel('menards')->error('Menards captcha: NoneCap request failed', ['error' => $e->getMessage(), 'seconds' => $seconds]);

            return ['ok' => false, 'error' => $e->getMessage(), 'seconds' => $seconds];
        }

        $seconds = round(microtime(true) - $started, 1);
        $solve = (array) $response->json();
        $token = trim((string) ($solve['token'] ?? ''));

        // Their own words, never a summary: a refused key, an empty balance
        // and an unsolvable widget are different problems.
        $context = [
            'provider' => 'nonecap',
            'http' => $response->status(),
            'status' => $solve['status'] ?? null,
            'id' => $solve['id'] ?? null,
            'credits' => $solve['credits_charged'] ?? null,
            'resolve_ms' => $solve['resolve_ms'] ?? null,
            'through_proxy' => $proxy?->host,
            'user_agent_matched' => $userAgent !== null && ($solve['user_agent'] ?? null) === trim($userAgent),
            'error' => $response->successful() ? null : mb_substr($response->body(), 0, 300),
            'seconds' => $seconds,
        ];

        if (! $response->successful() || ($solve['status'] ?? null) !== 'solved' || $token === '') {
            Log::channel('menards')->error('Menards captcha: NoneCap did not solve', $context);

            return ['ok' => false, 'error' => 'NoneCap: '.($solve['status'] ?? 'HTTP '.$response->status()).($response->successful() ? '' : ' '.mb_substr($response->body(), 0, 200)), 'seconds' => $seconds];
        }

        Log::channel('menards')->info('Menards captcha: token purchased', $context + ['length' => strlen($token)]);

        return ['ok' => true, 'token' => $token, 'seconds' => $seconds];
    }
}
