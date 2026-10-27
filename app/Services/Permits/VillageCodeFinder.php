<?php

namespace App\Services\Permits;

use App\Models\VillageCodes;
use App\Support\Permits\Chrome;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\Process\Process;

/**
 * Finds the building codes a village or city has adopted, on its own
 * website, and keeps them current (Patryk, 2026-10-26: "we need to do a
 * better job finding village codes … lakezurich.org/202/Residential-Codes").
 *
 * Two steps, so the answer is always grounded in a page we fetched
 * ourselves: (1) locate — a web search (OpenAI Responses API, web_search
 * tool) for the town's official building-codes page; (2) read — fetch that
 * page (HTML, or a PDF handout through Ghostscript; headless Chrome when a
 * plain fetch is refused) and have the model list the adopted codes from
 * its text only, in the cover letter's own "Name, YYYY Edition" form.
 * refresh() re-reads a town's recorded page and notes what changed
 * (permits:refresh-village-codes, monthly).
 */
class VillageCodeFinder
{
    public const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 HiveContractors/1.0 (+https://hive.contractors)';

    protected const LOCATE_PROMPT = <<<'PROMPT'
Find the page on the official municipal government website of %s, %s (the village, city or town itself — not the county, the state, a code publisher or a commercial site) that lists the building codes the municipality has adopted: International Residential Code, International Building Code, National Electrical Code, Illinois Plumbing Code and the like, with edition years. The building department / community development / permits section usually has it, often titled "Building Codes", "Adopted Codes" or "Residential Codes"; a PDF handout on the same site is acceptable. When the town keeps separate residential and commercial lists, give the RESIDENTIAL page first — these are permits for houses.
Reply with JSON only, no prose: {"urls": ["..."]} — up to 3 URLs, the best first, or an empty list if the town publishes no such page.
PROMPT;

    protected const EXTRACT_SYSTEM = <<<'PROMPT'
You read the text of a municipal web page and report which building codes that municipality has adopted. Work from the page text only — never from what you believe a town has adopted.
Rules:
- found is true only when THIS page states the codes adopted by the named town (not another town, not a county or the state in general, not a vendor's list).
- codes: one entry per adopted code that governs a residential building permit, in the order the page gives them, each written "<Formal code name>, <YYYY> Edition" — for example "International Residential Code, 2018 Edition", "National Electrical Code, 2017 Edition" (write "National Electrical Code" even when the page says "National Electric Code" or "NEC"), "Illinois Plumbing Code, 2014 Edition", "Illinois Energy Conservation Code, 2024 Edition", "Illinois Accessibility Code, 2018 Edition", "International Property Maintenance Code, 2018 Edition". When the page gives no year, write the name alone. Keep the building, residential, existing building, mechanical, fuel gas, fire, property maintenance, energy conservation, electrical, plumbing, accessibility, swimming pool and Life Safety codes. Leave out elevator and escalator codes, referenced standards (ASME, ANSI, ASCE, NFPA other than the Life Safety Code), the ADA Standards for Accessible Design, local ordinances, zoning and land-development codes, stormwater and cross-connection ordinances, fee schedules and the municipal code itself.
- note: an effective-date remark on the page, such as "For permits issued after 1/1/2026", or null.
Return JSON only, matching the schema.
PROMPT;

    /** The HTML of the last page fetched, for the residential-link check. */
    protected ?string $lastHtml = null;

    /** The row for a town, created pending when there is none. */
    public function pendingRow(string $town, string $state = 'IL'): VillageCodes
    {
        $name = VillageCodes::normalizeTown($town);

        return VillageCodes::query()->named($name, $state)->first()
            ?? VillageCodes::query()->create(['town' => $name, 'state' => $state, 'status' => VillageCodes::STATUS_PENDING, 'found_by' => VillageCodes::FOUND_BY_AI]);
    }

    /** Locate the town's page and read it; the row ends found, not_found or failed. */
    public function find(string $town, string $state = 'IL'): VillageCodes
    {
        $row = $this->pendingRow($town, $state);

        try {
            foreach ($this->locate($row->town, $row->state) as $url) {
                if ($best = $this->readBest($row->town, $url)) {
                    return $this->record($row, $best[0], $best[1]);
                }
            }

            $row->fill([
                'status' => $row->isFound() ? VillageCodes::STATUS_FOUND : VillageCodes::STATUS_NOT_FOUND,
                'last_error' => $row->isFound() ? 'No page listing the codes was found this time; the earlier list stands.' : null,
                'checked_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);
            $row->fill([
                'status' => $row->isFound() ? VillageCodes::STATUS_FOUND : VillageCodes::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'checked_at' => now(),
            ])->save();
        }

        return $row;
    }

    /** Re-read the page on file; when it no longer lists the codes, look for the page again, and keep the old list if nothing better turns up. */
    public function refresh(VillageCodes $row): VillageCodes
    {
        if (! $row->source_url) {
            return $this->find($row->town, $row->state);
        }

        try {
            if ($best = $this->readBest($row->town, $row->source_url)) {
                return $this->record($row, $best[0], $best[1]);
            }

            foreach ($this->locate($row->town, $row->state) as $url) {
                if ($url === $row->source_url) {
                    continue;
                }
                if ($best = $this->readBest($row->town, $url)) {
                    return $this->record($row, $best[0], $best[1]);
                }
            }

            $row->fill(['last_error' => 'The page on file no longer lists the codes and no other page was found; the earlier list stands.', 'checked_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
            $row->fill(['last_error' => mb_substr($e->getMessage(), 0, 1000), 'checked_at' => now()])->save();
        }

        return $row;
    }

    /**
     * Candidate pages, best first — a web search through the model.
     *
     * @return list<string>
     */
    public function locate(string $town, string $state = 'IL'): array
    {
        $apiKey = (string) config('services.openai.api_key') ?: throw new RuntimeException('OpenAI API key missing (services.openai.api_key)');

        $response = Http::withToken($apiKey)
            ->timeout(90)
            ->post('https://api.openai.com/v1/responses', [
                'model' => (string) config('services.openai.village_codes_model', 'gpt-4.1'),
                'tools' => [['type' => 'web_search_preview']],
                'input' => sprintf(self::LOCATE_PROMPT, $town, $state),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('village_codes_locate_http_'.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }

        $text = '';
        foreach (array_reverse($response->json('output', [])) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && filled($content['text'] ?? null)) {
                    $text = (string) $content['text'];
                    break 2;
                }
            }
        }

        $urls = [];
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $urls = (array) data_get(json_decode($m[0], true), 'urls', []);
        }
        if ($urls === [] && preg_match_all('~https?://[^\s"\'<>)\]]+~', $text, $m)) {
            $urls = $m[0];
        }

        $clean = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if (preg_match('~^https?://~i', $url) && ! in_array($url, $clean, true)) {
                $clean[] = $url;
            }
        }

        // Houses, not storefronts: a residential list, when the town keeps two, goes first.
        usort($clean, fn (string $a, string $b) => (int) ! Str::contains(Str::lower($a), 'residential') <=> (int) ! Str::contains(Str::lower($b), 'residential'));

        return array_slice($clean, 0, 3);
    }

    /**
     * Fetch a page and have the model list the adopted codes from its text.
     *
     * @return array{found: bool, codes: list<string>, note: ?string}|null
     */
    public function read(string $town, string $url): ?array
    {
        $text = $this->pageText($url);

        if ($text === null || mb_strlen($text) < 80) {
            return null;
        }

        return $this->extract($town, $url, $text);
    }

    /**
     * Read a page and, when it is not itself the residential list but links
     * to one on the same site (CivicPlus sidebars link "Commercial Codes" and
     * "Residential Codes" to each other), the residential page instead —
     * Lake Zurich's two lists differ. Null when neither lists the codes.
     *
     * @return array{0: string, 1: array{found: bool, codes: list<string>, note: ?string}}|null
     */
    protected function readBest(string $town, string $url): ?array
    {
        $read = $this->read($town, $url);
        if (! $read || ! $read['found'] || $read['codes'] === []) {
            return null;
        }

        if ($sibling = $this->residentialSibling($url, $this->lastHtml)) {
            $alt = $this->read($town, $sibling);
            if ($alt && $alt['found'] && $alt['codes'] !== []) {
                return [$sibling, $alt];
            }
        }

        return [$url, $read];
    }

    /** A same-site link to the residential code list, on a page that is not it. */
    public function residentialSibling(string $url, ?string $html): ?string
    {
        if (! $html || preg_match('/residential/i', (string) parse_url($url, PHP_URL_PATH))) {
            return null;
        }
        if (! preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        foreach ($matches as [, $href, $label]) {
            $words = Str::squish(strip_tags($label)).' '.$href;
            if (! preg_match('/residential/i', $words) || ! preg_match('/code/i', $words)) {
                continue;
            }
            $absolute = $this->absoluteUrl(html_entity_decode($href), $url);
            if ($absolute && parse_url($absolute, PHP_URL_HOST) === $host && $absolute !== $url) {
                return $absolute;
            }
        }

        return null;
    }

    protected function absoluteUrl(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) {
            return null;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $dir = preg_replace('~/[^/]*$~', '/', $parts['path'] ?? '/') ?: '/';

        return $origin.$dir.$href;
    }

    /** @param  array{found: bool, codes: list<string>, note: ?string}  $read */
    protected function record(VillageCodes $row, string $url, array $read): VillageCodes
    {
        $codes = array_values($read['codes']);
        $changed = $row->isFound() && $row->codes !== $codes;

        $row->fill([
            'status' => VillageCodes::STATUS_FOUND,
            'found_by' => ($row->found_by === VillageCodes::FOUND_BY_HAND && ! $changed) ? VillageCodes::FOUND_BY_HAND : VillageCodes::FOUND_BY_AI,
            'source_url' => $url,
            'codes' => $codes,
            'note' => $read['note'] ? mb_substr($read['note'], 0, 300) : null,
            'previous_codes' => $changed ? $row->codes : $row->previous_codes,
            'changed_at' => $changed ? now() : $row->changed_at,
            'checked_at' => now(),
            'last_error' => null,
        ])->save();

        if ($changed) {
            Log::info('Village building codes changed.', ['town' => $row->town, 'source' => $url, 'before' => $row->previous_codes, 'after' => $codes]);
        }

        return $row;
    }

    protected function pageText(string $url): ?string
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/pdf;q=0.9,*/*;q=0.8'])
            ->timeout(30)
            ->retry(1, 1000, throw: false)
            ->get($url);

        $body = $response->successful() ? (string) $response->body() : '';
        $isPdf = str_contains((string) $response->header('Content-Type'), 'pdf') || str_starts_with($body, '%PDF');

        if ($isPdf) {
            $this->lastHtml = null;

            return $this->pdfText($body);
        }

        if (! $response->successful() || mb_strlen(trim(strip_tags($body))) < 400) {
            $body = $this->chromeHtml($url) ?? $body;
        }
        $this->lastHtml = $body !== '' ? $body : null;

        return $body !== '' ? self::htmlToText($body) : null;
    }

    /** The readable text of a page: no scripts, styles, menus or footers; one line per block. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('~<(script|style|noscript|svg|nav|header|footer|form)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>|</(p|div|li|tr|h[1-6]|td|th|section|article)>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;

        return mb_substr(trim($text), 0, 30000);
    }

    protected function pdfText(string $pdf): ?string
    {
        if ($pdf === '') {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'village-codes-');
        file_put_contents($path, $pdf);

        try {
            $process = new Process([(string) config('services.ghostscript.binary', 'gs'), '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=txtwrite', '-sOutputFile=-', $path]);
            $process->setTimeout(60)->run();
            $text = trim(preg_replace('/[ \t]+/', ' ', $process->getOutput()) ?? '');

            return $text !== '' ? mb_substr($text, 0, 30000) : null;
        } finally {
            @unlink($path);
        }
    }

    /** A page that refuses a plain fetch, or builds itself in the browser. */
    protected function chromeHtml(string $url): ?string
    {
        try {
            return Chrome::configure(Browsershot::url($url))
                ->userAgent(self::USER_AGENT)
                ->timeout(60)
                ->waitUntilNetworkIdle()
                ->bodyHtml();
        } catch (\Throwable $e) {
            Log::info('Village codes page could not be opened in Chrome.', ['url' => $url, 'error' => Str::limit($e->getMessage(), 200)]);

            return null;
        }
    }

    /** @return array{found: bool, codes: list<string>, note: ?string} */
    protected function extract(string $town, string $url, string $text): array
    {
        $apiKey = (string) config('services.openai.api_key') ?: throw new RuntimeException('OpenAI API key missing (services.openai.api_key)');

        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->retry(2, 3000, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => (string) config('services.openai.village_codes_model', 'gpt-4.1'),
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => self::EXTRACT_SYSTEM],
                    ['role' => 'user', 'content' => "Town: {$town}\nPage: {$url}\n\n---\n{$text}"],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'village_building_codes',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['found', 'town', 'codes', 'note'],
                            'properties' => [
                                'found' => ['type' => 'boolean'],
                                'town' => ['type' => ['string', 'null']],
                                'codes' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'note' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('village_codes_read_http_'.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }

        $parsed = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);
        if (! is_array($parsed)) {
            throw new RuntimeException('village_codes_read_invalid_json');
        }

        $codes = [];
        foreach ((array) ($parsed['codes'] ?? []) as $line) {
            $line = Str::squish((string) $line);
            if ($line !== '' && ! in_array($line, $codes, true)) {
                $codes[] = mb_substr($line, 0, 160);
            }
        }

        return [
            'found' => (bool) ($parsed['found'] ?? false),
            'codes' => $codes,
            'note' => filled($parsed['note'] ?? null) ? Str::squish((string) $parsed['note']) : null,
        ];
    }
}
