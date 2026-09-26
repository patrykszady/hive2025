<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transparent relay of /admin/* to the ss-systems central admin.
 *
 * This app has no admin of its own — the whole editing experience (login,
 * Livewire components, everything under /admin) lives on ss-systems and is
 * served here byte-for-byte. The browser only ever talks to THIS origin;
 * this controller is the only thing that knows ss-systems exists.
 *
 * Ported from dawnsellshomes' AdminProxyController (itself ported from
 * gsc's — the working contract: see
 * /home/patryk/web/gsc/app/Http/Controllers/AdminProxyController.php and
 * ss-systems/CLAUDE.md's proxy architecture section). Hive is single-tenant
 * from ss-systems' point of view (one connected site, key 'hive'), so like
 * Dawn there is no per-request Site overlay to bind before rendering the
 * down page — there is only ever one brand here.
 *
 * admin_prefix is the identical literal path segment ('admin') on both
 * apps, so the path is never rewritten — only relayed. Headers are relayed
 * from an allowlist (never wholesale), with a handful of authoritative
 * ones (X-Forwarded-*, X-Site-Key, X-Ss-Service-Secret) always set fresh
 * from this request/config rather than copied from the client, since those
 * are exactly what ss-systems' VerifyAdminProxyOrigin middleware trusts to
 * decide the request came through a real proxy.
 */
class AdminProxyController extends Controller
{
    /** Inbound headers relayed to ss-systems, verbatim. Never the full set. */
    protected const ALLOWED_INBOUND_HEADERS = [
        'accept',
        'accept-language',
        'content-type',
        'cookie',
        'x-csrf-token',
        'x-livewire',
        'user-agent',
        'referer',
    ];

    /** Response headers never copied back: hop-by-hop, or recomputed by this leg. */
    protected const STRIPPED_RESPONSE_HEADERS = [
        'connection',
        'keep-alive',
        'transfer-encoding',
        'upgrade',
        'content-length',
        'content-encoding',
        'set-cookie',
    ];

    /** Set for 60s after an upstream failure: serve the down page without another outbound call. */
    protected const DOWN_CACHE_KEY = 'admin-proxy-down';

    public function handle(Request $request, string $path = ''): Response
    {
        // /admin is the most-probed path on the internet: never open an
        // outbound call when the relay can't succeed anyway (no service
        // secret configured yet) or just failed (breaker open).
        if ((string) config('services.ss.service_secret') === '' || Cache::get(self::DOWN_CACHE_KEY)) {
            return $this->downResponse();
        }

        $target = $this->targetUrl($request, $path);
        $contentType = (string) $request->headers->get('content-type');
        $isMultipart = str_starts_with(strtolower($contentType), 'multipart/form-data');

        $client = Http::withoutRedirecting()
            ->timeout((int) config('services.ss.timeout', 30))
            ->connectTimeout((int) config('services.ss.connect_timeout', 5))
            ->withHeaders($this->outboundHeaders($request, $isMultipart));

        try {
            $upstream = $isMultipart
                ? $this->sendMultipart($client, $request, $target)
                : $this->sendStandard($client, $request, $target, $contentType);
        } catch (ConnectionException $e) {
            Log::warning('Admin proxy: could not reach ss-systems', [
                'target' => $target,
                'message' => $e->getMessage(),
            ]);
            Cache::put(self::DOWN_CACHE_KEY, true, now()->addSeconds(60));

            return $this->downResponse();
        }

        return $this->relayResponse($upstream, $request);
    }

