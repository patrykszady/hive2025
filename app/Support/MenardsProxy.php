<?php

namespace App\Support;

/**
 * The residential proxy the server's Menards Chrome exits through.
 *
 * Imperva scores the droplet's own address as a bot and walls it almost
 * daily; a home address carrying a session a person established is served.
 * The 2captcha pool rotates an exit every two hours at most, and one fixed
 * session id in the username keeps the browser and every captcha solve on
 * whichever exit is current, so a solved token is never tied to a different
 * IP than the page that asked for it.
 */
final class MenardsProxy
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
    ) {}

    /** Null while MENARDS_PROXY is off, and until CAPTCHA_PROXY_HOST, _USERNAME and _PASSWORD are all set. */
    public static function fromConfig(): ?self
    {
        if (! config('services.menards.proxy_enabled', true)) {
            return null;
        }

        $endpoint = trim((string) config('services.menards.proxy_host', ''));
        $username = trim((string) config('services.menards.proxy_username', ''));
        $password = (string) config('services.menards.proxy_password', '');
        $region = trim((string) config('services.menards.proxy_region', ''));
        $session = trim((string) config('services.menards.proxy_session', ''));

        if ($endpoint === '' || $username === '' || $password === '') {
            return null;
        }

        [$host, $port] = array_pad(explode(':', $endpoint, 2), 2, '');

        // The pool reads its options off the username: `-region-us` pins the
        // exit country, `-session-<id>` keeps one exit for up to two hours.
        if ($region !== '') {
            $username .= "-region-{$region}";
        }

        if ($session !== '') {
            $username .= "-session-{$session}";
        }

        return new self($host, (int) ($port !== '' ? $port : 3128), $username, $password);
    }

    /**
     * `--proxy-server` plus the hosts that must stay direct: Hive itself,
     * Google's extension update servers, 2captcha, and loopback. Shell-escaped,
     * ready to splice into the Chrome command line.
     */
    public function chromeArguments(): string
    {
        $bypass = array_values(array_unique(array_filter([
            '127.0.0.1',
            'localhost',
            parse_url((string) config('app.url'), PHP_URL_HOST),
            '*.google.com',
            '*.googleapis.com',
            '*.gstatic.com',
            '*.googleusercontent.com',
            '2captcha.com',
            '*.2captcha.com',
        ])));

        return sprintf(
            '--proxy-server=%s --proxy-bypass-list=%s',
            escapeshellarg("http://{$this->host}:{$this->port}"),
            escapeshellarg(implode(';', $bypass)),
        );
    }

    /**
     * What the receipt extension needs to answer the proxy's 407s on Chrome's
     * behalf: `--proxy-server` takes no credentials.
     *
     * @return array{host: string, port: int, username: string, password: string}
     */
    public function forExtension(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    /** For status output and the solver plugin's proxy field: never the password. */
    public function label(): string
    {
        return "{$this->host}:{$this->port} as {$this->username}";
    }
}
