<?php

namespace App\Support\Ewccv;

use App\Services\NylasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * EWCCV's sign-in link (the magic link its login page emails), as the
 * server obtains it: the scraper asks the login page for one, the mail lands
 * in the certificates mailbox, and the link is cached for its lifetime. The
 * scheduled commands sign in with it; the browser extension asks for it
 * too, to sign its own tab in when it finds it logged out, so a pinned
 * ewccv.com tab keeps supplying credentials without anyone signing in.
 */
class SignInLink
{
    /** The link's JWT lasts 12 hours; keep a margin. */
    public const MAX_AGE_MINUTES = 600;

    public static function file(): string
    {
        return storage_path('files/_temp_ewccv/.last_login_url.json');
    }

    /** The cached link when it is younger than $maxAgeMinutes. */
    public static function cached(int $maxAgeMinutes = self::MAX_AGE_MINUTES): ?string
    {
        $file = self::file();
        if (! file_exists($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (empty($data['url']) || empty($data['cached_at'])) {
            return null;
        }
        if (Carbon::parse($data['cached_at'])->diffInMinutes(now()) > $maxAgeMinutes) {
            return null;
        }

        return (string) $data['url'];
    }

    public static function remember(string $url): void
    {
        $dir = dirname(self::file());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(self::file(), json_encode(['url' => $url, 'cached_at' => now()->toIso8601String()]));
    }

    /**
     * Ask EWCCV's login page for a link and read it from the mailbox. Takes
     * a minute or two; returns null when the mail never came.
     */
    public static function request(NylasService $nylas): ?string
    {
        $email = (string) config('services.ewccv.login_email');
        $outputDir = dirname(self::file());
        $requestedAfter = now()->subMinute();

        $configFile = tempnam(sys_get_temp_dir(), 'ewccv_mail_');
        file_put_contents($configFile, json_encode([
            'mode' => 'request-email',
            'loginEmail' => $email,
            'outputDir' => $outputDir,
            'screenshotDir' => public_path('EWCCV'),
            'headless' => true,
            'delayMs' => 3000,
            'captchaApiKey' => '',
            'twoCaptchaKey' => '',
        ]));

        // EWCCV drops sign-in emails requested via the residential proxy.
        $result = Process::timeout(180)
            ->env(['EWCCV_PROXY_HOST' => '', 'EWCCV_PROXY_PORT' => '', 'EWCCV_PROXY_USER' => '', 'EWCCV_PROXY_PASS' => ''])
            ->run(sprintf('%s %s %s', trim((string) shell_exec('which node')) ?: 'node', base_path('scripts/ewccv-scraper.cjs'), $configFile));
        @unlink($configFile);

        $sent = json_decode(trim($result->output()), true);
        if (empty($sent['emailSent'])) {
            Log::warning('EWCCV sign-in link: the login page did not confirm the email', ['output' => mb_substr((string) $result->output(), 0, 300)]);

            return null;
        }

        $grantId = (string) config('nylas.certificates_grant_id');
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            sleep(15);
            try {
                $messages = $nylas->getMessages($grantId, ['limit' => 15, 'received_after' => $requestedAfter->timestamp]);
                foreach ($messages['data'] ?? [] as $message) {
                    if (stripos((string) ($message['subject'] ?? ''), 'Sign in to Workers Compensation') === false) {
                        continue;
                    }
                    if (preg_match('#https://www\.ewccv\.com/cvs/login/\?t=[A-Za-z0-9._-]+#', (string) ($message['body'] ?? ''), $m)) {
                        self::remember($m[0]);
                        Log::info('EWCCV sign-in link obtained', ['attempt' => $attempt]);

                        return $m[0];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('EWCCV sign-in link: mailbox poll failed', ['error' => $e->getMessage()]);
            }
        }

        Log::warning('EWCCV sign-in link: timed out waiting for the email');

        return null;
    }
}
