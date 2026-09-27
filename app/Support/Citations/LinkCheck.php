<?php

namespace App\Support\Citations;

use Illuminate\Support\Facades\Http;

/**
 * Ported verbatim from gsc's/dawnsellshomes' app/Support/Citations/
 * LinkCheck.php — no site-specific logic at all. Fetch a business profile
 * like a visitor and look for a link to our site. Used by
 * `citations:check-links`.
 */
class LinkCheck
{
    /**
     * @return array{status: int, links_to_us: ?int, nofollow: ?int, note: ?string}
     *         links_to_us: 1 linked, 0 the page shows the business but no link,
     *         null could not be verified (blocked, error, or the page did not show the business)
     */
    public static function run(string $url, string $ourDomain, array $businessNames): array
    {
        $status = 0;
        $linked = null;
        $nofollow = null;
        $note = null;
        try {
            $resp = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(15)->get($url);
            $status = $resp->status();
            if ($status === 200) {
                $html = (string) $resp->body();
                $showsBusiness = collect($businessNames)->contains(fn ($n) => $n !== '' && stripos($html, (string) $n) !== false);
                if (preg_match('#<a\b[^>]*href=["\']?(?:https?:)?//(?:www\.)?'.preg_quote($ourDomain, '#').'(?:[/?\#"\'\s>]|$)[^>]*>#i', $html, $m)) {
                    $linked = 1;
                    $nofollow = preg_match('/rel=["\'][^"\']*nofollow/i', $m[0]) ? 1 : 0;
                } elseif ($showsBusiness) {
                    $linked = 0;
                } else {
                    $note = 'The page loaded but did not show the business (bot wall or consent page).';
                }
            } else {
                $note = "HTTP {$status}.";
            }
        } catch (\Throwable $e) {
            $note = 'Fetch failed: '.mb_substr($e->getMessage(), 0, 120);
        }

        return ['status' => $status, 'links_to_us' => $linked, 'nofollow' => $nofollow, 'note' => $note];
    }
}