    protected function downResponse(): Response
    {
        return response()
            ->view('errors.admin-proxy-down', [], 502)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    protected function targetUrl(Request $request, string $path): string
    {
        $base = rtrim((string) config('services.ss.url'), '/');
        $prefix = trim((string) config('services.ss.admin_prefix'), '/');
        $target = $base.'/'.$prefix.'/'.ltrim($path, '/');

        $query = $request->getQueryString();

        return $query ? $target.'?'.$query : $target;
    }

    /**
     * @return array<string, string>
     */
    protected function outboundHeaders(Request $request, bool $isMultipart): array
    {
        $headers = [];

        foreach (self::ALLOWED_INBOUND_HEADERS as $name) {
            // Multipart bodies are rebuilt from scratch with a fresh boundary
            // (see sendMultipart) — the inbound Content-Type's boundary no
            // longer matches, and Guzzle only sets the correct one itself
            // when this header is left unset on the outbound request.
            if ($isMultipart && $name === 'content-type') {
                continue;
            }

            $value = $request->headers->get($name);
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        // Authoritative — never derived from anything the client sent, so a
        // browser can never forge the identity ss-systems trusts these for.
        //
        // getHttpHost(), not getHost(): Symfony's getHost() strips the port,
        // but X-Forwarded-Host is conventionally the original Host header
        // verbatim (host[:port]) — and ss-systems' own trustProxies is
        // configured to read a port back out of it. Both apps run on
        // non-default ports in local dev, so getHost() alone would silently
        // drop the port and every relayed redirect would land on the wrong
        // one.
        $headers['X-Forwarded-Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Port'] = (string) $request->getPort();
        $headers['X-Forwarded-Proto'] = $request->getScheme();
        $headers['X-Forwarded-For'] = (string) $request->ip();
        $headers['X-Site-Key'] = (string) config('services.ss.site_key');
        $headers['X-Ss-Service-Secret'] = (string) config('services.ss.service_secret');

        return $headers;
    }

    protected function sendStandard(PendingRequest $client, Request $request, string $target, string $contentType): \Illuminate\Http\Client\Response
    {
        $body = $request->getContent();

        if ($body !== '') {
            $client->withBody($body, $contentType ?: 'application/octet-stream');
        }

        return $client->send($request->method(), $target);
    }

    /**
     * php://input is empty for multipart/form-data requests (PHP consumes it
     * into $_POST/$_FILES), so the outbound multipart body is rebuilt from
     * the parsed request rather than relayed as raw bytes.
     */
    protected function sendMultipart(PendingRequest $client, Request $request, string $target): \Illuminate\Http\Client\Response
    {
        $client->asMultipart();

        $fileKeys = [];

        foreach ($this->flatten($request->allFiles()) as $name => $file) {
            $fileKeys[explode('[', $name, 2)[0]] = true;

            $client->attach(
                $name,
                fopen($file->getRealPath(), 'r'),
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getClientMimeType()],
            );
        }

        $fields = $request->except(array_keys($fileKeys));
        $parts = [];

        foreach ($this->flatten($fields) as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => (string) $value];
        }

        return $client->send($request->method(), $target, ['multipart' => $parts]);
    }

    /**
     * Flatten a nested array (as produced by $request->allFiles() /
     * $request->except()) into bracket-notation names: ['files' => [0 =>
     * $file]] becomes ['files[0]' => $file].
     *
     * @return array<string, mixed>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                $flat += $this->flatten($value, $name);
            } else {
                $flat[$name] = $value;
            }
        }

        return $flat;
    }

    protected function relayResponse(\Illuminate\Http\Client\Response $upstream, Request $request): Response
    {
        $response = response($upstream->body(), $upstream->status());

        foreach ($upstream->headers() as $name => $values) {
            if (in_array(strtolower($name), self::STRIPPED_RESPONSE_HEADERS, true)) {
                continue;
            }

            foreach ((array) $values as $value) {
                $response->headers->set($name, $value, false);
            }
        }

        // A naive header set collapses repeated Set-Cookie lines into one —
        // this is load-bearing for login, which sets both the session cookie
        // and the CSRF cookie in the same response.
        foreach ($upstream->toPsrResponse()->getHeader('Set-Cookie') as $raw) {
            $response->headers->setCookie(Cookie::fromString($raw));
        }

        if ($response->isRedirection() && $response->headers->has('Location')) {
            $response->headers->set('Location', $this->rewriteLocation($response->headers->get('Location'), $request));
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Rewrite ONLY an absolute Location that points back at ss-systems'
     * own origin, so a redirect lands on this app's origin instead — the
     * browser never has a reason to know ss-systems exists.
     */
    protected function rewriteLocation(string $location, Request $request): string
    {
        $ssOrigin = rtrim((string) config('services.ss.url'), '/');

        if ($ssOrigin !== '' && str_starts_with($location, $ssOrigin)) {
            return $request->getSchemeAndHttpHost().substr($location, strlen($ssOrigin));
        }

        return $location;
    }
}
