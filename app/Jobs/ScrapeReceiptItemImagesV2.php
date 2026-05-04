<?php

namespace App\Jobs;

use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * V2 Receipt Item Image Scraper — simplified 3-phase pipeline.
 *
 * Replaces the legacy 8-phase ScrapeReceiptItemImages with a clean,
 * fast, robust implementation:
 *
 *   PHASE A — RESOLVE: SerpAPI Google Shopping (1 query per item) →
 *            use inline product image+link, or fetch top organic result
 *            and extract JSON-LD / meta tags.
 *   PHASE B — FALLBACK: Browsershot+stealth for Cloudflare/SPA-protected
 *            hosts. SerpAPI Google Images as last resort for image-only
 *            misses.
 *   PHASE C — VALIDATE: HEAD-check images, drop dead ones.
 *
 * Cache key is (manufacturer, MPN) — global, cross-receipt.
 */
class ScrapeReceiptItemImagesV2 implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    /**
     * Host of the receipt vendor's own website (e.g. 'virginiatile.com'),
     * derived from `vendor.business_website`. When set, this host is
     * preferred over every entry in PREFERRED_HOSTS and a `site:` filtered
     * SerpAPI search is attempted FIRST so we land on the vendor's own
     * product page when one exists.
     */
    private ?string $vendorHost = null;

    /**
     * Hosts whose URLs we never accept (manufacturer "shop" pages with
     * unreliable image extraction, marketing landing pages, etc.).
     */
    private const REJECTED_HOSTS = [
        'kohler.com', 'moen.com', 'deltafaucet.com', 'pfisterfaucets.com',
        'grohe.com', 'hansgrohe-usa.com', 'kallista.com',
        'amazon.com', 'walmart.com', 'ebay.com', 'alibaba.com', 'aliexpress.com',
        'pinterest.com', 'youtube.com', 'facebook.com', 'instagram.com',
        'reddit.com', 'wikipedia.org', 'yelp.com',
    ];

    /**
     * Hosts known to require headless-browser rendering (Cloudflare,
     * client-side rendered SPAs). Phase B uses Browsershot for these.
     */
    private const HEADLESS_HOSTS = [
        'kbauthority.com',
        'supplyhouse.com',
        'qualitybath.com',
        'brizo.com',
        'brizofaucet.ca',
    ];

    /**
     * Preferred reseller hosts, in priority order. Candidates whose link
     * is on one of these hosts are tried FIRST so that:
     *   (a) URLs across the receipt are visually consistent
     *   (b) we get high-resolution merchant-page images instead of the
     *       small SerpAPI Shopping thumbnails.
     * Hosts not in this list still get tried, just in original rank order.
     */
    private const PREFERRED_HOSTS = [
        'supplyhouse.com',
        'faucetdirect.com',
        'build.com',
        'fergusonshowrooms.com',
        'efaucets.com',
        'rona.ca',
        'homedepot.com',
        'kbauthority.com',
    ];

    public function __construct(public ExpenseReceipts $receipt) {}

    public function handle(): void
    {
        $items = $this->receipt->receipt_items['items'] ?? [];
        if (empty($items)) {
            return;
        }

        $vendor          = $this->receipt->expense?->vendor;
        $vendorId        = $vendor?->id;
        $this->vendorHost = $this->normalizeHost($vendor?->business_website);
        $changed         = false;

        // When most items in a receipt share the same finish in their
        // descriptions (e.g. "POLISHED CHROME"), siblings missing a
        // finish almost certainly belong to the same finish family.
        // Pre-compute the dominant finish so we can inject it into the
        // search query for those siblings.
        $receiptFinish = $this->inferReceiptFinish($items);

        foreach ($items as $idx => $item) {
            // Skip if already complete
            if (! empty($item['product_url']) && ! empty($item['image_url'])) {
                continue;
            }

            $mpn          = trim((string) ($item['ManufacturerPartNumber'] ?? ''));
            $manufacturer = trim((string) ($item['Manufacturer'] ?? ''));
            $description  = trim((string) ($item['Description'] ?? ''));

            // Receipts from vendors that don't break out an MPN column
            // (e.g. Studio41) often embed the part number at the start
            // of the description: "C3-455 CLEANSING TOILET SEAT WHITE".
            // Promote it to the MPN slot so downstream MPN-aware ranking
            // works.
            if ($mpn === '' && $description !== '') {
                $extracted = $this->extractMpnFromDescription($description);
                if ($extracted) {
                    $mpn = $extracted;
                }
            }

            // Inject the receipt-wide finish into description-only items
            // that don't already mention any finish, so SupplyHouse and
            // friends return the correct color variant.
            if ($receiptFinish && ! $this->descriptionHasFinish($description)) {
                $description = trim($description . ' ' . $receiptFinish);
            }

            if (! $description && ! $mpn) {
                continue;
            }

            // Vendor-bias is only safe when we have an MPN. With
            // description-only queries the vendor's site search will
            // happily return a near-miss SKU (wrong product). Disable
            // it for this item so PREFERRED_HOSTS (SupplyHouse first)
            // wins instead.
            $savedVendorHost = $this->vendorHost;
            if ($mpn === '') {
                $this->vendorHost = null;
            }
            try {

            // 1. Try global cache first (cross-receipt by manufacturer+MPN)
            $cached = $this->lookupGlobalCache($manufacturer, $mpn);
            if ($cached) {
                $items[$idx]['product_url'] = $items[$idx]['product_url'] ?? $cached['product_url'];
                $items[$idx]['image_url']   = $items[$idx]['image_url']   ?? $cached['image_url'];
                $changed = true;
                continue;
            }

            // 2. Resolve (Phase A)
            $result = $this->resolveProduct($manufacturer, $mpn, $description);

            // 3. Fallback (Phase B) if needed
            if (! $result || empty($result['image_url'])) {
                $fallback = $this->fallbackResolve($manufacturer, $mpn, $description, $result);
                if ($fallback) {
                    $result = array_merge($result ?? [], array_filter($fallback));
                }
            }

            if (! $result) {
                continue;
            }

            // 4. Validate image (Phase C)
            if (! empty($result['image_url']) && ! $this->isImageAccessible($result['image_url'])) {
                $result['image_url'] = null;
            }

            if (empty($result['product_url']) && empty($result['image_url'])) {
                continue;
            }

            $items[$idx]['product_url'] = $items[$idx]['product_url'] ?? $result['product_url'] ?? null;
            $items[$idx]['image_url']   = $items[$idx]['image_url']   ?? $result['image_url']   ?? null;
            $changed = true;

            // Store in global cache + per-receipt cache
            $this->storeCache($vendorId, $manufacturer, $mpn, $idx, $result);

            } finally {
                // Restore vendor host for the next iteration regardless of
                // which `continue` exit path was taken inside the try.
                $this->vendorHost = $savedVendorHost;
            }
        }

        if ($changed) {
            $ri = $this->receipt->receipt_items;
            $ri['items'] = $items;
            $this->receipt->receipt_items = $ri;
            $this->receipt->save();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PHASE A — RESOLVE
    // ═══════════════════════════════════════════════════════════════

    /**
     * One Brave web search → fetch top acceptable result → parse product page.
     * Returns ['product_url' => ..., 'image_url' => ...] or null.
     */
    private function resolveProduct(string $manufacturer, string $mpn, string $description): ?array
    {
        $query = $this->buildQuery($manufacturer, $mpn, $description);
        if (! $query) {
            return null;
        }

        // Vendor-biased pre-search: when the receipt has a known vendor
        // domain, try `site:vendor.com {query}` FIRST. If the vendor sells
        // the item we'll get their authoritative PDP (correct branding,
        // SKU, image), which is what users expect when reviewing the
        // receipt for that vendor.
        if ($this->vendorHost) {
            $vendorResult = $this->resolveFromVendorSite($query, $mpn);
            if ($vendorResult) {
                return $vendorResult;
            }
        }

        // SupplyHouse-priority pre-search: probe Google Shopping via
        // SerpAPI first (merchant feeds give us a paired PDP URL +
        // thumbnail in one call). If shopping doesn't surface a
        // SupplyHouse listing, fall back to a `site:supplyhouse.com`
        // web search — many Kohler/Brizo PDPs are indexed organically
        // but absent from the shopping feed.
        $supplyResult = $this->resolveFromShoppingHost('supplyhouse.com', $query, $mpn)
            ?? $this->resolveFromHost('supplyhouse.com', $query, $mpn);
        if ($supplyResult) {
            return $supplyResult;
        }

        // Single SerpAPI call returns BOTH organic + shopping results.
        $search   = $this->serpWebSearch($query, 10);
        $organic  = $search['organic']  ?? [];
        $shopping = $search['shopping'] ?? [];

        // Kohler MPNs ship with a "K-" prefix in receipts, but Google
        // Shopping indexes them WITHOUT that prefix. If we got no shopping
        // hits, retry once with the K- stripped to fetch per-variant ads.
        // Receipt MPNs may also arrive WITHOUT the dash (e.g. K41440), so
        // we strip an optional dash after the leading K.
        if (empty($shopping) && $mpn !== '' && preg_match('/^K-?\d/i', $mpn)) {
            $altMpn   = preg_replace('/^K-?/i', '', $mpn);
            $altQuery = $this->buildQuery($manufacturer, $altMpn, $description);
            if ($altQuery && $altQuery !== $query) {
                $shopping = $this->serpWebSearch($altQuery, 10)['shopping'] ?? [];
            }
        }

        if (empty($organic) && empty($shopping)) {
            return null;
        }

        // Rank Shopping rows by MPN-in-link / MPN-in-title — these are the
        // VARIANT pages (per-finish), so they're the best source for both
        // the product URL and a correct-finish image.
        $shopRanked = $this->rankShoppingByMpn($shopping, $mpn);

        // Re-rank organic so URLs containing the full MPN come first.
        $organic = $this->rankResults($organic, $mpn);

        // Build candidate URL list: Shopping-variant links first, then
        // organic. Each candidate carries its own correct-finish thumb URL
        // (from the matching shopping row) when available, used as a
        // FALLBACK when high-res page extraction fails.
        $candidates = [];
        foreach ($shopRanked as $r) {
            $url = $r['link'] ?? null;
            if ($url && $this->isAcceptableProductUrl($url, $mpn)) {
                $candidates[] = ['url' => $url, 'thumb' => $r['thumbnail'] ?? null];
            }
        }
        // First Shopping thumb that matched MPN — reusable as fallback for
        // any organic URL when its page extraction fails.
        $bestThumb = $shopRanked[0]['thumbnail'] ?? null;
        foreach ($organic as $r) {
            $url = $r['link'] ?? null;
            if ($url && $this->isAcceptableProductUrl($url, $mpn)) {
                $candidates[] = ['url' => $url, 'thumb' => $bestThumb];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Re-rank: PREFERRED_HOSTS first (in declared priority order),
        // then everything else preserving their existing order.
        $candidates = $this->rankCandidatesByHost($candidates);

        // Walk candidates: try high-res page extract; on failure use the
        // matched Shopping thumb (downloaded locally so it never expires).
        // Untrusted extracts (e.g. kbauthority opaque proxy) are deferred
        // and used only if nothing better surfaces.
        $firstAcceptable = null;
        $untrustedFallback = null;
        foreach ($candidates as $cand) {
            $url = $cand['url'];
            $firstAcceptable = $firstAcceptable ?? $url;
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

            $highRes = $this->extractProductData($url, $mpn);

            // For headless-required hosts (e.g. kbauthority), or when plain
            // HTTP extraction returned an untrusted image URL, escalate to
            // Browsershot which can pick up the variant-specific og:image
            // after JS settles.
            $needsEscalation = $this->needsHeadless($host)
                && (! is_string($highRes) || $highRes === ''
                    || $this->isUntrustedImageUrl($highRes, $mpn));
            if ($needsEscalation) {
                $rendered = $this->headlessExtractImage($url, $mpn);
                if ($rendered && ! $this->isUntrustedImageUrl($rendered, $mpn)) {
                    return ['product_url' => $url, 'image_url' => $rendered];
                }
            }

            $extractTrusted = is_string($highRes) && $highRes !== ''
                && ! $this->isUntrustedImageUrl($highRes, $mpn);

            if ($extractTrusted) {
                return ['product_url' => $url, 'image_url' => $highRes];
            }
            // Before falling back to the small Shopping thumb, try Google
            // Images for a HIGH-RES MPN-matching original (verified by
            // page-link/title containing the MPN, so finish is correct).
            if ($cand['thumb']) {
                $hires = $this->serpImageSearchHighRes($manufacturer, $mpn);
                if ($hires) {
                    return ['product_url' => $url, 'image_url' => $hires];
                }
                $stored = $this->downloadImageLocally($cand['thumb'], $manufacturer, $mpn);
                if ($stored) {
                    return ['product_url' => $url, 'image_url' => $stored];
                }
            }
            // Remember an untrusted extract as last-resort; keep walking.
            // Untrusted IMAGE URLs (e.g. Amazon-hosted images leaked via
            // merchant JSON-LD) are dropped — better to surface no image
            // than a wrong-product image — but the product_url is still
            // kept since it passed the MPN gate.
            if ($untrustedFallback === null && is_string($highRes) && $highRes !== '') {
                $imgIsUntrusted = $this->isUntrustedImageUrl($highRes, $mpn);
                $untrustedFallback = [
                    'product_url' => $url,
                    'image_url'   => $imgIsUntrusted ? null : $highRes,
                ];
            }
        }
        if ($untrustedFallback !== null) {
            return $untrustedFallback;
        }

        // Final safety net — some MPN-matched thumb but no extractable page.
        if ($firstAcceptable && $bestThumb) {
            $stored = $this->downloadImageLocally($bestThumb, $manufacturer, $mpn);
            if ($stored) {
                return ['product_url' => $firstAcceptable, 'image_url' => $stored];
            }
        }
        if ($firstAcceptable) {
            return ['product_url' => $firstAcceptable, 'image_url' => null];
        }

        return null;
    }

    /**
     * Return Shopping rows ranked by MPN match strength: link contains MPN
     * (best) > title contains MPN > others (only when MPN has no finish
     * suffix). Each entry has 'link' and 'thumbnail' keys.
     */
    private function rankShoppingByMpn(array $shopping, string $mpn): array
    {
        if (empty($shopping) || $mpn === '') {
            return [];
        }
        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        if ($mpnNorm === '') {
            return [];
        }
        $altNorm = preg_match('/^k-?\d/i', $mpn)
            ? strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)))
            : null;
        $hasFinishSuffix = $this->mpnHasFinishSuffix($mpn);

        $tiers = [[], [], []];
        foreach ($shopping as $r) {
            $thumb = $r['thumbnail'] ?? '';
            $link  = $r['link']      ?? '';
            if (! $thumb || $this->looksLikeLogo($thumb)) {
                continue;
            }
            $linkNorm  = strtolower(preg_replace('/[^a-z0-9]/i', '', $link));
            $titleNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $r['title'] ?? ''));

            $row = ['link' => $link, 'thumbnail' => $thumb];
            if (str_contains($linkNorm, $mpnNorm) || ($altNorm && str_contains($linkNorm, $altNorm))) {
                $tiers[0][] = $row;
            } elseif (str_contains($titleNorm, $mpnNorm) || ($altNorm && str_contains($titleNorm, $altNorm))) {
                $tiers[1][] = $row;
            } elseif (! $hasFinishSuffix) {
                $tiers[2][] = $row;
            }
        }
        return array_merge($tiers[0], $tiers[1], $tiers[2]);
    }

    /**
     * MPNs commonly encode finish in 1–5 trailing letters after digits,
     * with or without a dash separator. Examples that count as having a
     * finish suffix: K-26052-BLL, K26052BLL, 88765PC, 65365LFPCLHP, ITD910P.
     * Examples that do NOT: K41440, R70100WS (WS = whitespread, not finish
     * — but treating as finish is still safer than risking wrong picks).
     */
    private function mpnHasFinishSuffix(string $mpn): bool
    {
        return (bool) preg_match('/[0-9]-?[A-Z]{1,5}$/i', $mpn);
    }

    /**
     * Re-rank candidate URL list so PREFERRED_HOSTS appear first, in the
     * declared priority order. Preserves relative order within each tier.
     *
     * @param  array<int, array{url:string, thumb:?string}>  $candidates
     * @return array<int, array{url:string, thumb:?string}>
     */
    private function rankCandidatesByHost(array $candidates): array
    {
        $preferred = [];
        $other = [];
        // Vendor host always wins — bucket -1 sorts before every entry in
        // PREFERRED_HOSTS (which start at 0).
        foreach ($candidates as $cand) {
            $host = strtolower(parse_url($cand['url'], PHP_URL_HOST) ?? '');
            $host = preg_replace('/^www\./', '', $host);
            $rank = null;
            if ($this->vendorHost && str_ends_with($host, $this->vendorHost)) {
                $rank = -1;
            } else {
                foreach (self::PREFERRED_HOSTS as $i => $pref) {
                    if (str_ends_with($host, $pref)) {
                        $rank = $i;
                        break;
                    }
                }
            }
            if ($rank !== null) {
                $preferred[$rank][] = $cand;
            } else {
                $other[] = $cand;
            }
        }
        ksort($preferred);
        $sortedPreferred = [];
        foreach ($preferred as $bucket) {
            foreach ($bucket as $cand) {
                $sortedPreferred[] = $cand;
            }
        }
        return array_merge($sortedPreferred, $other);
    }

    /**
     * Returns true when the extracted image URL is from a host known to
     * leak the WRONG finish (parent-SKU image served via opaque proxy IDs
     * even on variant PDPs). When this is the case AND we have a verified
     * Shopping thumbnail, we should prefer the thumbnail.
     *
     * Currently flags:
     *  - kbauthority.com/image.php?... (opaque type=T&id=NNN — frequently parent finish)
     *  - any image URL whose path normalizes to NEITHER the full MPN nor
     *    a parent-SKU hint that matches our MPN family.
     */
    private function isUntrustedImageUrl(string $imgUrl, string $mpn): bool
    {
        $host = strtolower(parse_url($imgUrl, PHP_URL_HOST) ?? '');
        $path = strtolower(parse_url($imgUrl, PHP_URL_PATH) ?? '');

        // Amazon-hosted product images leaking through merchant JSON-LD
        // are almost always unrelated SKUs (different brand/model).
        if (str_contains($host, 'media-amazon.com') || str_contains($host, 'images-amazon.com')) {
            return true;
        }

        // kbauthority's image.php?type=T&id=N proxy serves the PARENT SKU
        // image (wrong finish — typically chrome) even on variant PDPs.
        // Reject so we fall back to itemprop=image which is variant-correct.
        if (str_contains($host, 'kbauthority.com') && str_contains($path, '/image.php')) {
            return true;
        }

        // kbauthority's /images/W/{N}/{FILE}.jpg — variant-specific when
        // the filename contains the MPN (correct finish, just small at
        // 130-250px). Only flag as untrusted when the filename doesn't
        // match our MPN — that signals a parent-SKU placeholder.
        if (str_contains($host, 'kbauthority.com')
            && preg_match('#/images/[wW]/\d+/([^/]+)\.(?:jpg|jpeg|png|webp)$#i', $path, $m)) {
            $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
            $altNorm = preg_match('/^k-?\d/i', $mpn)
                ? strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)))
                : '';
            $fileNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $m[1]));
            if ($mpnNorm && ! str_contains($fileNorm, $mpnNorm) && (! $altNorm || ! str_contains($fileNorm, $altNorm))) {
                return true;
            }
        }

        // SupplyHouse social-share placeholder served when extractProductData
        // hits a category/listing page (no og:image for an actual product).
        if (str_contains($host, 'supplyhouse.com') && str_contains($path, '/facebook_preview/')) {
            return true;
        }
        if (str_ends_with($path, '/fb_link_01.png')) {
            return true;
        }

        // afsupply.com generic catalog placeholder pattern
        // (e.g. /media/catalog/product/cache/HASH/k/o/kohler1_22_5_7.png).
        // The filename `kohler1` (or similar bare-brand placeholders) bears
        // no relation to the MPN — it's the "no real product image" image.
        if (str_contains($host, 'afsupply.com')
            && preg_match('#/media/catalog/product/cache/[^/]+/[a-z0-9]/[a-z0-9]/([a-z0-9_]+)\.(?:png|jpg|jpeg|webp)$#', $path, $m)) {
            $filename = $m[1];
            $mpnNorm  = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
            $altNorm  = preg_match('/^k-?\d/i', $mpn)
                ? strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)))
                : '';
            $fileNorm = preg_replace('/[^a-z0-9]/i', '', $filename);
            if ($mpnNorm && ! str_contains($fileNorm, $mpnNorm) && (! $altNorm || ! str_contains($fileNorm, $altNorm))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the best Shopping result whose link contains the full MPN, then
     * download its thumbnail to local public storage so the URL never
     * expires. Returns the public URL of the stored image, or null.
     */
    private function pickAndStoreShoppingImage(array $shopping, string $mpn, string $manufacturer): ?string
    {
        if (empty($shopping) || $mpn === '') {
            return null;
        }
        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        if ($mpnNorm === '') {
            return null;
        }
        // Also accept MPN with leading "K-" stripped (Kohler ads index w/o prefix).
        // Handles both K-26052 and K26052 forms.
        $altNorm = preg_match('/^k-?\d/i', $mpn)
            ? strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)))
            : null;

        // Try in priority order:
        //  1. link contains full MPN (best — variant-specific PDP)
        //  2. title contains full MPN (merchant variant listing)
        //  3. first shopping thumbnail (last resort, may be wrong finish)
        $tiers = [[], [], []];
        foreach ($shopping as $r) {
            $thumb = $r['thumbnail'] ?? '';
            if (! $thumb || $this->looksLikeLogo($thumb)) {
                continue;
            }
            $linkNorm  = strtolower(preg_replace('/[^a-z0-9]/i', '', $r['link']  ?? ''));
            $titleNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $r['title'] ?? ''));

            if (str_contains($linkNorm, $mpnNorm) || ($altNorm && str_contains($linkNorm, $altNorm))) {
                $tiers[0][] = $thumb;
            } elseif (str_contains($titleNorm, $mpnNorm) || ($altNorm && str_contains($titleNorm, $altNorm))) {
                $tiers[1][] = $thumb;
            } else {
                $tiers[2][] = $thumb;
            }
        }

        // Only fall back to tier 2 (no MPN match anywhere) when the MPN has
        // NO finish suffix — otherwise we'd risk storing the wrong color.
        $hasFinishSuffix = $this->mpnHasFinishSuffix($mpn);
        $candidates = array_merge($tiers[0], $tiers[1], $hasFinishSuffix ? [] : $tiers[2]);

        foreach ($candidates as $thumb) {
            $stored = $this->downloadImageLocally($thumb, $manufacturer, $mpn);
            if ($stored) {
                return $stored;
            }
        }
        return null;
    }

    /**
     * Download an image (typically a SerpAPI-proxied gstatic thumbnail) to
     * storage/app/public/receipt-images/ and return its public URL. File
     * is keyed by md5(manufacturer:mpn) so re-runs deduplicate cleanly.
     */
    private function downloadImageLocally(string $url, string $manufacturer, string $mpn): ?string
    {
        try {
            $resp = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($url);
        } catch (Throwable $e) {
            return null;
        }
        if (! $resp->successful()) {
            return null;
        }

        $bytes = $resp->body();
        if (strlen($bytes) < 500) {
            // Empty/placeholder; SerpAPI shopping thumbs are typically
            // 700-4000 bytes (small webp previews) so use a generous floor.
            return null;
        }
        $ctype = strtolower($resp->header('Content-Type') ?? '');
        if (! str_starts_with($ctype, 'image/')) {
            return null;
        }

        // Reject tiny images (e.g. cpesupply 107x101 thumbnails). The UI
        // displays product images at ~80-120px, so anything < 300px on
        // either side will look blurry/pixelated when scaled.
        $dims = @getimagesizefromstring($bytes);
        if ($dims && (($dims[0] < 300) || ($dims[1] < 300))) {
            return null;
        }

        $ext = match (true) {
            str_contains($ctype, 'webp') => 'webp',
            str_contains($ctype, 'png')  => 'png',
            str_contains($ctype, 'gif')  => 'gif',
            default                      => 'jpg',
        };

        // Key by manufacturer:mpn so re-runs deduplicate the same product.
        // When the item lacks BOTH manufacturer and MPN, fall back to a
        // hash of the source image URL — otherwise every MPN-less item
        // collides on md5(':') and the first download wins for all of
        // them (causing wrong images on unrelated rows).
        $manufacturerKey = strtolower(trim($manufacturer));
        $mpnKey          = strtolower(trim($mpn));
        $key = ($manufacturerKey === '' && $mpnKey === '')
            ? md5($url)
            : md5($manufacturerKey . ':' . $mpnKey);
        $path     = 'receipt-images/' . $key . '.' . $ext;
        $disk     = \Illuminate\Support\Facades\Storage::disk('public');

        if (! $disk->put($path, $bytes)) {
            return null;
        }
        return asset('storage/' . $path);
    }

    private function buildQuery(string $manufacturer, string $mpn, string $description): ?string
    {
        if ($manufacturer && $mpn) {
            return trim($manufacturer . ' ' . $mpn);
        }
        if ($mpn) {
            return $mpn;
        }
        if ($description) {
            $clean = preg_replace('/[^A-Za-z0-9\s\-\/]/', ' ', $description);
            $clean = preg_replace('/\s+/', ' ', trim($clean));
            return Str::limit($clean, 80, '');
        }
        return null;
    }

    /**
     * Vendors that don't break out an MPN column often embed the part
     * number at the START of the description, separated by ":" or a
     * space: "C3-455 CLEANSING TOILET SEAT WHITE".
     *
     * Heuristic: the first whitespace-delimited token is treated as a
     * candidate MPN when it's 4-25 chars, contains BOTH a letter and a
     * digit, and matches a typical alphanumeric SKU shape (letters,
     * digits, dashes, optionally a trailing "-{FINISH}").
     */
    private function extractMpnFromDescription(string $description): ?string
    {
        $first = strtok(trim($description), " \t\n\r:");
        if (! $first) {
            return null;
        }
        $first = rtrim($first, ':');
        if (strlen($first) < 4 || strlen($first) > 25) {
            return null;
        }
        if (! preg_match('/^[A-Z0-9][A-Z0-9\-\/]+$/i', $first)) {
            return null;
        }
        // Must contain at least one letter AND one digit to look like a SKU
        if (! preg_match('/[A-Z]/i', $first) || ! preg_match('/\d/', $first)) {
            return null;
        }
        // Reject common English words that happen to fit (KOHLER, BRIZO,
        // BEAUCLERE, OTHER, PURIST, AWAKEN, etc.) — these are the brand
        // or series names that prefix many descriptions instead of an MPN.
        if (! preg_match('/\d/', $first)) {
            return null;
        }
        return $first;
    }

    /**
     * Look across all items in a receipt and return the most common
     * finish phrase. Used to inject the right finish into queries for
     * sibling items whose description omits it.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function inferReceiptFinish(array $items): ?string
    {
        $tally = [];
        foreach ($items as $it) {
            $desc = strtoupper((string) ($it['Description'] ?? ''));
            foreach (self::FINISH_PHRASES as $finish) {
                if (str_contains($desc, $finish)) {
                    $tally[$finish] = ($tally[$finish] ?? 0) + 1;
                    break; // longest-match-first ordering in const handles overlap
                }
            }
        }
        if (empty($tally)) {
            return null;
        }
        arsort($tally);
        $top = array_key_first($tally);
        // Require at least 2 sibling items to share the finish so we
        // don't propagate a one-off finish to unrelated items.
        return ($tally[$top] >= 2) ? $top : null;
    }

    private function descriptionHasFinish(string $description): bool
    {
        $upper = strtoupper($description);
        foreach (self::FINISH_PHRASES as $finish) {
            if (str_contains($upper, $finish)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Common kitchen/bath finish phrases, ordered longest-first so the
     * inference loop matches the most specific phrase before a substring
     * (e.g. "VIBRANT BRUSHED MODERNE BRASS" beats "BRUSHED" alone).
     */
    private const FINISH_PHRASES = [
        'VIBRANT BRUSHED MODERNE BRASS',
        'VIBRANT BRUSHED NICKEL',
        'VIBRANT POLISHED NICKEL',
        'POLISHED CHROME',
        'BRUSHED NICKEL',
        'POLISHED NICKEL',
        'MATTE BLACK',
        'OIL RUBBED BRONZE',
        'BRUSHED BRONZE',
        'BRUSHED GOLD',
        'POLISHED BRASS',
        'STAINLESS STEEL',
        'CHAMPAGNE BRONZE',
        'CHROME',
        'WHITE',
    ];

    /**
     * Extract product image from page using JSON-LD (preferred) and meta tags.
     * Returns image URL string, empty string if page accessible but no image,
     * or null if page failed to load.
     */
    private function extractProductData(string $url, string $mpn): string|null
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get($url);
        } catch (Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        // UA-sniff workaround: some sites (e.g. virginiatile.com) return
        // a tiny JS-only stub when a Chrome UA is detected, but serve the
        // full SSR HTML to non-Chrome agents. Retry without our UA so the
        // og:image / inline product images become available.
        if (strlen($html) < 10000) {
            try {
                $alt = Http::timeout(8)
                    ->withHeaders(['Accept' => 'text/html,*/*;q=0.8'])
                    ->get($url);
                if ($alt->successful() && strlen($alt->body()) > strlen($html)) {
                    $html = $alt->body();
                }
            } catch (Throwable $e) {
                // keep original $html
            }
        }

        // 1. JSON-LD Product structured data (gold standard)
        $image = $this->extractJsonLdImage($html, $mpn);
        if ($image) {
            return $image;
        }

        // 1b. itemprop="image" — finish-specific image often lives here
        // even when og:image points to the parent SKU's default image
        // (kbauthority.com pattern: og:image=image.php?id=PARENT but
        // itemprop=images/W/.../K-26052-BLL_Kohler_Matte_Black.jpg).
        // Prefer when its URL contains the MPN.
        $mpnNormForItemprop = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        if ($mpnNormForItemprop !== ''
            && preg_match_all('/itemprop=["\']image["\']\s+content=["\']([^"\']+)["\']/i', $html, $im)) {
            foreach ($im[1] as $candidate) {
                $resolved = $this->resolveUrl($candidate, $url);
                if (! $resolved || $this->looksLikeLogo($resolved)) {
                    continue;
                }
                $urlNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $resolved));
                if (str_contains($urlNorm, $mpnNormForItemprop)) {
                    return $resolved;
                }
            }
        }

        // 2. Open Graph image
        if (preg_match('/<meta\s+(?:property|name)=["\']og:image(?::secure_url)?["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $resolved = $this->resolveUrl($m[1], $url);
            if ($resolved && ! $this->looksLikeLogo($resolved)) {
                return $resolved;
            }
        }

        // 3. Shopify product JSON (when loaded inline)
        if (preg_match('#"image":\s*"(https?:[^"]+)"#', $html, $m)) {
            $img = stripslashes($m[1]);
            if (! $this->looksLikeLogo($img)) {
                return $img;
            }
        }

        // 4. Twitter card
        if (preg_match('/<meta\s+name=["\']twitter:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $resolved = $this->resolveUrl($m[1], $url);
            if ($resolved && ! $this->looksLikeLogo($resolved)) {
                return $resolved;
            }
        }

        // 5. Vendor CDN sniff — some vendor sites (e.g. virginiatile.com)
        // omit og:image and Product JSON-LD on PDPs, but render the
        // product photo via a known image CDN in <img srcset>. Pick the
        // FIRST CDN URL on the page and normalize the width param to a
        // hi-res value, dropping any responsive `.webp` extension in
        // favour of `.jpg` for broader downstream compatibility.
        if (preg_match('#https?://cdn\.artivosurfaces\.com/image/upload/[A-Za-z0-9_-]+/w_\d+/[A-Za-z0-9_-]+\.(?:jpg|webp)#', $html, $m)) {
            $hi = preg_replace('#/w_\d+/#', '/w_1340/', $m[0]);
            $hi = preg_replace('/\.webp$/', '.jpg', $hi);
            return $hi;
        }

        return ''; // page loaded but no image found
    }

    /**
     * Walk JSON-LD blocks and return Product.image URL.
     * Optionally checks that block matches MPN.
     */
    private function extractJsonLdImage(string $html, string $mpn): ?string
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));

        foreach ($matches[1] as $jsonText) {
            $data = json_decode(trim($jsonText), true);
            if (! is_array($data)) {
                continue;
            }

            // Handle @graph wrappers and arrays
            $blocks = isset($data['@graph']) ? $data['@graph'] : [$data];
            if (isset($data[0])) {
                $blocks = $data;
            }

            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $type = $block['@type'] ?? null;
                if (is_array($type)) {
                    $type = implode(',', $type);
                }
                if (! $type || ! str_contains(strtolower((string) $type), 'product')) {
                    continue;
                }

                // Strict MPN match — required when our MPN has a finish
                // suffix (e.g. K-13689-2MB). A parent SKU like K-13689 in
                // the JSON-LD means the page defaults to the chrome image,
                // not our brushed-brass variant — so we skip and fall
                // through to og:image (which on a variant URL will be the
                // correct finish).
                // Accept either the full MPN or the K- stripped alt form
                // (resellers like supplyhouse drop the K- prefix in SKUs).
                if ($mpnNorm) {
                    $blockMpn = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($block['mpn'] ?? $block['sku'] ?? '')));
                    $altNorm = preg_match('/^k/i', $mpnNorm) ? substr($mpnNorm, 1) : '';
                    if ($blockMpn && $blockMpn !== $mpnNorm && (! $altNorm || $blockMpn !== $altNorm)) {
                        continue;
                    }
                }

                $img = $block['image'] ?? null;
                if (is_array($img)) {
                    $img = $img['url'] ?? ($img[0] ?? null);
                    if (is_array($img)) {
                        $img = $img['url'] ?? null;
                    }
                }
                if (is_string($img) && $img && ! $this->looksLikeLogo($img)) {
                    return $img;
                }
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // PHASE B — FALLBACK
    // ═══════════════════════════════════════════════════════════════

    private function fallbackResolve(string $manufacturer, string $mpn, string $description, ?array $partial): ?array
    {
        $out = [];

        // B1. If we have a URL on a headless-required host, render it
        if ($partial && ! empty($partial['product_url']) && empty($partial['image_url'])) {
            $host = parse_url($partial['product_url'], PHP_URL_HOST) ?: '';
            if ($this->needsHeadless($host)) {
                $rendered = $this->headlessExtractImage($partial['product_url'], $mpn);
                if ($rendered) {
                    $out['image_url'] = $rendered;
                }
            }
        }

        // B2. Google Shopping thumbnail (gstatic CDN — stable product image).
        // Download locally because gstatic URLs eventually return 404.
        if (empty($out['image_url']) && (empty($partial['image_url']) ?? true)) {
            $query = $this->buildQuery($manufacturer, $mpn, $description);
            $shop  = $this->serpShoppingImage($query, $mpn);
            if ($shop) {
                $stored = $this->downloadImageLocally($shop, $manufacturer, $mpn);
                $out['image_url'] = $stored ?: $shop;
            }
        }

        // B3. SerpAPI Google Images as last resort
        if (empty($out['image_url']) && (empty($partial['image_url']) ?? true)) {
            $query  = $this->buildQuery($manufacturer, $mpn, $description);
            $imgUrl = $this->serpImageSearch($query, $mpn);
            if ($imgUrl) {
                $stored = $this->downloadImageLocally($imgUrl, $manufacturer, $mpn);
                $out['image_url'] = $stored ?: $imgUrl;
            }
        }

        return $out ?: null;
    }

    private function needsHeadless(string $host): bool
    {
        foreach (self::HEADLESS_HOSTS as $h) {
            if (str_ends_with($host, $h)) {
                return true;
            }
        }
        return false;
    }

    private function headlessExtractImage(string $url, string $mpn): ?string
    {
        try {
            $html = Browsershot::url($url)
                ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox'])
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36')
                ->waitUntilNetworkIdle()
                ->timeout(30)
                ->bodyHtml();
        } catch (Throwable $e) {
            Log::warning('V2 headless render failed', ['url' => $url, 'err' => $e->getMessage()]);
            return null;
        }

        $img = $this->extractJsonLdImage($html, $mpn);
        if ($img && ! $this->isUntrustedImageUrl($img, $mpn)) {
            return $img;
        }

        // Prefer itemprop=image when its URL contains the MPN — these are
        // variant-specific (correct finish) on hosts like kbauthority.com
        // whose og:image points at an opaque parent-SKU proxy that serves
        // the wrong color (e.g. chrome instead of brushed brass).
        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        if ($mpnNorm !== ''
            && preg_match_all('/itemprop=["\']image["\']\s+content=["\']([^"\']+)["\']/i', $html, $im)) {
            foreach ($im[1] as $candidate) {
                $resolved = $this->resolveUrl($candidate, $url);
                if (! $resolved || $this->looksLikeLogo($resolved)) {
                    continue;
                }
                $resolvedNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $resolved));
                if (str_contains($resolvedNorm, $mpnNorm)) {
                    return $resolved;
                }
            }
        }

        if (preg_match('/<meta\s+(?:property|name)=["\']og:image(?::secure_url)?["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $resolved = $this->resolveUrl($m[1], $url);
            if ($resolved && ! $this->looksLikeLogo($resolved) && ! $this->isUntrustedImageUrl($resolved, $mpn)) {
                return $resolved;
            }
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // PHASE C — VALIDATE
    // ═══════════════════════════════════════════════════════════════

    private function isImageAccessible(string $url): bool
    {
        try {
            $response = Http::timeout(5)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ImageBot/1.0)',
            ])->head($url);
            if (! $response->successful()) {
                return false;
            }
            $contentType = $response->header('Content-Type') ?? '';
            return str_starts_with($contentType, 'image/');
        } catch (Throwable $e) {
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SERPAPI (Google Shopping + Web + Images)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Google Shopping thumbnail extraction. Returns gstatic image URL
     * preferring an MPN-matching title. Used only as image fallback.
     */
    private function serpShoppingImage(string $query, string $mpn): ?string
    {
        $apiKey = config('services.serpapi.api_key');
        if (! $apiKey) {
            return null;
        }
        try {
            $response = Http::timeout(15)->get('https://serpapi.com/search.json', [
                'engine'  => 'google_shopping',
                'q'       => $query,
                'gl'      => 'us',
                'hl'      => 'en',
                'api_key' => $apiKey,
            ]);
        } catch (Throwable $e) {
            return null;
        }
        if (! $response->successful()) {
            Log::warning('V2 SerpAPI shopping failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 200)]);
            return null;
        }
        $results = $response->json('shopping_results') ?? [];
        if (empty($results)) {
            return null;
        }

        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        $altNorm = preg_match('/^k-?\d/i', $mpn)
            ? strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)))
            : '';

        // Require MPN match in either link or title — never fall back to
        // the first thumbnail (high risk of wrong color/finish).
        foreach ($results as $r) {
            $img = $r['thumbnail'] ?? null;
            if (! $img || $this->looksLikeLogo($img)) {
                continue;
            }
            $hayNorm = preg_replace('/[^a-z0-9]/i', '', strtolower(($r['title'] ?? '') . ' ' . ($r['link'] ?? '')));
            if ($mpnNorm && (str_contains($hayNorm, $mpnNorm) || ($altNorm && str_contains($hayNorm, $altNorm)))) {
                return $img;
            }
        }
        return null;
    }

    /**
     * Single Google Web Search via SerpAPI. Returns BOTH organic and
     * shopping results from the same call (1 API credit total):
     *   ['organic' => [...organic_results], 'shopping' => [...shopping_results]]
     */
    private function serpWebSearch(string $query, int $count = 10): array
    {
        $apiKey = config('services.serpapi.api_key');
        if (! $apiKey) {
            return ['organic' => [], 'shopping' => []];
        }
        try {
            $response = Http::timeout(15)->get('https://serpapi.com/search.json', [
                'engine'  => 'google',
                'q'       => $query,
                'num'     => $count,
                'gl'      => 'us',
                'hl'      => 'en',
                'api_key' => $apiKey,
            ]);
        } catch (Throwable $e) {
            return ['organic' => [], 'shopping' => []];
        }
        if (! $response->successful()) {
            Log::warning('V2 SerpAPI web failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 200)]);
            return ['organic' => [], 'shopping' => []];
        }
        return [
            'organic'  => $response->json('organic_results')  ?? [],
            'shopping' => $response->json('shopping_results') ?? [],
        ];
    }

    private function serpImageSearch(?string $query, string $mpn): ?string
    {
        if (! $query) {
            return null;
        }
        $apiKey = config('services.serpapi.api_key');
        if (! $apiKey) {
            return null;
        }
        try {
            $response = Http::timeout(15)->get('https://serpapi.com/search.json', [
                'engine' => 'google_images',
                'q'      => $query,
                'gl'     => 'us',
                'hl'     => 'en',
                'api_key' => $apiKey,
            ]);
        } catch (Throwable $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $results = $response->json('images_results') ?? [];

        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        // Prefer MPN-matching result
        foreach ($results as $r) {
            $imgUrl = $r['original'] ?? ($r['thumbnail'] ?? null);
            if (! $imgUrl) continue;
            $title = strtolower($r['title'] ?? '');
            $page  = strtolower($r['link'] ?? '');
            $hayNorm = preg_replace('/[^a-z0-9]/i', '', $title . ' ' . $page);
            if ($mpnNorm && str_contains($hayNorm, $mpnNorm) && ! $this->looksLikeLogo($imgUrl)) {
                return $imgUrl;
            }
        }
        foreach ($results as $r) {
            $imgUrl = $r['original'] ?? ($r['thumbnail'] ?? null);
            if ($imgUrl && ! $this->looksLikeLogo($imgUrl)) {
                return $imgUrl;
            }
        }
        return null;
    }

    /**
     * High-resolution image lookup via Google Images, restricted to LARGE
     * images (`tbs=isz:l`) and validated by MPN appearing in the result's
     * page link or title (so finish is correct). Returns the `original`
     * CDN URL — typically 800-2000px versus the ~150px Shopping thumbs.
     * Returns null when no MPN-matching large image is found.
     */
    private function serpImageSearchHighRes(string $manufacturer, string $mpn): ?string
    {
        if ($mpn === '') {
            return null;
        }
        $apiKey = config('services.serpapi.api_key');
        if (! $apiKey) {
            return null;
        }
        $query = trim(($manufacturer ? $manufacturer . ' ' : '') . $mpn);
        try {
            $response = Http::timeout(15)->get('https://serpapi.com/search.json', [
                'engine'  => 'google_images',
                'q'       => $query,
                'tbs'     => 'isz:l',
                'gl'      => 'us',
                'hl'      => 'en',
                'api_key' => $apiKey,
            ]);
        } catch (Throwable $e) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $results = $response->json('images_results') ?? [];
        if (empty($results)) {
            return null;
        }

        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        $altNorm = preg_match('/^k/i', $mpnNorm) ? substr($mpnNorm, 1) : '';

        foreach ($results as $r) {
            $imgUrl = $r['original'] ?? null;
            if (! $imgUrl || $this->looksLikeLogo($imgUrl)) {
                continue;
            }
            // Reject obvious low-res CDN proxies (gstatic encrypted thumbs).
            $imgHost = strtolower(parse_url($imgUrl, PHP_URL_HOST) ?? '');
            if (str_contains($imgHost, 'gstatic.com')) {
                continue;
            }
            // Reject untrusted hosts (Amazon CDNs etc) — wrong-product risk.
            if ($this->isUntrustedImageUrl($imgUrl, $mpn)) {
                continue;
            }
            $hay = strtolower(($r['title'] ?? '') . ' ' . ($r['link'] ?? '') . ' ' . $imgUrl);
            $hayNorm = preg_replace('/[^a-z0-9]/i', '', $hay);
            if (str_contains($hayNorm, $mpnNorm) || ($altNorm && str_contains($hayNorm, $altNorm))) {
                return $imgUrl;
            }
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Vendor-biased pre-search. Runs `site:{vendorHost} {query}` and tries
     * to extract a product image from the top acceptable result. Returns
     * null when the vendor doesn't list this item (so caller falls through
     * to the general search). Keeps URLs across a receipt visually
     * consistent with the vendor the user actually purchased from.
     *
     * Tries up to two query variants: (1) the original cleaned query
     * (preserves dimensions like `12X24` for finish/size precision), then
     * (2) an alphabetic-only fallback that drops noisy tokens like
     * fractions (`1-1/4`) or trailing flags (`NEW PKG`) which often cause
     * the `site:` search to return zero hits.
     */
    private function resolveFromVendorSite(string $query, string $mpn): ?array
    {
        if (! $this->vendorHost) {
            return null;
        }

        return $this->resolveFromHost($this->vendorHost, $query, $mpn);
    }

    /**
     * Generic `site:{host} {query}` web probe via SerpAPI. Tries the
     * cleaned query first, then an alphabetic-only fallback that drops
     * fractions/dimensions/flags which often cause `site:` searches to
     * return zero hits against slug URLs.
     */
    private function resolveFromHost(string $host, string $query, string $mpn): ?array
    {
        $variants = array_unique(array_filter([
            $query,
            $this->stripNumericTokens($query),
        ]));

        foreach ($variants as $variant) {
            $scoped  = 'site:' . $host . ' ' . $variant;
            $organic = $this->serpWebSearch($scoped, 5)['organic'] ?? [];
            if (empty($organic)) {
                continue;
            }
            $organic = $this->rankResults($organic, $mpn);
            foreach ($organic as $r) {
                $url = $r['link'] ?? null;
                if (! $url) {
                    continue;
                }
                $urlHost = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
                $urlHost = preg_replace('/^www\./', '', $urlHost);
                if (! str_ends_with($urlHost, $host)) {
                    continue;
                }
                if (! $this->isAcceptableProductUrl($url, $mpn)) {
                    continue;
                }
                $image = $this->extractProductData($url, $mpn);
                if (is_string($image) && $image !== '' && ! $this->isUntrustedImageUrl($image, $mpn)) {
                    return ['product_url' => $url, 'image_url' => $image];
                }
                // Even without an image we prefer this host's product URL
                // over a mismatched-brand URL from a generic search.
                // Phase B will try Google Images for an MPN-matching photo.
                return ['product_url' => $url, 'image_url' => null];
            }
        }
        return null;
    }

    /**
     * Probe SerpAPI's google_shopping engine and return the first
     * MPN-matching merchant result whose link points at the given host.
     * Shopping feeds give us a paired PDP URL + thumbnail in one call,
     * which is more reliable than re-ranking generic organic results.
     */
    private function resolveFromShoppingHost(string $host, string $query, string $mpn): ?array
    {
        $apiKey = config('services.serpapi.api_key');
        if (! $apiKey) {
            return null;
        }

        $variants = array_unique(array_filter([
            $query,
            $this->stripNumericTokens($query),
        ]));

        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        $altNorm = preg_match('/^k-?\d/i', $mpn)
            ? strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)))
            : '';

        foreach ($variants as $variant) {
            try {
                $response = Http::timeout(15)->get('https://serpapi.com/search.json', [
                    'engine'  => 'google_shopping',
                    'q'       => $variant,
                    'gl'      => 'us',
                    'hl'      => 'en',
                    'api_key' => $apiKey,
                ]);
            } catch (Throwable $e) {
                continue;
            }
            if (! $response->successful()) {
                continue;
            }
            $results = $response->json('shopping_results') ?? [];
            foreach ($results as $r) {
                $url = $r['product_link'] ?? $r['link'] ?? null;
                if (! $url) {
                    continue;
                }
                $urlHost = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
                $urlHost = preg_replace('/^www\./', '', $urlHost);
                if (! str_ends_with($urlHost, $host)) {
                    continue;
                }
                if ($mpnNorm) {
                    $hayNorm = preg_replace('/[^a-z0-9]/i', '', strtolower(($r['title'] ?? '') . ' ' . $url));
                    if (! str_contains($hayNorm, $mpnNorm) && ! ($altNorm && str_contains($hayNorm, $altNorm))) {
                        continue;
                    }
                }
                if (! $this->isAcceptableProductUrl($url, $mpn)) {
                    continue;
                }
                // Prefer scraping the PDP for a high-res image; fall back
                // to the shopping thumbnail if extraction fails.
                $image = $this->extractProductData($url, $mpn);
                if (is_string($image) && $image !== '' && ! $this->isUntrustedImageUrl($image, $mpn)) {
                    return ['product_url' => $url, 'image_url' => $image];
                }
                $thumb = $r['thumbnail'] ?? null;
                if ($thumb && ! $this->looksLikeLogo($thumb) && ! $this->isUntrustedImageUrl($thumb, $mpn)) {
                    return ['product_url' => $url, 'image_url' => $thumb];
                }
                return ['product_url' => $url, 'image_url' => null];
            }
        }
        return null;
    }

    /**
     * Drop pure-number / dimension tokens (e.g. `1-1/4`, `12X24`, `3X5`)
     * and common trailing noise (`NEW PKG`, `RECT`) so a `site:` search
     * still matches the vendor's slug-style URLs which omit those bits.
     */
    private function stripNumericTokens(string $query): string
    {
        // Noise modifiers vendors omit from URL slugs — must be skipped
        // so `site:vendor.com` searches still match the PDP. Real product
        // words like HONED/MATTE/POLISHED stay because vendors DO include
        // those in slugs (e.g. `.../moscato-argento-...-honed/`).
        static $stopWords = ['RECT', 'RECTIFIED', 'NEW', 'PKG', 'NEWPKG', 'NEW-PKG'];

        // Remove slash fractions and common dim patterns.
        $clean = preg_replace('#[0-9]+[\s\-/]+[0-9]+([xX][0-9]+)?#', ' ', $query);
        // Remove standalone NxM dim tokens.
        $clean = preg_replace('/\b[0-9]+[xX][0-9]+\b/', ' ', $clean);
        // Drop standalone short tokens (1-2 chars), pure-number tokens,
        // and noise modifiers.
        $words = preg_split('/\s+/', $clean);
        $kept  = array_filter($words, function ($w) use ($stopWords) {
            $w = trim($w);
            if ($w === '') return false;
            if (preg_match('/^[0-9]+$/', $w)) return false;
            if (strlen($w) < 2) return false;
            if (in_array(strtoupper($w), $stopWords, true)) return false;
            return true;
        });
        return trim(implode(' ', $kept));
    }

    /**
     * Strip scheme/path/`www.` prefix from a URL and return the bare host
     * (e.g. 'https://www.virginiatile.com/about' → 'virginiatile.com').
     * Returns null for empty / invalid input.
     */
    private function normalizeHost(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! $host) {
            // Bare host passed without scheme.
            $host = preg_replace('#^([^/]+).*$#', '$1', trim($url));
        }
        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);
        return $host ?: null;
    }

    private function isAcceptableProductUrl(string $url, string $mpn): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $host = preg_replace('/^www\./', '', $host);

        foreach (self::REJECTED_HOSTS as $bad) {
            if ($host === $bad || str_ends_with($host, '.' . $bad)) {
                return false;
            }
        }

        // Reject obvious category/search URLs (also matches
        // /product-category/, /product-categories/ used by WooCommerce).
        if (preg_match('#(?:^|/|-)(?:product-)?(?:category|categories|search|filter|tag|brand|shop-[a-z]+)(/|\?|$)#i', $url)) {
            return false;
        }

        // When MPN is meaningful (≥4 chars with a digit), require it to
        // appear in the URL. This rejects near-MPN PDPs (different model)
        // and wrong-finish variants. The MPN may appear with or without
        // dashes/spaces, so compare normalized.
        if (strlen($mpn) >= 4 && preg_match('/\d/', $mpn)) {
            $urlNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $url));
            $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
            $altNorm = preg_match('/^k-?\d/i', $mpn)
                ? strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)))
                : null;
            $matches = str_contains($urlNorm, $mpnNorm)
                || ($altNorm && str_contains($urlNorm, $altNorm));
            if (! $matches) {
                return false;
            }
        }

        // Reject paginated index pages (e.g. `/tile/page/10/`, `?page=12`).
        if (preg_match('#/page/[0-9]+/?#i', $url) || preg_match('#[?&]page=[0-9]+#i', $url)) {
            return false;
        }

        // SupplyHouse category slugs end in a 6+ digit category id
        // (e.g. `/Toilets-26001000`, `/Shower-Heads-14834000`). Real
        // PDPs always start with a brand prefix (`/Kohler-K-...`).
        if (str_ends_with($host, 'supplyhouse.com')
            && preg_match('#^/[A-Za-z][A-Za-z0-9\-]*-\d{6,}/?$#', parse_url($url, PHP_URL_PATH) ?? '')) {
            return false;
        }

        // Reject filter / facet query strings (e.g. `?formats=...`,
        // `?applications=...`, `?categories=...`, `?filters=...`).
        if (preg_match('#[?&](formats|applications|categories|filters|facet|sort|color|finish|size)=#i', $url)) {
            return false;
        }

        return true;
    }

    private function looksLikeLogo(string $url): bool
    {
        $u = strtolower($url);
        if (str_ends_with(parse_url($u, PHP_URL_PATH) ?? '', '.svg')) {
            return true;
        }
        return (bool) preg_match('/(logo|placeholder|favicon|sprite|icon-|spinner|loading)/i', $u);
    }

    /**
     * Re-rank SerpAPI results so URLs containing the full MPN come first.
     * That guarantees we land on the exact-finish variant PDP rather than
     * a parent product page that defaults to a different finish image.
     * Stable within tier — keeps Google's organic order as the tiebreaker.
     */
    private function rankResults(array $results, string $mpn): array
    {
        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        if ($mpnNorm === '') {
            return $results;
        }

        $scored = [];
        foreach ($results as $i => $r) {
            $url      = $r['link'] ?? '';
            $urlNorm  = strtolower(preg_replace('/[^a-z0-9]/i', '', $url));
            $mpnMatch = str_contains($urlNorm, $mpnNorm) ? 0 : 1;
            $scored[] = ['rank' => ($mpnMatch * 1000) + $i, 'result' => $r];
        }

        usort($scored, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return array_column($scored, 'result');
    }

    private function resolveUrl(string $url, string $base): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $host   = parse_url($base, PHP_URL_HOST) ?: '';
            return $scheme . '://' . $host . $url;
        }
        return $url;
    }

    // ═══════════════════════════════════════════════════════════════
    // CACHE — global by (manufacturer, MPN)
    // ═══════════════════════════════════════════════════════════════

    private function lookupGlobalCache(string $manufacturer, string $mpn): ?array
    {
        if (! $mpn) {
            return null;
        }
        $row = ReceiptLineItemDesc::query()
            ->where('sku', $mpn)
            ->whereNotNull('product_url')
            ->whereNotNull('product_image_url')
            ->orderByDesc('updated_at')
            ->first();

        if (! $row) {
            return null;
        }
        return [
            'product_url' => $row->product_url,
            'image_url'   => $row->product_image_url,
        ];
    }

    private function storeCache(?int $vendorId, string $manufacturer, string $mpn, int $idx, array $result): void
    {
        if (! $mpn) {
            return;
        }
        ReceiptLineItemDesc::updateOrCreate(
            [
                'expense_receipt_id' => $this->receipt->id,
                'item_index'         => $idx,
            ],
            [
                'vendor_id'         => $vendorId,
                'sku'               => $mpn,
                'product_url'       => $result['product_url'] ?? null,
                'product_image_url' => $result['image_url']   ?? null,
            ]
        );
    }
}
