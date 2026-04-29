<?php

namespace App\Jobs;

use App\Models\ExpenseReceipts;
use App\Models\Product;
use App\Models\ReceiptLineItemDesc;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * V2 Receipt Item Image Scraper — simplified 2-phase pipeline.
 *
 *   PHASE A — RESOLVE: one SERP web search per item (returns organic +
 *            shopping in a single Bright Data call). Vendor- and
 *            SupplyHouse-biased pre-searches run first when relevant.
 *            Each candidate URL is fetched (HTTP, escalating to a
 *            headless Browsershot render for JS-protected hosts) and
 *            its main product image is extracted from JSON-LD /
 *            itemprop / og:image. The PDP's own image is the source
 *            of truth — no Google-Images detours, no shopping-thumb
 *            substitutions.
 *   PHASE C — VALIDATE: HEAD-check images, drop dead ones.
 *
 * Cache key is (manufacturer, MPN) — global, cross-receipt. The
 * `verified_at` flag on `products` protects manually-curated entries
 * from being overwritten by future scrapes.
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
     * SERP search is attempted FIRST so we land on the vendor's own
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
        'homedepot.com',
        'houseoffixtures.com',
        'plumbers-supply-co.com',
    ];

    /**
     * Preferred reseller hosts, in priority order. Candidates whose link
     * is on one of these hosts are tried FIRST so that:
     *   (a) URLs across the receipt are visually consistent
     *   (b) we get high-resolution merchant-page images instead of the
     *       small SERP Shopping thumbnails.
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

    public function __construct(public ExpenseReceipts $receipt)
    {
        // Headless-render fallbacks (Browsershot ~30s) plus chained SERP
        // lookups can run well past the default queue's 60s worker timeout.
        // Route to Horizon's `long-running` supervisor (timeout 2100s).
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        $items = $this->receipt->receipt_items['items'] ?? [];
        if (empty($items)) {
            \Log::info('ScrapeReceiptItemImagesV2: no items', ['receipt_id' => $this->receipt->id]);
            return;
        }

        $vendor          = $this->receipt->expense?->vendor;
        $vendorId        = $vendor?->id;
        $this->vendorHost = $this->normalizeHost($vendor?->business_website);
        $changed         = false;

        \Log::info('ScrapeReceiptItemImagesV2: start', [
            'receipt_id'  => $this->receipt->id,
            'item_count'  => count($items),
            'vendor_host' => $this->vendorHost,
        ]);

        // When most items in a receipt share the same finish in their
        // descriptions (e.g. "POLISHED CHROME"), siblings missing a
        // finish almost certainly belong to the same finish family.
        // Pre-compute the dominant finish so we can inject it into the
        // search query for those siblings.
        $receiptFinish = $this->inferReceiptFinish($items);

        foreach ($items as $idx => $item) {
            // Skip if already complete
            if (! empty($item['product_url']) && ! empty($item['image_url'])) {
                \Log::info('ScrapeReceiptItemImagesV2: skip complete', [
                    'receipt_id' => $this->receipt->id,
                    'idx'        => $idx,
                    'vc'         => $item['VendorCode'] ?? null,
                ]);
                // Even when the item is already complete, make sure the
                // global products table reflects it so cross-receipt
                // lookups stay in sync.
                $this->storeCache(
                    $vendorId,
                    trim((string) ($item['Manufacturer'] ?? '')),
                    trim((string) ($item['ManufacturerPartNumber'] ?? '')),
                    $idx,
                    [
                        'product_url' => $item['product_url'],
                        'image_url'   => $item['image_url'],
                    ]
                );
                continue;
            }

            $mpn          = trim((string) ($item['ManufacturerPartNumber'] ?? ''));
            $manufacturer = trim((string) ($item['Manufacturer'] ?? ''));
            $description  = trim((string) ($item['Description'] ?? ''));

            \Log::info('ScrapeReceiptItemImagesV2: item begin', [
                'receipt_id' => $this->receipt->id,
                'idx'        => $idx,
                'vc'         => $item['VendorCode'] ?? null,
                'mpn'        => $mpn,
                'mfr'        => $manufacturer,
                'desc'       => substr($description, 0, 80),
                'has_url'    => ! empty($item['product_url']),
                'has_img'    => ! empty($item['image_url']),
            ]);

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
                \Log::info('ScrapeReceiptItemImagesV2: skip no-desc-no-mpn', [
                    'receipt_id' => $this->receipt->id,
                    'idx'        => $idx,
                ]);
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
                \Log::info('ScrapeReceiptItemImagesV2: cache hit', [
                    'receipt_id' => $this->receipt->id, 'idx' => $idx, 'mpn' => $mpn,
                ]);
                if (empty($items[$idx]['product_url'])) {
                    $items[$idx]['product_url'] = $cached['product_url'];
                }
                if (empty($items[$idx]['image_url'])) {
                    $items[$idx]['image_url'] = $cached['image_url'];
                }
                $changed = true;
                continue;
            }

            // 2. Resolve (Phase A)
            $result = $this->resolveProduct($manufacturer, $mpn, $description);
            \Log::info('ScrapeReceiptItemImagesV2: resolveProduct result', [
                'receipt_id' => $this->receipt->id, 'idx' => $idx,
                'product_url' => $result['product_url'] ?? null,
                'image_url'   => $result['image_url'] ?? null,
            ]);

            // Phase B fallback removed — extractProductData now escalates
            // to a headless render itself, so resolveProduct returns
            // either the PDP's own image or null. No more Google-Images
            // detours that surfaced wrong-finish/wrong-product photos.

            if (! $result) {
                \Log::info('ScrapeReceiptItemImagesV2: no result', [
                    'receipt_id' => $this->receipt->id, 'idx' => $idx, 'mpn' => $mpn,
                ]);
                continue;
            }

            // 4. Validate image (Phase C)
            if (! empty($result['image_url']) && ! $this->isImageAccessible($result['image_url'])) {
                $result['image_url'] = null;
            }

            if (empty($result['product_url']) && empty($result['image_url'])) {
                continue;
            }

            if (empty($items[$idx]['product_url'])) {
                $items[$idx]['product_url'] = $result['product_url'] ?? null;
            }
            if (empty($items[$idx]['image_url'])) {
                $items[$idx]['image_url'] = $result['image_url'] ?? null;
            }
            $changed = true;

            // Store in global cache + per-receipt cache
            $this->storeCache($vendorId, $manufacturer, $mpn, $idx, $result);

            // Persist incrementally so a timeout doesn't wipe progress
            // accumulated for earlier items in this same run.
            $ri = $this->receipt->receipt_items;
            $ri['items'] = $items;
            $this->receipt->receipt_items = $ri;
            $this->receipt->save();

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
     * One SERP web search → fetch top acceptable result → parse product page.
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
        // SERP first (merchant feeds give us a paired PDP URL +
        // thumbnail in one call). If shopping doesn't surface a
        // SupplyHouse listing, fall back to a `site:supplyhouse.com`
        // web search — many Kohler/Brizo PDPs are indexed organically
        // but absent from the shopping feed.
        $supplyResult = $this->resolveFromShoppingHost('supplyhouse.com', $query, $mpn)
            ?? $this->resolveFromHost('supplyhouse.com', $query, $mpn);
        if ($supplyResult) {
            return $supplyResult;
        }

        // Single SERP call returns BOTH organic + shopping results.
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

        // Walk candidates: for each, fetch the page (HTTP, escalating to
        // a headless render when needed) and use its main product image.
        // Trust the URL — no Google-Images detours, no shopping-thumb
        // substitutions; if the PDP doesn't expose an image we surface
        // the URL with no image (better than a wrong-product image).
        $firstAcceptable = null;
        foreach ($candidates as $cand) {
            $url = $cand['url'];
            $firstAcceptable = $firstAcceptable ?? $url;

            $image = $this->extractProductData($url, $mpn);
            if (! is_string($image) || $image === '' || $this->looksLikeLogo($image)) {
                continue;
            }
            // Reject generic/placeholder/wrong-SKU images so we walk on
            // to the next candidate URL instead of caching junk.
            if ($this->isUntrustedImageUrl($image, $mpn)) {
                continue;
            }

            // Try to download. Many product hosts are Cloudflare-protected
            // (403 to plain HTTP) or return tiny/no-content responses for
            // a hotlinked image — in either case advance to the next
            // candidate rather than caching an unusable remote URL.
            $stored = $this->downloadImageLocally($image, $manufacturer, $mpn);
            if ($stored) {
                return ['product_url' => $url, 'image_url' => $stored];
            }
            \Log::info('ScrapeReceiptItemImagesV2: image download failed, walking on', [
                'mpn' => $mpn, 'url' => $url, 'image' => $image,
            ]);

            // Last-resort for THIS candidate: many vendors host their
            // hero image behind Cloudflare (full JS challenge) which
            // blocks server-side fetches. The matching Google Shopping
            // thumbnail is the same product (variant-correct when ranked
            // by MPN) and Google's gstatic CDN is hotlink-friendly. Use
            // it so we surface SOME image rather than walking off the
            // last acceptable PDP URL with nothing.
            $thumb = $cand['thumb'] ?? null;
            if ($thumb && ! $this->isUntrustedImageUrl($thumb, $mpn)) {
                $stored = $this->downloadImageLocally($thumb, $manufacturer, $mpn);
                if ($stored) {
                    return ['product_url' => $url, 'image_url' => $stored];
                }
            }
        }

        if ($firstAcceptable) {
            // Final last-resort: every candidate either had no image, an
            // untrusted/placeholder image, or a download-blocked image.
            // The top-ranked Shopping thumbnail (bestThumb) is the right
            // product (matched by MPN rank) and gstatic.com is
            // hotlink-friendly — surface it against the firstAcceptable
            // PDP URL so the user sees an image instead of an empty box.
            if ($bestThumb && ! $this->isUntrustedImageUrl($bestThumb, $mpn)) {
                $stored = $this->downloadImageLocally($bestThumb, $manufacturer, $mpn);
                if ($stored) {
                    return ['product_url' => $firstAcceptable, 'image_url' => $stored];
                }
            }
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

        // Marketplace CDN images (eBay, Amazon, Walmart, Pinterest, AliExpress)
        // leak through merchant JSON-LD and are almost always unrelated
        // SKUs from third-party listings — wrong color, wrong product, or
        // generic stock photos. Reject so we fall back to a real merchant
        // page or Google Shopping thumb.
        $marketplaceHostFragments = [
            'media-amazon.com', 'images-amazon.com', 'ssl-images-amazon.com',
            'ebayimg.com',
            'walmartimages.com',
            'pinimg.com',
            'alicdn.com',
        ];
        foreach ($marketplaceHostFragments as $frag) {
            if (str_contains($host, $frag)) {
                return true;
            }
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

        // Theme-level OG placeholder images (e.g. uakc.com's WordPress
        // theme serves /wp-content/themes/.../og_snippet_1200x630.png
        // when a product has no real image). Filename pattern is generic.
        if (preg_match('#/og_snippet[_\-]?\d*x?\d*\.(?:png|jpe?g|webp)$#i', $path)) {
            return true;
        }
        // Generic social/share fallbacks served by themes when a PDP
        // lacks real imagery.
        if (preg_match('#/(?:default|fallback|no[-_]?image|noimage|placeholder|share[-_]image|social[-_]share)[^/]*\.(?:png|jpe?g|webp)$#i', $path)) {
            return true;
        }

        // Alternative-angle / detail / swatch images. Vendors often
        // expose these in JSON-LD `image` arrays alongside the primary
        // hero shot — they show only a part of the product (e.g. just
        // a handle for a valve trim) so they're misleading. Reject so
        // the candidate loop walks to the next merchant URL where the
        // primary image (or a different alt-free PDP) can be picked.
        // Patterns covered: `_alt`, `_alt2`, `/alternative/`, `_back`,
        // `_side`, `_top`, `_bottom`, `_detail`, `_swatch`, `_thumb`,
        // `_secondary`, `-alt-`, `-alt2-`.
        // Only match against the BASENAME (the filename itself), not
        // folder names — many vendors use generic folders like
        // `/detail_page/`, `/thumbs/`, `/top/{cat}/` that happen to
        // contain these tokens but the actual image is the primary.
        $basename = strtolower(basename($path));
        if (preg_match('#(?:^|[_\-])(?:alt\d*|alternative|back|side|top|bottom|detail|swatch|thumb|secondary)(?:[_\-\.]|$)#i', $basename)) {
            return true;
        }

        return false;
    }

    /**
     * Download an image (typically a SERP-proxied gstatic thumbnail) to
     * storage/app/public/receipt-images/ and return its public URL. File
     * is keyed by md5(manufacturer:mpn) so re-runs deduplicate cleanly.
     */
    private function downloadImageLocally(string $url, string $manufacturer, string $mpn): ?string
    {
        // Decode HTML entities (e.g. `&amp;` from JSON-LD/og:image).
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);

        // Many product hosts sit behind Cloudflare and 403 plain HTTP
        // clients without a real browser UA + same-origin Referer. Send
        // both so the candidate-loop only walks-on for genuine failures.
        $imgHost = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $referer = $imgHost !== '' ? ('https://' . $imgHost . '/') : null;
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
                            '(KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'Accept'     => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ];
        if ($referer) {
            $headers['Referer'] = $referer;
        }

        try {
            $resp = Http::timeout(15)->withHeaders($headers)->get($url);
        } catch (Throwable $e) {
            return null;
        }
        if (! $resp->successful()) {
            return null;
        }

        $bytes = $resp->body();
        if (strlen($bytes) < 500) {
            // Empty/placeholder; SERP shopping thumbs are typically
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

        // Auto-crop large white borders (scene7 ISO renders, Lowe's product
        // shots, etc. often have 20-40% whitespace). Re-encodes as JPEG when
        // crop is significant; leaves photos with full-bleed/colored
        // backgrounds untouched.
        $cropped = $this->cropWhiteBorders($bytes, $ctype);
        if ($cropped !== null) {
            $bytes = $cropped;
            $ctype = 'image/jpeg';
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

    /**
     * Trim large white borders from product photos (scene7 ISO renders,
     * Lowe's/THD studio shots, etc.). Returns re-encoded JPEG bytes when
     * cropping removes more than ~10% on any side; returns null if the
     * image is full-bleed, has a colored background, or GD isn't available.
     */
    private function cropWhiteBorders(string $bytes, string $ctype): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecrop')) {
            return null;
        }
        // Don't crop animated GIFs (would lose animation) or webp with
        // transparency.
        if (str_contains($ctype, 'gif')) {
            return null;
        }

        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $threshold = 240;
        $minX = $w; $minY = $h; $maxX = 0; $maxY = 0;

        // Sample every 2 pixels for speed (sufficient resolution for border detection).
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($r < $threshold || $g < $threshold || $b < $threshold) {
                    if ($x < $minX) { $minX = $x; }
                    if ($y < $minY) { $minY = $y; }
                    if ($x > $maxX) { $maxX = $x; }
                    if ($y > $maxY) { $maxY = $y; }
                }
            }
        }

        // No non-white content found, or content fills the frame already.
        if ($maxX <= $minX || $maxY <= $minY) {
            imagedestroy($img);
            return null;
        }

        $borderLeft   = $minX;
        $borderRight  = $w - 1 - $maxX;
        $borderTop    = $minY;
        $borderBottom = $h - 1 - $maxY;
        $maxBorder    = max($borderLeft, $borderRight, $borderTop, $borderBottom);

        // Only crop when borders are substantial (>10% of dimension).
        if ($maxBorder < min($w, $h) * 0.10) {
            imagedestroy($img);
            return null;
        }

        $pad = 15;
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($w - 1, $maxX + $pad);
        $maxY = min($h - 1, $maxY + $pad);

        $cropped = @imagecrop($img, [
            'x' => $minX, 'y' => $minY,
            'width' => $maxX - $minX + 1,
            'height' => $maxY - $minY + 1,
        ]);
        imagedestroy($img);
        if (! $cropped) {
            return null;
        }

        ob_start();
        imagejpeg($cropped, null, 92);
        $out = ob_get_clean();
        imagedestroy($cropped);

        return $out ?: null;
    }

    private function buildQuery(string $manufacturer, string $mpn, string $description): ?string
    {
        $finish = $this->extractFinish($description);
        if ($manufacturer && $mpn) {
            return trim($manufacturer . ' ' . $mpn . ($finish ? ' ' . $finish : ''));
        }
        if ($mpn) {
            return trim($mpn . ($finish ? ' ' . $finish : ''));
        }
        if ($description) {
            $clean = preg_replace('/[^A-Za-z0-9\s\-\/]/', ' ', $description);
            $clean = preg_replace('/\s+/', ' ', trim($clean));
            return Str::limit($clean, 80, '');
        }
        return null;
    }

    /**
     * Return the longest FINISH_PHRASE found in the description, or null.
     * Used to bias both the search query and result-matching toward the
     * correct color/finish (e.g. WHITE vs BLACK toilet tanks).
     */
    private function extractFinish(string $description): ?string
    {
        $upper = strtoupper($description);
        foreach (self::FINISH_PHRASES as $finish) {
            if (str_contains($upper, $finish)) {
                return $finish;
            }
        }
        return null;
    }

    /**
     * Returns true when the candidate text contains the requested finish,
     * OR contains no other competing finish phrase. Returns false only
     * when a *different* finish is explicitly named (wrong color).
     */
    private function matchesFinish(string $haystack, ?string $finish): bool
    {
        if (! $finish) {
            return true;
        }
        $upper = strtoupper($haystack);
        if (str_contains($upper, $finish)) {
            return true;
        }
        foreach (self::FINISH_PHRASES as $other) {
            if ($other === $finish) {
                continue;
            }
            if (str_contains($upper, $other)) {
                return false;
            }
        }
        return true;
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
    /**
     * Get the main product image from the PDP at $url.
     * Tries plain HTTP first; escalates to a headless render when the
     * page is on a JS-protected host, returns < 10KB, or yields no
     * recognisable image. Returns the absolute image URL or null.
     */
    private function extractProductData(string $url, string $mpn): ?string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $needsHeadless = $this->needsHeadless($host);

        $html = null;
        if (! $needsHeadless) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                        'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    ])
                    ->get($url);
                if ($response->successful()) {
                    $html = $response->body();
                    // UA-sniff workaround for sites that ship a JS-only stub
                    // to Chrome but a full SSR page to other agents.
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
                }
            } catch (Throwable $e) {
                $html = null;
            }
        }

        $image = $html ? $this->parsePdpImage($html, $url, $mpn) : null;
        if ($image) {
            return $image;
        }

        // Plain-HTTP parse found nothing. Always escalate to a headless
        // render: many modern PDPs (firstsupply, etc.) ship a 100KB+
        // JS bundle whose product image is only injected after hydration.
        // The earlier short-circuit on response size was wrong because a
        // big JS stub still has zero parseable PDP markup.

        // First pass: domcontentloaded (fast, ~5-10s). JSON-LD / og:image
        // are server-rendered on most PDPs, so we usually get the image
        // here without waiting for tracker scripts to finish.
        $rendered = $this->browsershotRender($url, 'domcontentloaded', 25);
        $image = $rendered ? $this->parsePdpImage($rendered, $url, $mpn) : null;
        if ($image) {
            return $image;
        }

        // Second pass: networkidle (slower, up to 60s) for lazy-loaded
        // hero images on Shopify-style PDPs.
        $rendered = $this->browsershotRender($url, 'networkidle0', 60);
        return $rendered ? $this->parsePdpImage($rendered, $url, $mpn) : null;
    }

    /**
     * Render a URL with Browsershot, returning HTML or null on failure.
     */
    private function browsershotRender(string $url, string $waitUntil, int $timeoutSeconds): ?string
    {
        try {
            return Browsershot::url($url)
                ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox'])
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36')
                ->setOption('waitUntil', $waitUntil)
                ->timeout($timeoutSeconds)
                ->bodyHtml();
        } catch (Throwable $e) {
            Log::warning('V2 headless render failed', [
                'url'        => $url,
                'wait_until' => $waitUntil,
                'timeout'    => $timeoutSeconds,
                'err'        => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract the main product image URL from a fully-rendered HTML page.
     * Tries, in order:
     *   1. JSON-LD Product.image (with optional MPN-strict matching)
     *   2. itemprop="image" containing the MPN (variant-correct on many
     *      reseller sites where og:image points at the parent SKU)
     *   3. og:image / og:image:secure_url
     *   4. Inline `"image": "https://..."` (Shopify, custom JSON dumps)
     *   5. twitter:image
     *   6. Vendor CDN sniff (artivosurfaces, etc.)
     */
    private function parsePdpImage(string $html, string $url, string $mpn): ?string
    {
        $image = $this->extractJsonLdImage($html, $mpn);
        if ($image) {
            return $image;
        }

        $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
        if ($mpnNorm !== ''
            && preg_match_all('/itemprop=["\']image["\']\s+content=["\']([^"\']+)["\']/i', $html, $im)) {
            foreach ($im[1] as $candidate) {
                $resolved = $this->resolveUrl($candidate, $url);
                if (! $resolved || $this->looksLikeLogo($resolved)) {
                    continue;
                }
                $urlNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $resolved));
                if (str_contains($urlNorm, $mpnNorm)) {
                    return $resolved;
                }
            }
        }

        if (preg_match('/<meta\s+(?:property|name)=["\']og:image(?::secure_url)?["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $resolved = $this->resolveUrl($m[1], $url);
            if ($resolved && ! $this->looksLikeLogo($resolved)) {
                return $resolved;
            }
        }

        if (preg_match('#"image":\s*"(https?:[^"]+)"#', $html, $m)) {
            $img = stripslashes($m[1]);
            if (! $this->looksLikeLogo($img)) {
                return $img;
            }
        }

        if (preg_match('/<meta\s+name=["\']twitter:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $resolved = $this->resolveUrl($m[1], $url);
            if ($resolved && ! $this->looksLikeLogo($resolved)) {
                return $resolved;
            }
        }

        if (preg_match('#https?://cdn\.artivosurfaces\.com/image/upload/[A-Za-z0-9_-]+/w_\d+/[A-Za-z0-9_-]+\.(?:jpg|webp)#', $html, $m)) {
            $hi = preg_replace('#/w_\d+/#', '/w_1340/', $m[0]);
            $hi = preg_replace('/\.webp$/', '.jpg', $hi);
            return $hi;
        }

        // SupplyHouse PDPs sometimes ship without og:image / itemprop
        // when their JS-rendered gallery hasn't hydrated. They host all
        // product imagery under a predictable cloudfront path keyed by
        // the dashed MPN slug (e.g. `k-26052-bll.jpeg`). Extract the
        // dashed MPN from the URL path and fall back to that CDN URL.
        // Negative-lookahead `(?![a-z])` stops at the first segment that
        // begins a word (e.g. `-Essential`) so we don't capture marketing
        // slug words after the actual SKU.
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (str_ends_with($host, 'supplyhouse.com')
            && preg_match('#-([A-Z]+-?\d+(?:-[A-Z0-9]+(?![a-z]))*)#', $url, $m)) {
            $slug = strtolower($m[1]);
            return 'https://d3501hjdis3g5w.cloudfront.net/images/products/zoom/' . $slug . '.jpeg';
        }

        return null;
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

    private function needsHeadless(string $host): bool
    {
        foreach (self::HEADLESS_HOSTS as $h) {
            if (str_ends_with($host, $h)) {
                return true;
            }
        }
        return false;
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
    // SERP (Google Web Search via Bright Data)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Single Google Web Search via SERP. Returns BOTH organic and
     * shopping results from the same call (1 API credit total):
     *   ['organic' => [...organic_results], 'shopping' => [...shopping_results]]
     */
    private function serpWebSearch(string $query, int $count = 10): array
    {
        $result = \App\Services\Search\SerpClient::make()->web($query, $count);
        return [
            'organic'  => $result['organic_results']  ?? [],
            'shopping' => $result['shopping_results'] ?? [],
        ];
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
     * Generic `site:{host} {query}` web probe via SERP. Tries the
     * cleaned query first, then an alphabetic-only fallback that drops
     * fractions/dimensions/flags which often cause `site:` searches to
     * return zero hits against slug URLs.
     */
    private function resolveFromHost(string $host, string $query, string $mpn): ?array
    {
        $variants = array_unique(array_filter(array_merge(
            [$query, $this->stripNumericTokens($query)],
            $this->mpnQueryVariants($query, $mpn),
        )));

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
     * Probe SERP's google_shopping engine and return the first
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

        $variants = array_unique(array_filter(array_merge(
            [$query, $this->stripNumericTokens($query)],
            $this->mpnQueryVariants($query, $mpn),
        )));

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
     * Return query variants where the MPN token is rewritten into the
     * canonical dashed forms vendors actually use in URL slugs. Receipts
     * collapse Kohler MPNs into bare alphanumerics (`K200000`, `K41440`,
     * `K26052BLL`) but supplyhouse / kbauthority / homedepot index them
     * dashed (`K-20000-0`, `K-4144-0`, `K-26052-BLL`). Without this
     * substitution `site:supplyhouse.com Kohler K200000 WHITE` returns
     * zero hits even though the PDP exists.
     */
    private function mpnQueryVariants(string $query, string $mpn): array
    {
        if ($mpn === '' || stripos($query, $mpn) === false) {
            return [];
        }
        $alts = [];
        if (preg_match('/^K(\d+)([A-Z]+)$/i', $mpn, $m)) {
            $alts[] = 'K-' . $m[1] . '-' . strtoupper($m[2]);
        } elseif (preg_match('/^K(\d{2,})$/i', $mpn, $m)) {
            $digits = $m[1];
            $alts[] = 'K-' . $digits;
            if (strlen($digits) >= 2) {
                $alts[] = 'K-' . substr($digits, 0, -1) . '-' . substr($digits, -1);
            }
        }
        $variants = [];
        foreach (array_unique($alts) as $alt) {
            $v = str_ireplace($mpn, $alt, $query);
            if ($v !== $query && $v !== '') {
                $variants[] = $v;
            }
        }
        return $variants;
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
        // dashes/spaces, so compare on a normalized form — but require a
        // non-alphanumeric boundary on each side so `K200000` does NOT
        // match inside `K-R20000-0` (Kohler "R20000" is a different sink).
        if (strlen($mpn) >= 4 && preg_match('/\d/', $mpn)) {
            $urlPath = strtolower(parse_url($url, PHP_URL_PATH) ?? '') . ' '
                . strtolower(parse_url($url, PHP_URL_QUERY) ?? '');
            $mpnNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $mpn));
            // Build a regex that allows optional separators (`-`, `_`,
            // `/`, ` `) BETWEEN each character of the MPN, and requires
            // a non-alphanumeric (or start/end) boundary on the outside.
            $pattern = '#(?:^|[^a-z0-9])'
                . implode('[\-_/\s]?', array_map(fn ($c) => preg_quote($c, '#'), str_split($mpnNorm)))
                . '(?:[^a-z0-9]|$)#i';
            $matches = (bool) preg_match($pattern, $urlPath);

            // Kohler URLs sometimes drop the `K-` prefix (e.g.
            // `/p/kohler-foo-r20000-0/...`). Allow that too.
            if (! $matches && preg_match('/^k-?\d/i', $mpn)) {
                $alt        = strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/^K-?/i', '', $mpn)));
                $altPattern = '#(?:^|[^a-z0-9])'
                    . implode('[\-_/\s]?', array_map(fn ($c) => preg_quote($c, '#'), str_split($alt)))
                    . '(?:[^a-z0-9]|$)#i';
                $matches = (bool) preg_match($altPattern, $urlPath);
            }
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

        // Brizo bundle/configurable URLs join multiple SKUs with `--`
        // (e.g. `/bath/product/T70165-PCLHP--HL7065-PC--R70100`). When
        // the user wants a single component (a handle, a rough-in), the
        // bundle PDP shows the whole assembly which is wrong. Reject
        // any path that contains 2+ `--` segments — Brizo's standalone
        // PDPs are at `/bath/product/<SINGLE-SKU>`.
        if ((str_ends_with($host, 'brizo.com') || str_ends_with($host, 'brizofaucet.ca'))
            && substr_count(parse_url($url, PHP_URL_PATH) ?? '', '--') >= 2) {
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
     * Re-rank SERP results so URLs containing the full MPN come first.
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
        $product = Product::lookup($manufacturer, $mpn);
        if (! $product) {
            return null;
        }

        return [
            'product_url' => $product->product_url,
            'image_url'   => $product->image_url,
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

        $norm = Product::normalizeMpn($mpn);
        if ($norm === '') {
            return;
        }

        $product = Product::firstOrNew([
            'manufacturer' => $manufacturer ?: null,
            'mpn'          => $mpn,
        ]);

        // Don't overwrite a human-verified pin with auto-scraped data.
        if ($product->verified_at) {
            $product->last_checked_at = now();
            $product->save();
            return;
        }

        $product->fill([
            'mpn_normalized'  => $norm,
            'product_url'     => $result['product_url'] ?? $product->product_url,
            'image_url'       => $result['image_url']   ?? $product->image_url,
            'vendor_id'       => $product->vendor_id ?? $vendorId,
            'source'          => $product->source ?: 'scrape',
            'last_checked_at' => now(),
        ])->save();
    }
}
