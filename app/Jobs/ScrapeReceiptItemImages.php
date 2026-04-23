<?php

namespace App\Jobs;


use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapeReceiptItemImages implements ShouldQueue
{
    use Queueable;

    /**
     * Preferred specialty/trade sites checked in Phase 0, before any vendor WooCommerce API.
     * These sites have accurate SKU-based product pages and high-quality images.
     */
    private const PREFERRED_SPECIALTY_SITES = [
        'https://www.kbauthority.com',
        'https://gnkitchenandbath.com',
    ];

    /**
     * Major retailer/aggregator domains — used to deprioritize results from these sites
     * in favor of manufacturer or specialty plumbing/building supply sites.
     */
    private const RETAILER_DOMAINS = [
        'amazon.com', 'walmart.com', 'homedepot.com', 'homedepot.ca', 'lowes.com',
        'ebay.com', 'target.com', 'wayfair.com', 'overstock.com', 'costco.com',
        'build.com', 'menards.com', 'acehardware.com', 'tractorsupply.com',
        'ferguson.com', 'fergusonhome.com', 'qualitybath.com', 'plumbersstock.com',
        'faucet.com', 'faucetdirect.com', 'plumbingdepot.com',
        'alibaba.com', 'aliexpress.com', 'wish.com', 'etsy.com',
        'google.com', 'bing.com', 'youtube.com', 'pinterest.com', 'facebook.com',
        'reddit.com', 'wikipedia.org', 'yelp.com',
    ];

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public ExpenseReceipts $receipt)
    {
    }

    public function handle(): void
    {
        $items = $this->receipt->receipt_items['items'] ?? [];

        if (empty($items)) {
            return;
        }

        $vendorId = $this->receipt->expense?->vendor_id;
        $vendor = $this->receipt->expense?->vendor;
        $vendorName = $vendor?->business_name;
        $vendorWebsite = $vendor?->business_website;

        // If the receipt's merchant_name differs from the expense vendor (e.g. a designer
        // ordered materials from a supplier), use the supplier's website for product lookups.
        $merchantName = $this->receipt->receipt_items['merchant_name'] ?? null;
        if ($merchantName && $vendor && ! Str::contains(Str::lower($vendorName ?? ''), Str::lower($merchantName))) {
            // Try exact match first, then fuzzy: strip common suffixes (LLC, Inc, Co, etc.)
            $merchantVendor = \App\Models\Vendor::where('business_name', 'LIKE', '%' . $merchantName . '%')->first();

            if (! $merchantVendor) {
                $cleaned = preg_replace('/\b(LLC|INC|CO|CORP|COMPANY|LTD|LP|GROUP)\b\.?/i', '', $merchantName);
                $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
                if (strlen($cleaned) >= 3) {
                    $merchantVendor = \App\Models\Vendor::where('business_name', 'LIKE', '%' . $cleaned . '%')->first();
                }
            }

            if ($merchantVendor) {
                $vendorId = $merchantVendor->id;
                $vendorName = $merchantVendor->business_name;
                $vendorWebsite = $merchantVendor->business_website;
            }
        }

        // If vendor has no website, try to discover it
        if ($vendor && ! $vendorWebsite && $vendorName) {
            $vendorWebsite = $this->discoverVendorWebsite($vendorName);

            if ($vendorWebsite) {
                $vendor->update(['business_website' => $vendorWebsite]);
            }
        }

        $updated = false;

        // Snapshot existing image/url data so we never regress
        $originalData = [];
        foreach ($items as $index => $item) {
            if (! empty($item['image_url']) || ! empty($item['product_url'])) {
                $originalData[$index] = [
                    'image_url'   => $item['image_url'] ?? null,
                    'product_url' => $item['product_url'] ?? null,
                ];
            }
        }

        // Helper: collect items that still need a product URL
        $needsUrl = function () use (&$items): array {
            $missing = [];
            foreach ($items as $i => $item) {
                if (empty($item['product_url'])) {
                    $missing[$i] = $item;
                }
            }
            return $missing;
        };

        // Helper: collect items that still need an image
        $needsImage = function () use (&$items): array {
            $missing = [];
            foreach ($items as $i => $item) {
                if (empty($item['image_url'])) {
                    $missing[$i] = $item;
                }
            }
            return $missing;
        };

        // Helper: persist a result into items + DB
        $applyResult = function (int $index, ?string $productUrl, ?string $imageUrl) use (&$items, &$updated, $vendorId): void {
            // If a "product URL" is actually a direct image URL, reclassify it
            if ($productUrl && ! $imageUrl && preg_match('/\.(jpg|jpeg|png|webp|gif)(\?|$)/i', $productUrl)) {
                $imageUrl = $productUrl;
                $productUrl = null;
            }

            if ($productUrl && empty($items[$index]['product_url']) && $this->isProductUrl($productUrl)) {
                $items[$index]['product_url'] = $productUrl;
                $updated = true;
            }
            if ($imageUrl && empty($items[$index]['image_url'])) {
                $items[$index]['image_url'] = $imageUrl;
                $updated = true;
            }
            if ($productUrl || $imageUrl) {
                ReceiptLineItemDesc::updateOrCreate(
                    ['expense_receipt_id' => $this->receipt->id, 'item_index' => $index],
                    array_filter([
                        'vendor_id' => $vendorId,
                        'sku' => $items[$index]['sku'] ?? $items[$index]['VendorCode'] ?? $items[$index]['ProductCode'] ?? null,
                        'product_url' => $items[$index]['product_url'] ?? null,
                        'product_image_url' => $items[$index]['image_url'] ?? null,
                        'area' => $items[$index]['Area'] ?? null,
                    ])
                );
            }
        };

        // ═══════════════════════════════════════════════════════════════
        // PHASE 0: Preferred specialty sites (kbauthority.com etc.)
        // Checked first via site: search to get accurate SKU-matched product
        // pages. Runs before the vendor WooCommerce API so correct results
        // are locked in and cannot be overwritten by looser keyword matches.
        // ═══════════════════════════════════════════════════════════════
        foreach (self::PREFERRED_SPECIALTY_SITES as $specialtySite) {
            $urlMissing = $needsUrl();
            if (empty($urlMissing)) {
                break;
            }
            $specialtyResults = $this->searchVendorSite($urlMissing, $specialtySite);
            foreach ($specialtyResults as $index => $result) {
                if ($result) {
                    $applyResult($index, $result['product_url'] ?? null, $result['image_url'] ?? null);
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 0.5: Fetch images for items that already have a PREFERRED
        // SPECIALTY_SITES URL but no image. kbauthority.com is Cloudflare-
        // protected so Phase 4 HTTP scraping fails; Azure web_search_preview
        // can access those pages and return the image URL directly.
        // ═══════════════════════════════════════════════════════════════
        $preferredHosts = array_map(
            fn ($site) => parse_url($site, PHP_URL_HOST) ?: $site,
            self::PREFERRED_SPECIALTY_SITES
        );
        $needsImageFromPreferred = function () use (&$items, $preferredHosts): array {
            $missing = [];
            foreach ($items as $i => $item) {
                if (! empty($item['image_url'])) {
                    continue;
                }
                $url = $item['product_url'] ?? null;
                if (! $url) {
                    continue;
                }
                $urlHost = parse_url($url, PHP_URL_HOST) ?: '';
                foreach ($preferredHosts as $ph) {
                    if (str_ends_with($urlHost, $ph)) {
                        $missing[$i] = $item;
                        break;
                    }
                }
            }
            return $missing;
        };
        $needingPreferredImage = $needsImageFromPreferred();
        if (! empty($needingPreferredImage)) {
            foreach ($needingPreferredImage as $index => $item) {
                $knownUrl = $item['product_url'];
                $urlHost  = parse_url($knownUrl, PHP_URL_HOST) ?: '';
                $result   = $this->fetchImageFromKnownUrl($urlHost, $knownUrl, $item);
                if ($result && ! empty($result['image_url'])) {
                    $applyResult($index, null, $result['image_url']);
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 1: WooCommerce API (s41tradeconnect.com)
        // Fast, returns both URL + image, no scraping needed.
        // ═══════════════════════════════════════════════════════════════
        $wooSites = ['https://s41tradeconnect.com'];
        if ($vendorWebsite) {
            $wooSites[] = $vendorWebsite;
        }

        foreach ($wooSites as $wooSite) {
            $wooItems = $needsImage();
            if (empty($wooItems)) {
                break;
            }

            $siteReachable = true;
            foreach ($wooItems as $index => $item) {
                if (! $siteReachable) {
                    break; // Skip remaining items if this site is unreachable
                }

                try {
                    $result = $this->searchWooCommerceApi(
                        $item['ManufacturerPartNumber'] ?? $item['sku'] ?? $item['VendorCode'] ?? $item['ProductCode'] ?? null,
                        $item['name'] ?? $item['Description'] ?? null,
                        $wooSite
                    );
                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    Log::debug('ScrapeReceiptItemImages: WooCommerce site unreachable, skipping', ['site' => $wooSite]);
                    $siteReachable = false;
                    continue;
                }

                if ($result) {
                    $applyResult($index, $result['product_url'] ?? null, $result['image_url'] ?? null);
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 1.5: Brave site: search — s41tradeconnect.com first, then vendor website
        // For items where WooCommerce API returned nothing, search vendor-indexed
        // pages via Brave site: query. s41tradeconnect.com is always tried first.
        // ═══════════════════════════════════════════════════════════════
        $urlMissing = $needsUrl();
        if (! empty($urlMissing)) {
            $s41SiteResults = $this->searchVendorSite($urlMissing, 'https://s41tradeconnect.com');
            foreach ($s41SiteResults as $index => $result) {
                if ($result) {
                    $applyResult($index, $result['product_url'] ?? null, $result['image_url'] ?? null);
                }
            }
        }
        if ($vendorWebsite && parse_url($vendorWebsite, PHP_URL_HOST) !== 's41tradeconnect.com') {
            $urlMissing = $needsUrl();
            if (! empty($urlMissing)) {
                $vendorSiteResults = $this->searchVendorSite($urlMissing, $vendorWebsite);
                foreach ($vendorSiteResults as $index => $result) {
                    if ($result) {
                        $applyResult($index, $result['product_url'] ?? null, $result['image_url'] ?? null);
                    }
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 2: Brave Web Search for product URLs
        // Finds manufacturer/retailer product pages. Does NOT try to
        // extract images (that's Phase 4). Just gets URLs.
        // Specialty/manufacturer sites are preferred; retailers (Amazon etc.)
        // are only used as a fallback when no other page scores well.
        // ═══════════════════════════════════════════════════════════════
        $urlMissing = $needsUrl();
        if (! empty($urlMissing)) {
            $braveResults = $this->braveProductSearch($urlMissing, $vendorName);
            foreach ($braveResults as $index => $result) {
                if ($result && ! empty($result['product_url'])) {
                    $applyResult($index, $result['product_url'], null);
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 3: Azure OpenAI + Bing for remaining URLs
        // AI-assisted search finds URLs that keyword search misses.
        // ═══════════════════════════════════════════════════════════════
        $urlMissing = $needsUrl();
        if (! empty($urlMissing)) {
            $bingResults = $this->batchSearchProductImages($urlMissing, $vendorName);
            foreach ($bingResults as $index => $result) {
                if ($result) {
                    $applyResult($index, $result['product_url'] ?? null, $result['image_url'] ?? null);
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 4: Extract images from found product URLs
        // Simple HTTP extraction (og:image, WooCommerce, srcset, img).
        // Fast — skips SPA/bot-protected sites (handled in Phase 6).
        // ═══════════════════════════════════════════════════════════════
        $imgMissing = $needsImage();
        foreach ($imgMissing as $index => $item) {
            $productUrl = $item['product_url'] ?? null;
            if (! $productUrl) {
                continue;
            }
            try {
                $extracted = $this->extractImageFromProductPage($productUrl);
                if ($extracted && $extracted !== false && $this->isImageAccessible($extracted)) {
                    $applyResult($index, null, $extracted);
                }
            } catch (\Throwable $e) {
                // Page unreachable — will try image search next
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 5: Brave Image Search for missing images
        // Bypasses SPA/bot protection by getting images directly from
        // search engine CDN. Most reliable image source.
        // ═══════════════════════════════════════════════════════════════
        $imgMissing = $needsImage();
        if (! empty($imgMissing)) {
            $braveImgResults = $this->braveImageSearch($imgMissing, $vendorName, $vendorWebsite);
            foreach ($braveImgResults as $index => $result) {
                if ($result) {
                    $applyResult(
                        $index,
                        $result['product_url'] ?? null,
                        $result['image_url'] ?? null
                    );
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 6.5: Final preferred-site image fetch.
        // Phase 0.5 ran early; if subsequent phases (1.5/2/3) discovered a
        // PREFERRED_SPECIALTY_SITES URL but its image still wasn't filled
        // (Phase 4 HTTP scrape often fails on Cloudflare/Shopify), retry
        // Azure-based image fetch here so late-discovered URLs still get images.
        // ═══════════════════════════════════════════════════════════════
        $needingPreferredImageLate = $needsImageFromPreferred();
        if (! empty($needingPreferredImageLate)) {
            foreach ($needingPreferredImageLate as $index => $item) {
                $knownUrl = $item['product_url'];
                $urlHost  = parse_url($knownUrl, PHP_URL_HOST) ?: '';
                $result   = $this->fetchImageFromKnownUrl($urlHost, $knownUrl, $item);
                if ($result && ! empty($result['image_url'])) {
                    $applyResult($index, null, $result['image_url']);
                }
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PHASE 7: Validate all image URLs — kill dead/inaccessible ones
        // ═══════════════════════════════════════════════════════════════
        foreach ($items as $index => &$item) {
            $imageUrl = $item['image_url'] ?? null;
            if (! $imageUrl) {
                continue;
            }
            $accessible = $this->isImageAccessible($imageUrl);
            if (! $accessible) {
                $item['image_url'] = null;
                $updated = true;
                // Also clear the stale image from the cache to avoid confusion
                ReceiptLineItemDesc::where('expense_receipt_id', $this->receipt->id)
                    ->where('item_index', $index)
                    ->where('product_image_url', $imageUrl)
                    ->update(['product_image_url' => null]);
            }
        }
        unset($item);

        // Never regress: restore any image/url data that was present before but lost
        foreach ($originalData as $index => $orig) {
            if (empty($items[$index]['image_url']) && ! empty($orig['image_url'])) {
                $items[$index]['image_url'] = $orig['image_url'];
                $updated = true;
            }
            if (empty($items[$index]['product_url']) && ! empty($orig['product_url'])) {
                $items[$index]['product_url'] = $orig['product_url'];
                $updated = true;
            }
        }

        if ($updated) {
            $receiptItems = $this->receipt->receipt_items;
            $receiptItems['items'] = $items;
            $this->receipt->update(['receipt_items' => $receiptItems]);
        }
    }

    /**
     * Search the vendor's own website for product pages using Brave Web Search
     * with a site: filter, then extract image from the product page.
     *
     * @param  array<int, array{sku: ?string, name: ?string}>  $items
     * @return array<int, ?array{image_url: ?string, product_url: ?string}>
     */
    private function searchVendorSite(array $items, ?string $vendorWebsite): array
    {
        if (! $vendorWebsite) {
            return [];
        }

        $host = parse_url($vendorWebsite, PHP_URL_HOST) ?: $vendorWebsite;
        $results = [];

        foreach ($items as $index => $item) {
            $results[$index] = $this->searchVendorSiteViaAzure($host, $item);
        }

        return $results;

        // ── Brave site: search (commented out — Azure OpenAI is now primary) ──
        /*
        $apiKey = config('services.brave_search.api_key');

        if (! $apiKey || ! $vendorWebsite) {
            return [];
        }

        $host = parse_url($vendorWebsite, PHP_URL_HOST) ?: $vendorWebsite;
        $results = [];

        foreach ($items as $index => $item) {
            $sku = $item['sku'] ?? $item['VendorCode'] ?? $item['ProductCode'] ?? null;
            $name = $item['name'] ?? $item['Description'] ?? null;

            $parsed = $this->parseProductDescription($name ?? '');
            $mfrPart = $item['ManufacturerPartNumber'] ?? null;
            if ($mfrPart && ! ctype_digit(str_replace(['-', ' '], '', $mfrPart))) {
                $parsed['model'] = $mfrPart;
            }
            $searchTerm = $this->buildSmartQuery($parsed, includeFinish: true);

            if (! $searchTerm && $sku && ! ctype_digit($sku)) {
                $searchTerm = $sku;
            }

            if (! $searchTerm) {
                $results[$index] = null;
                continue;
            }

            $query = "site:{$host} {$searchTerm}";

            try {
                $response = Http::timeout(8)
                    ->withHeaders([
                        'X-Subscription-Token' => $apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->get('https://api.search.brave.com/res/v1/web/search', [
                        'q' => $query,
                        'count' => 3,
                    ]);

                if (! $response->ok()) {
                    $results[$index] = null;
                    continue;
                }

                $webResults = $response->json('web.results', []);
                $firstValidUrl = null;

                foreach ($webResults as $webResult) {
                    $pageUrl = $webResult['url'] ?? null;
                    if (! $pageUrl) { continue; }

                    if (preg_match('#/product-lines/[^/]+/?$|/category/[^/]+/?$|/collections/[^/]+/?$|/c/[^/]+/?$#i', $pageUrl)) {
                        continue;
                    }

                    $resultTitle   = $webResult['title'] ?? '';
                    $resultSnippet = $webResult['description'] ?? '';
                    if ($this->scorePageRelevance($pageUrl, $resultTitle, $resultSnippet, $parsed) < 3) {
                        continue;
                    }

                    $typeDiscriminators = ['valve', 'seat', 'lavatory', 'faucet', 'bidet', 'urinal', 'showerhead', 'toilet'];
                    $requiredTypes      = array_intersect($parsed['keywords'], $typeDiscriminators);
                    if (! empty($requiredTypes)) {
                        $titleLower  = strtolower($resultTitle . ' ' . $resultSnippet . ' ' . $pageUrl);
                        $typeMatched = false;
                        foreach ($requiredTypes as $tw) {
                            if (str_contains($titleLower, $tw)) { $typeMatched = true; break; }
                        }
                        if (! $typeMatched) { continue; }
                    }

                    if ($parsed['line']) {
                        $combined = strtolower($resultTitle . ' ' . $resultSnippet . ' ' . $pageUrl);
                        if (! str_contains($combined, strtolower($parsed['line']))) { continue; }
                    }

                    if ($parsed['model']) {
                        $combined      = strtolower($resultTitle . ' ' . $resultSnippet . ' ' . $pageUrl);
                        $modelLower    = strtolower($parsed['model']);
                        $modelClean    = str_replace('-', '', $modelLower);
                        $combinedClean = str_replace('-', '', $combined);
                        if (! str_contains($combined, $modelLower) && ! str_contains($combinedClean, $modelClean)) {
                            continue;
                        }
                    }

                    if ($firstValidUrl === null) { $firstValidUrl = $pageUrl; }

                    $imageUrl = $this->extractImageFromProductPage($pageUrl);
                    if ($imageUrl && $imageUrl !== false) {
                        $results[$index] = ['image_url' => $imageUrl, 'product_url' => $pageUrl];
                        break;
                    }
                }

                if (! isset($results[$index])) {
                    if ($firstValidUrl) {
                        $results[$index] = ['image_url' => null, 'product_url' => $firstValidUrl];
                    } else {
                        $results[$index] = $this->searchVendorSiteViaAzure($host, $item);
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('horizon')->debug('ScrapeReceiptItemImages: Vendor site search failed', [
                    'host' => $host,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
        */
    }

    /**
     * Ask Azure OpenAI to retrieve the main product image from a known product page URL.
     * Used for Cloudflare-protected sites (kbauthority.com etc.) where HTTP scraping fails.
     * Azure web_search_preview can access these pages and return the image URL directly.
     *
     * @return ?array{image_url: ?string}
     */
    private function fetchImageFromKnownUrl(string $host, string $productUrl, array $item): ?array
    {
        $endpoint = config('services.azure_cu.endpoint');
        $apiKey   = config('services.azure_cu.api_key');

        if (! $endpoint || ! $apiKey) {
            return null;
        }

        $prompt = "Go to this product page: {$productUrl}\n\n"
                . "Find the main product image that is hosted on {$host} (not a third-party CDN like homedepot.com, amazon.com, etc.).\n"
                . "Return the direct image URL from {$host} only.\n"
                . "Return ONLY a JSON object with no extra text:\n"
                . '{"image_url":"https://..."}' . "\n\n"
                . 'If no image hosted on ' . $host . ' is found: {"image_url":null}';

        try {
            $response = Http::timeout(15)
                ->withHeaders(['api-key' => $apiKey, 'Content-Type' => 'application/json'])
                ->post("https://{$endpoint}/openai/responses?api-version=2025-03-01-preview", [
                    'model'       => 'gpt-4o-mini',
                    'input'       => $prompt,
                    'tools'       => [['type' => 'web_search_preview']],
                    'tool_choice' => 'required',
                    'stream'      => false,
                ]);

            if (! $response->ok()) {
                return null;
            }

            $result   = $this->parseSearchResponse($response);
            $imageUrl = $result['image_url'] ?? null;

            if (! $imageUrl || ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return null;
            }

            // Reject images from third-party domains — must be from the same host
            $imageHost = parse_url($imageUrl, PHP_URL_HOST) ?: '';
            if (! str_ends_with($imageHost, $host)) {
                return null;
            }

            return ['image_url' => $imageUrl];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Azure OpenAI fallback for searchVendorSite when Brave site: search returns no indexed results.
     * Sends a site-specific prompt so the model searches for the product exclusively on $host.
     *
     * @return ?array{image_url: ?string, product_url: string}
     */
    private function searchVendorSiteViaAzure(string $host, array $item): ?array
    {
        $endpoint = config('services.azure_cu.endpoint');
        $apiKey   = config('services.azure_cu.api_key');

        if (! $endpoint || ! $apiKey) {
            return null;
        }

        $mfrPart = $item['ManufacturerPartNumber'] ?? null;
        $name    = $item['name'] ?? $item['Description'] ?? null;
        $parsed  = $this->parseProductDescription($name ?? '');

        if ($mfrPart && ! ctype_digit(str_replace(['-', ' '], '', $mfrPart))) {
            $parsed['model'] = $mfrPart;
        }

        $searchTerm = $this->buildSmartQuery($parsed, includeFinish: true) ?? $mfrPart ?? $name;

        if (! $searchTerm) {
            return null;
        }

        $prompt = "Find the product page URL on {$host} for this product: {$searchTerm}\n\n"
                . "Search specifically on {$host} — only return a URL from that domain.\n"
                . "Also look for the main product image URL on that page.\n"
                . "Return ONLY a JSON object with no extra text:\n"
                . '{"product_url":"https://...","image_url":"https://...or null"}' . "\n\n"
                . 'If product not found: {"product_url":null,"image_url":null}';

        try {
            $response = Http::timeout(15)
                ->withHeaders(['api-key' => $apiKey, 'Content-Type' => 'application/json'])
                ->post("https://{$endpoint}/openai/responses?api-version=2025-03-01-preview", [
                    'model'       => 'gpt-4o-mini',
                    'input'       => $prompt,
                    'tools'       => [['type' => 'web_search_preview']],
                    'tool_choice' => 'required',
                    'stream'      => false,
                ]);

            if (! $response->ok()) {
                return null;
            }

            $result  = $this->parseSearchResponse($response);
            $pageUrl  = $result['product_url'] ?? null;
            $imageUrl = $result['image_url'] ?? null;

            if (! $pageUrl) {
                return null;
            }

            // Ensure the returned URL actually belongs to the target host
            $resultHost = parse_url($pageUrl, PHP_URL_HOST) ?: '';
            if (! str_ends_with($resultHost, $host)) {
                return null;
            }

            // Validate the URL contains the MPN (model number) to catch truncated slugs
            if ($mfrPart && ! ctype_digit(str_replace(['-', ' '], '', $mfrPart))) {
                $urlLower = strtolower($pageUrl);
                $mpnLower = strtolower(str_replace(' ', '-', $mfrPart));
                if (! str_contains($urlLower, $mpnLower)) {
                    return null;
                }
            }

            // If Azure didn't return an image, try HTTP scraping (works for non-Cloudflare sites)
            if (! $imageUrl) {
                $scraped  = $this->extractImageFromProductPage($pageUrl);
                $imageUrl = ($scraped && $scraped !== false) ? $scraped : null;
            }

            return [
                'image_url'   => $imageUrl,
                'product_url' => $pageUrl,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Search for product pages on the manufacturer's own website using Brave Web Search.
     * Uses the SKU to find the manufacturer's product page (not a retailer), then extracts the image.
     *
     * @param  array<int, array{sku: ?string, name: ?string}>  $items
     * @return array<int, ?array{image_url: ?string, product_url: ?string}>
     */
    private function searchManufacturerSite(array $items, ?string $vendorWebsite): array
    {
        $apiKey = config('services.brave_search.api_key');

        if (! $apiKey) {
            return [];
        }

        $vendorHost = $vendorWebsite ? (parse_url($vendorWebsite, PHP_URL_HOST) ?: null) : null;

        $retailerDomains = self::RETAILER_DOMAINS;

        $results = [];

        // Track domains that return empty/blocked pages to skip retries across items
        $blockedDomains = [];

        foreach ($items as $index => $item) {
            $sku = $item['sku'] ?? null;
            $name = $item['name'] ?? null;

            if (! $sku && ! $name) {
                $results[$index] = null;

                continue;
            }

            // Build a smart query from parsed product attributes.
            // Never use numeric-only SKUs (internal vendor warehouse codes) in external web searches.
            $parsed = $this->parseProductDescription($name ?? '');
            $query  = $this->buildSmartQuery($parsed, includeFinish: true);

            // Fall back to non-numeric SKU + brief name excerpt
            if (! $query) {
                if ($sku && ! ctype_digit($sku)) {
                    $query = $sku . ($name ? ' ' . Str::words($name, 3, '') : '');
                } else {
                    $results[$index] = null;

                    continue;
                }
            }

            try {
                $response = Http::timeout(8)
                    ->withHeaders([
                        'X-Subscription-Token' => $apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->get('https://api.search.brave.com/res/v1/web/search', [
                        'q' => $query,
                        'count' => 8,
                    ]);

                if (! $response->ok()) {
                    $results[$index] = null;

                    continue;
                }

                $webResults = $response->json('web.results', []);

                foreach ($webResults as $webResult) {
                    $pageUrl = $webResult['url'] ?? null;

                    if (! $pageUrl) {
                        continue;
                    }

                    $resultHost = parse_url($pageUrl, PHP_URL_HOST);

                    if (! $resultHost) {
                        continue;
                    }

                    // Skip vendor domain — already searched in Phase 2
                    if ($vendorHost && Str::endsWith($resultHost, $vendorHost)) {
                        continue;
                    }

                    // Skip retailers and aggregators — we want the manufacturer
                    $isRetailer = false;
                    foreach ($retailerDomains as $domain) {
                        if (Str::endsWith($resultHost, $domain)) {
                            $isRetailer = true;

                            break;
                        }
                    }

                    if ($isRetailer) {
                        continue;
                    }

                    // Skip regional/country-localized manufacturer sites — they return template
                    // images or locale-specific URLs (e.g. la.kohler.com, au.kohler.com, de.kohler.com).
                    if (preg_match('/^[a-z]{2}\.kohler\.com$/i', $resultHost)) {
                        continue;
                    }

                    // Skip category/collection pages
                    if (preg_match('#/product-lines/[^/]+/?$|/category/[^/]+/?$|/collections/[^/]+/?$|/c/[^/]+/?$#i', $pageUrl)) {
                        continue;
                    }

                    // Skip domains already known to be WAF-blocked (Incapsula, etc.)
                    $rootDomain = implode('.', array_slice(explode('.', $resultHost), -2));
                    if (isset($blockedDomains[$rootDomain])) {
                        continue;
                    }

                    // Validate relevance before visiting — rejects medfitcenter.org-type results
                    $resultTitle    = $webResult['title'] ?? '';
                    $resultSnippet  = $webResult['description'] ?? '';
                    $relevanceScore = $this->scorePageRelevance($pageUrl, $resultTitle, $resultSnippet, $parsed);
                    if ($relevanceScore < 5) {
                        continue;
                    }

                    // Reject domains that are clearly not product commerce sites
                    if (! $this->isLikelyProductDomain($resultHost, $resultTitle . ' ' . $resultSnippet)) {
                        continue;
                    }

                    // Require type-discriminator words in title/snippet (e.g. "valve" for "valve trim")
                    $typeDiscriminators = ['valve', 'seat', 'lavatory', 'faucet', 'bidet', 'urinal', 'showerhead', 'toilet'];
                    $requiredTypes      = array_intersect($parsed['keywords'], $typeDiscriminators);
                    if (! empty($requiredTypes)) {
                        $titleLower  = strtolower($resultTitle . ' ' . $resultSnippet . ' ' . $pageUrl);
                        $typeMatched = false;
                        foreach ($requiredTypes as $tw) {
                            if (str_contains($titleLower, $tw)) {
                                $typeMatched = true;
                                break;
                            }
                        }
                        if (! $typeMatched) {
                            continue;
                        }
                    }

                    $imageUrl = $this->extractImageFromProductPage($pageUrl);

                    if ($imageUrl && $imageUrl !== false) {
                        $results[$index] = [
                            'image_url' => $imageUrl,
                            'product_url' => $pageUrl,
                        ];

                        break;
                    }

                    // null = page loaded but no images (likely WAF/Incapsula) — block domain
                    if ($imageUrl === null) {
                        $blockedDomains[$rootDomain] = true;
                    }
                }

                if (! isset($results[$index])) {
                    $results[$index] = null;
                }
            } catch (\Throwable $e) {
                Log::channel('horizon')->debug('ScrapeReceiptItemImages: Manufacturer site search failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
    }

    /**
     * Search Brave Web Search API for product pages.
     * Uses parsed product descriptions and relevance scoring to find the best product page URL.
     *
     * @param  array<int, array{sku: ?string, name: ?string}>  $items
     * @return array<int, ?array{product_url: ?string}>
     */
    private function braveProductSearch(array $items, ?string $manufacturerName, ?string $color = null): array
    {
        $apiKey = config('services.brave_search.api_key');

        if (! $apiKey) {
            return [];
        }

        $results = [];

        foreach ($items as $index => $item) {
            $name = $item['name'] ?? $item['Description'] ?? null;
            $sku = $item['sku'] ?? $item['VendorCode'] ?? $item['ProductCode'] ?? null;

            if (! $name && ! $sku) {
                $results[$index] = null;
                continue;
            }

            $parsed = $this->parseProductDescription($name ?? '');
            // ManufacturerPartNumber is more precise than anything we can parse from Description
            $mfrPart = $item['ManufacturerPartNumber'] ?? null;
            if ($mfrPart && ! ctype_digit(str_replace(['-', ' '], '', $mfrPart))) {
                $parsed['model'] = $mfrPart;
            }

            // Override brand with manufacturer if not detected
            if (! $parsed['brand'] && $manufacturerName) {
                $parsed['brand'] = $manufacturerName;
            }

            // Override finish with color hint if provided and not detected
            if (! $parsed['finish'] && $color) {
                $parsed['finish'] = $color;
            }

            // Build search query — prefer model number, then SKU with context
            $query = null;
            if ($parsed['model'] && $parsed['brand']) {
                // Best case: brand + model number (e.g. "Brizo 65365LF-GLLHP")
                $query = $parsed['brand'] . ' ' . $parsed['model'];
                if ($parsed['line']) {
                    $query .= ' ' . $parsed['line'];
                }
            }
            if (! $query && $sku) {
                // Use vendor/brand + SKU + product type keywords for context
                $prefix = $parsed['brand'] ?: $manufacturerName ?: '';
                $typeHints = implode(' ', array_slice($parsed['keywords'], 0, 3));
                $query = trim("{$prefix} {$sku} {$typeHints}");
            }
            if (! $query) {
                $query = $this->buildSmartQuery($parsed);
            }

            if (! $query) {
                $results[$index] = null;
                continue;
            }

            try {
                $response = Http::timeout(8)
                    ->withHeaders([
                        'X-Subscription-Token' => $apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->get('https://api.search.brave.com/res/v1/web/search', [
                        'q' => $query,
                        'count' => 10,
                    ]);

                if (! $response->ok()) {
                    Log::channel('horizon')->debug('ScrapeReceiptItemImages: Brave Product Search failed', [
                        'status' => $response->status(),
                        'query' => $query,
                    ]);
                    $results[$index] = null;
                    continue;
                }

                $webResults = $response->json('web.results', []);

                // Track specialty/manufacturer results separately from mass retailers.
                // Amazon, Home Depot, etc. are only used when no specialty site scored well.
                $bestNonRetailerUrl   = null;
                $bestNonRetailerScore = 0;
                $bestRetailerUrl      = null;
                $bestRetailerScore    = 0;

                foreach ($webResults as $webResult) {
                    $url = $webResult['url'] ?? '';
                    $title = $webResult['title'] ?? '';
                    $snippet = $webResult['description'] ?? '';
                    $host = parse_url($url, PHP_URL_HOST) ?: '';

                    if (! $this->isLikelyProductDomain($host, $title . ' ' . $snippet)) {
                        continue;
                    }

                    // Skip category/listing pages — only specific product pages are useful
                    if (! $this->isProductUrl($url)) {
                        continue;
                    }

                    $score = $this->scorePageRelevance($url, $title, $snippet, $parsed);

                    $isRetailer = false;
                    foreach (self::RETAILER_DOMAINS as $domain) {
                        if (Str::endsWith($host, $domain)) {
                            $isRetailer = true;
                            break;
                        }
                    }

                    if ($isRetailer) {
                        if ($score > $bestRetailerScore && $score >= 15) {
                            $bestRetailerScore = $score;
                            $bestRetailerUrl   = $url;
                        }
                    } else {
                        if ($score > $bestNonRetailerScore && $score >= 15) {
                            $bestNonRetailerScore = $score;
                            $bestNonRetailerUrl   = $url;
                        }
                    }
                }

                // Prefer specialty/manufacturer; only fall back to retailers when nothing else works
                $bestUrl = $bestNonRetailerUrl ?? $bestRetailerUrl;
                $results[$index] = $bestUrl ? ['product_url' => $bestUrl] : null;
            } catch (\Throwable $e) {
                Log::channel('horizon')->debug('ScrapeReceiptItemImages: Brave Product Search error', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
    }

    /**
     * Search Brave Image Search API for product images.
     * Fast (~200ms per query), returns direct image URLs and product page URLs.
     *
     * @param  array<int, array{sku: ?string, name: ?string}>  $items
     * @return array<int, ?array{image_url: ?string, product_url: ?string}>
     */
    private function braveImageSearch(array $items, ?string $vendorName, ?string $vendorWebsite = null): array
    {
        $apiKey = config('services.brave_search.api_key');

        if (! $apiKey) {
            return [];
        }

        $results = [];

        foreach ($items as $index => $item) {
            // Prefer ManufacturerPartNumber over internal SKU for more precise image searches
            $effectiveSku = $item['ManufacturerPartNumber'] ?? $item['sku'] ?? null;
            // Receipt items use Description, not name — fall back so queries include the product description
            $effectiveName = $item['name'] ?? $item['Description'] ?? null;
            $query = $this->buildBraveQuery($effectiveSku, $effectiveName, $vendorName, $item['Manufacturer'] ?? null);

            if (! $query) {
                $results[$index] = null;

                continue;
            }

            try {
                $response = Http::timeout(8)
                    ->withHeaders([
                        'X-Subscription-Token' => $apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->get('https://api.search.brave.com/res/v1/images/search', [
                        'q' => $query,
                        'count' => 5,
                        'safesearch' => 'off',
                    ]);

                if (! $response->ok()) {
                    Log::channel('horizon')->debug('ScrapeReceiptItemImages: Brave Image Search failed', [
                        'status' => $response->status(),
                        'query' => $query,
                    ]);
                    $results[$index] = null;

                    continue;
                }

                $imageResults = $response->json('results', []);
                $vendorHost = $vendorWebsite ? (parse_url($vendorWebsite, PHP_URL_HOST) ?: null) : null;
                $result = $this->pickBestBraveResult($imageResults, $effectiveName, $vendorHost, $effectiveSku);
                $results[$index] = $result;
            } catch (\Throwable $e) {
                Log::channel('horizon')->debug('ScrapeReceiptItemImages: Brave Image Search error', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
    }

    /**
     * Returns true if the URL points to a specific product page (not a category, listing, or search page).
     * Prevents accepting category pages as product_url, which causes wrong image scraping downstream.
     */
    private function isProductUrl(string $url): bool
    {
        // Reject category/search URLs
        if (preg_match(
            '#/(product-category|product-categories|catalog/category|categories|collections/browse|search|filter)[/\\?]#i',
            $url
        )) {
            return false;
        }
        // Reject kohler.com — manufacturer pages don't extract images well
        // (scene7 CDN) and frequently return facets/category pages.
        // Specialty sites (kbauthority, gnkitchenandbath) provide reliable URLs+images.
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (preg_match('#(^|\.)kohler\.com$#i', $host)) {
            return false;
        }
        return true;
    }

    /**
     * Build a search query for Brave Image Search.
     *
     * When $manufacturer + $sku are both available, returns a direct model search
     * (e.g. "Kohler K-25499-2MB") which is far more precise than building from
     * the noisy description text.
     */
    private function buildBraveQuery(?string $sku, ?string $name, ?string $vendorName, ?string $manufacturer = null): ?string
    {
        // Direct Manufacturer + MPN query — most reliable for items with known make/model
        if ($manufacturer && $sku && ! ctype_digit(str_replace(['-', ' '], '', $sku))) {
            return ucfirst(strtolower($manufacturer)) . ' ' . $sku;
        }

        $cleanName = '';
        if ($name) {
            // Normalize newlines/tabs so multiline descriptions don't break the query
            $name = preg_replace('/[\r\n\t]+/', ' ', $name);
            $cleanName = preg_replace('/^[\*\s]+/', '', $name);
            $cleanName = preg_replace('/["\'"″″\'\'`]+/', '', $cleanName);
            $cleanName = trim(preg_replace('/\s{2,}/', ' ', $cleanName));
        }

        $query = $cleanName ?: ($sku ? $sku . ' product' : null);

        if (! $query) {
            return null;
        }

        // Add SKU for more specific results
        if ($sku && $cleanName && ! str_contains($cleanName, $sku)) {
            $query = $sku . ' ' . $cleanName;
        }

        // Append "product" for better image results
        $query .= ' product';

        return $query;
    }

    /**
     * Pick the best image result from Brave search results.
     * Tiers: vendor domain → MPN-matched → specialty → major retailer.
     *
     * When a $sku (ManufacturerPartNumber) is supplied, any result whose title,
     * page URL, or image URL contains the normalized SKU is promoted above
     * generic specialty matches. This prevents "K-27946" being returned when
     * we searched for "K-32257".
     */
    private function pickBestBraveResult(array $imageResults, ?string $name, ?string $vendorHost = null, ?string $sku = null): ?array
    {
        if (empty($imageResults)) {
            return null;
        }

        $nameWords = $name ? array_filter(
            preg_split('/[\s:,\-\/]+/', strtolower(preg_replace('/^[\*\s]+/', '', $name))),
            fn ($w) => strlen($w) > 2 && ! is_numeric($w) && ! preg_match('/^\d+x\d+$/i', $w)
        ) : [];

        // Normalize SKU for loose matching (strip hyphens, lowercase)
        $skuNorm = $sku ? strtolower(str_replace('-', '', $sku)) : null;
        // Also build a base-model variant without the finish suffix (last hyphen segment).
        // Many product pages list K-32257-SC03 without the finish code -2GL, so we need
        // both variants to match: full SKU wins over base, but base beats no match.
        $skuBaseNorm = null;
        if ($sku) {
            $segments = explode('-', $sku);
            if (count($segments) > 2) {
                $base = strtolower(str_replace('-', '', implode('-', array_slice($segments, 0, -1))));
                if ($base !== $skuNorm) {
                    $skuBaseNorm = $base;
                }
            }
        }

        $skuMatch = null;        // any site where SKU appears in title/URL/image URL
        $specialtyMatch = null;  // manufacturer or plumbing specialty site
        $retailerMatch = null;   // major retailer (amazon, walmart, etc.)

        foreach ($imageResults as $result) {
            $pageUrl = $result['url'] ?? null;
            $title = strtolower($result['title'] ?? '');
            $source = strtolower($result['source'] ?? '');
            $imageUrl = $result['properties']['url'] ?? $result['thumbnail']['src'] ?? null;

            if (! $pageUrl || ! $imageUrl) {
                continue;
            }

            // Skip stock photo domains
            if (Str::contains($source, ['shutterstock', 'getty', 'istockphoto', 'alamy', 'depositphotos'])) {
                continue;
            }

            // Skip tiny thumbnails
            $width = $result['properties']['width'] ?? $result['thumbnail']['width'] ?? 0;
            if ($width > 0 && $width < 80) {
                continue;
            }

            // Require meaningful relevance — at least 2 word matches for descriptions with 4+ words.
            // Prevents matching unrelated pages that happen to share just 1 common word.
            if (! empty($nameWords)) {
                $matchCount = 0;
                foreach ($nameWords as $w) {
                    if (str_contains($title, $w) || str_contains(strtolower($pageUrl), $w)) {
                        $matchCount++;
                    }
                }

                $minMatches = count($nameWords) >= 4 ? 2 : 1;
                if ($matchCount < $minMatches) {
                    continue;
                }
            }

            $match = [
                'image_url' => $imageUrl,
                'product_url' => $pageUrl,
            ];

            $resultHost = parse_url($pageUrl, PHP_URL_HOST);

            // Tier 1: Vendor domain — return immediately
            if ($vendorHost && $resultHost && Str::endsWith($resultHost, $vendorHost)) {
                return $match;
            }

            // Tier 2 (new): SKU match — title, page URL, or image URL contains the normalized MPN.
            // Promoted above specialty/retailer classification to prevent wrong-model results.
            if ($skuNorm && ! $skuMatch) {
                $titleNorm    = strtolower(str_replace('-', '', $result['title'] ?? ''));
                $pageUrlNorm  = strtolower(str_replace('-', '', $pageUrl));
                $imageUrlNorm = strtolower(str_replace('-', '', $imageUrl));
                $matchesFull = str_contains($titleNorm, $skuNorm)
                    || str_contains($pageUrlNorm, $skuNorm)
                    || str_contains($imageUrlNorm, $skuNorm);
                // Fallback: match base model without finish suffix (e.g. K-32257-SC03 when
                // searching for K-32257-SC03-2GL, since merchants often omit the finish code)
                $matchesBase = ! $matchesFull && $skuBaseNorm && (
                    str_contains($titleNorm, $skuBaseNorm)
                    || str_contains($pageUrlNorm, $skuBaseNorm)
                    || str_contains($imageUrlNorm, $skuBaseNorm)
                );
                if ($matchesFull || $matchesBase) {
                    $skuMatch = $match;
                }
            }

            // Classify as retailer or specialty/manufacturer
            $isRetailer = false;
            if ($resultHost) {
                foreach (self::RETAILER_DOMAINS as $domain) {
                    if (Str::endsWith($resultHost, $domain)) {
                        $isRetailer = true;

                        break;
                    }
                }
            }

            // Tier 3: Manufacturer or specialty site
            if (! $isRetailer && ! $specialtyMatch) {
                $specialtyMatch = $match;
            }

            // Tier 4: Major retailer (last resort)
            if ($isRetailer && ! $retailerMatch) {
                $retailerMatch = $match;
            }
        }

        return $skuMatch ?? $specialtyMatch ?? $retailerMatch;
    }

    /**
     * Batch search for product images using parallel Azure OpenAI web_search_preview.
     * Processes items in chunks of 5 concurrently (~5s per batch instead of ~8s per item).
     *
     * @param  array<int, array{sku: ?string, name: ?string}>  $items  index => item data
     * @return array<int, ?array{image_url: ?string, product_url: ?string}>
     */
    private function batchSearchProductImages(array $items, ?string $vendorName): array
    {
        $endpoint = config('services.azure_cu.endpoint');
        $apiKey = config('services.azure_cu.api_key');

        if (! $endpoint || ! $apiKey) {
            return [];
        }

        $results = [];
        $chunks = array_chunk($items, 5, true);

        foreach ($chunks as $chunk) {
            $responses = Http::pool(fn ($pool) => collect($chunk)->map(fn ($item, $index) => $pool->as((string) $index)
                ->timeout(15)
                ->withHeaders(['api-key' => $apiKey, 'Content-Type' => 'application/json'])
                ->post("https://{$endpoint}/openai/responses?api-version=2025-03-01-preview", [
                    'model' => 'gpt-4o-mini',
                    'input' => $this->buildSearchPrompt($item['ManufacturerPartNumber'] ?? $item['sku'] ?? $item['VendorCode'] ?? $item['ProductCode'] ?? null, $item['name'] ?? $item['Description'] ?? null, $vendorName),
                    'tools' => [['type' => 'web_search_preview']],
                    'tool_choice' => 'required',
                    'stream' => false,
                ])
            )->all());

            foreach ($chunk as $index => $item) {
                $response = $responses[(string) $index] ?? null;

                if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->ok()) {
                    $results[$index] = null;

                    continue;
                }

                $result = $this->parseSearchResponse($response);
                $results[$index] = $result;
            }
        }

        return $results;
    }

    /**
     * Build a concise search prompt for a product.
     * Uses parseProductDescription to construct brand-aware queries.
     */
    private function buildSearchPrompt(?string $sku, ?string $name, ?string $vendorName): string
    {
        $cleanName = '';
        if ($name) {
            $cleanName = preg_replace('/^[\*\s]+/', '', $name);
            $cleanName = preg_replace('/["\'"″″\'\'`]+/', '', $cleanName);
            $cleanName = trim(preg_replace('/\s{2,}/', ' ', $cleanName));
        }

        // Use parsed description for smarter queries
        $parsed = $this->parseProductDescription($name ?? '');

        // Build a structured query from parsed data
        $queryParts = [];
        if ($parsed['brand']) {
            $queryParts[] = $parsed['brand'];
        }
        if ($parsed['line']) {
            $queryParts[] = $parsed['line'];
        }
        if ($parsed['model']) {
            $queryParts[] = $parsed['model'];
        }

        // Add product type keywords for context
        $typeWords = array_slice($parsed['keywords'], 0, 4);
        foreach ($typeWords as $kw) {
            $queryParts[] = $kw;
        }

        if ($parsed['finish']) {
            $queryParts[] = $parsed['finish'];
        }

        $smartQuery = implode(' ', $queryParts);

        // Fallback to raw description if parsing yielded nothing useful
        if (! $smartQuery) {
            $smartQuery = $cleanName ?: ($sku ?? 'unknown product');
        }

        // Add SKU only if it's a manufacturer model number (not a warehouse code like pure digits)
        if ($sku && ! ctype_digit($sku) && ! str_contains($smartQuery, $sku)) {
            $smartQuery = "{$sku} {$smartQuery}";
        }

        $brandHint = $parsed['brand'] ? " by {$parsed['brand']}" : '';

        return "Find the product page URL for this plumbing/bath product{$brandHint}: {$smartQuery}\n\n"
             . "Prefer the manufacturer's website (e.g. brizo.com, kohler.com, moen.com) or specialty bath/kitchen retailers (kbauthority.com, fergusonhome.com, gnkitchenandbath.com, qualitybath.com, faucetdirect.com, build.com). Use amazon.com, homedepot.com, or lowes.com only if no other source exists.\n"
             . "Return ONLY a JSON object with the product page URL and a direct image URL:\n"
             . "{\"image_url\":\"https://...\",\"product_url\":\"https://...\"}\n\n"
             . "If not found: {\"image_url\":null,\"product_url\":null}";
    }

    /**
     * Parse an Azure OpenAI web search response to extract image and product URLs.
     */
    private function parseSearchResponse($response): ?array
    {
        $imageUrl = null;
        $productUrl = null;
        $annotations = [];

        foreach ($response->json('output', []) as $output) {
            if (($output['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($output['content'] ?? [] as $content) {
                $text = $content['text'] ?? '';

                // Strip markdown code fences
                $cleanText = preg_replace('/^```(?:json)?\s*/m', '', $text);
                $cleanText = preg_replace('/\s*```\s*$/m', '', $cleanText);

                // Try to parse as JSON
                $decoded = json_decode(trim($cleanText), true);
                if (is_array($decoded)) {
                    $imageUrl = $decoded['image_url'] ?? null;
                    $productUrl = $decoded['product_url'] ?? null;
                }

                // Fall back to extracting any URL from text
                if (! $productUrl && preg_match('#https?://[^\s\]\)\"<>]+#', strip_tags($text), $urlMatch)) {
                    $productUrl = rtrim($urlMatch[0], '.,;');
                }

                // Collect URL citations as additional sources
                foreach ($content['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation' && ! empty($annotation['url'])) {
                        $annotations[] = $annotation['url'];
                    }
                }
            }
        }

        // Prefer annotation URL over text-extracted URL
        $productUrl = $annotations[0] ?? $productUrl;

        if (! $productUrl || Str::contains($productUrl, ['bing.com', 'google.com', 'duckduckgo', 'NONE'])) {
            return null;
        }

        // Reject non-product domains (streaming, social media, etc.)
        $productHost = parse_url($productUrl, PHP_URL_HOST) ?: '';
        if (! $this->isLikelyProductDomain($productHost)) {
            return null;
        }

        // Reject collection/category/listing pages — want individual product pages
        if (preg_match('#
            /product-lines/[^/]+/?$  |
            /category/[^/]+/?$       |
            /collections/[^/]+/?$    |
            /c/[^/]+/?$              |
            /stores/                 |
            /s\?                     |
            /pl/                     |
            /b/                      |
            /dealers/                |
            /support/                |
            /find-a-                 |
            \.pdf(\?|$)              |
            /page/[A-Z0-9-]+$       |
            /shop-[a-z-]+/?$         |
            prefn\d=                 |
            rh=n:                    |
            merchant-items
        #ix', $productUrl)) {
            return null;
        }

        // Reject bare homepages
        $productPath = parse_url($productUrl, PHP_URL_PATH) ?: '/';
        if ($productPath === '/' || $productPath === '') {
            return null;
        }

        // If we got an image URL from the AI response, validate it looks real
        if ($imageUrl && ! preg_match('#^https?://.+\.(jpe?g|png|webp|gif)#i', $imageUrl)) {
            // Not a direct image URL — try to extract from the product page instead
            $imageUrl = null;
        }

        // If no image URL from AI, try to extract from the product page
        if (! $imageUrl) {
            try {
                $extracted = $this->extractImageFromProductPage($productUrl);
                if ($extracted && $extracted !== false) {
                    $imageUrl = $extracted;
                }
                // Don't discard product_url when page can't be fetched (SPA sites like brizo.com)
            } catch (\Throwable $e) {
                // Page extraction failed (timeout, connection error, etc.) — keep the product URL
            }
        }

        return array_filter([
            'image_url' => $imageUrl,
            'product_url' => $productUrl,
        ]) ?: null;
    }

    /**
     * Search WooCommerce Store API for product by name/SKU.
     * Works for vendors running WooCommerce (e.g. s41tradeconnect.com).
     */
    private function searchWooCommerceApi(?string $sku, ?string $name, ?string $vendorWebsite): ?array
    {
        if (! $vendorWebsite) {
            return null;
        }

        $host = parse_url($vendorWebsite, PHP_URL_HOST) ?: $vendorWebsite;
        $baseUrl = "https://{$host}/wp-json/wc/store/products";

        // Parse the description for structured attributes used in query building and result validation
        $parsed = $this->parseProductDescription($name ?? '');
        // ManufacturerPartNumber is more precise than anything we can parse from Description
        $mfrPart = $sku && ! ctype_digit(str_replace(['-', ' '], '', $sku)) ? $sku : null;
        // Note: $sku here is passed from outside — check if the raw ManufacturerPartNumber was passed
        // (searchWooCommerceApi is called with $item['ManufacturerPartNumber'] as sku when available)
        if ($mfrPart && ! $parsed['model']) {
            $parsed['model'] = $mfrPart;
        }

        // Build search queries in priority order — most specific first
        $queries = [];

        // 1. Brand + model (base, no finish suffix) — best for WooCommerce search
        if ($parsed['model']) {
            // Strip finish/variant suffixes.
            // Kohler-style K-702429-L-2MB → K-702429, K-TS35920-4-2MB → K-TS35920
            // (keep K- plus any optional letter-prefix then primary digit block).
            // Standard finish suffix: 65365LF-GLLHP → 65365LF (strip trailing alpha block).
            if (preg_match('/^(K-[A-Z]{0,2}\d+)/i', $parsed['model'], $km)) {
                $baseModel = $km[1];
            } else {
                $baseModel = preg_replace('/-[A-Z]{2,}$/', '', $parsed['model']);
            }
            if ($parsed['brand']) {
                $queries[] = $parsed['brand'] . ' ' . $baseModel;
            }
            $queries[] = $baseModel;
        }

        // 2. Brand + line name (e.g. "Brizo Beauclere")
        if ($parsed['brand'] && $parsed['line']) {
            $queries[] = $parsed['brand'] . ' ' . $parsed['line'];
        }

        // 3. Smart query (full) with and without finish
        $smartQueryWithFinish = $this->buildSmartQuery($parsed, includeFinish: true);
        $smartQueryNoFinish   = $this->buildSmartQuery($parsed, includeFinish: false);
        if ($smartQueryWithFinish && ! in_array($smartQueryWithFinish, $queries)) {
            $queries[] = $smartQueryWithFinish;
        }
        if ($smartQueryNoFinish && $smartQueryNoFinish !== $smartQueryWithFinish && ! in_array($smartQueryNoFinish, $queries)) {
            $queries[] = $smartQueryNoFinish;
        }

        // 3b. Line name alone (WooCommerce often fails on multi-word queries)
        if ($parsed['line'] && ! in_array($parsed['line'], $queries)) {
            $queries[] = $parsed['line'];
        }

        // 4. Full description as fallback
        if ($name) {
            $cleanName = preg_replace('/^[\\*\\s]+/', '', $name);
            $cleanName = preg_replace('/["\'\'`]+/', '', $cleanName);
            $cleanName = trim(preg_replace('/\s{2,}/', ' ', $cleanName));
            if (! in_array($cleanName, $queries)) {
                $queries[] = $cleanName;
            }
        }
        if ($sku && ! ctype_digit($sku)) {
            $queries[] = $sku;
        }

        foreach ($queries as $query) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; HiveBot/1.0)'])
                    ->get($baseUrl, ['search' => $query, 'per_page' => 3]);

                if (! $response->ok()) {
                    return null; // API not available on this host
                }

                $products = $response->json();
                if (empty($products)) {
                    continue;
                }

                // Find best matching product with strict relevance checks
                $bestMatch = null;
                $bestScore = 0;

                foreach ($products as $product) {
                    $productUrl = $product['permalink'] ?? null;
                    $images = $product['images'] ?? [];
                    $imageUrl = ! empty($images) ? ($images[0]['src'] ?? null) : null;

                    if (! $productUrl || ! $imageUrl) {
                        continue;
                    }

                    $productName = strtolower(strip_tags($product['name'] ?? ''));
                    $productSku  = strtolower($product['sku'] ?? '');

                    // --- Gate 1: Line name must match as a whole word ---
                    if ($parsed['line'] && ! preg_match('/\b' . preg_quote(strtolower($parsed['line']), '/') . '\b/', $productName)) {
                        continue;
                    }

                    // --- Gate 2: Finish must not conflict ---
                    if ($parsed['finish'] && $this->hasConflictingFinish($productName, $parsed['finish'])) {
                        continue;
                    }

                    // --- Gate 3: Product-type discrimination ---
                    // Expanded list of words that distinguish fundamentally different products.
                    // ALL matching type words from the search description must appear in the product name.
                    $typeDiscriminators = [
                        'valve', 'seat', 'lavatory', 'faucet', 'bidet', 'urinal', 'tub',
                        'showerhead', 'toilet', 'shower', 'arm', 'flange', 'hose', 'bowl',
                        'tank', 'trim', 'handle', 'column', 'bar', 'rough', 'handshower',
                        'filler', 'spout', 'drain', 'lever', 'diverter', 'volume', 'thermostatic',
                    ];
                    $requiredTypes = array_intersect($parsed['keywords'], $typeDiscriminators);

                    if (! empty($requiredTypes)) {
                        // Require ALL type-discriminator keywords to appear as whole words in the product name
                        $allTypesMatched = true;
                        foreach ($requiredTypes as $tw) {
                            if (! preg_match('/\b' . preg_quote($tw, '/') . '\b/', $productName)) {
                                $allTypesMatched = false;
                                break;
                            }
                        }
                        if (! $allTypesMatched) {
                            continue;
                        }
                    }

                    // --- Gate 3b: Reject products with EXTRA type-discriminator words not in our item ---
                    // e.g. we want a "toilet" — reject "toilet tank", "toilet seat" (tank/seat not in our item)
                    if (! empty($requiredTypes)) {
                        $extraTypes = false;
                        foreach ($typeDiscriminators as $td) {
                            if (! in_array($td, $requiredTypes) && preg_match('/\b' . preg_quote($td, '/') . '\b/', $productName)) {
                                $extraTypes = true;
                                break;
                            }
                        }
                        if ($extraTypes) {
                            continue;
                        }
                    }

                    // --- Gate 4: Keyword overlap scoring ---
                    // Count how many of our description keywords appear in the product name
                    $allKeywords = $parsed['keywords'];
                    $matchCount = 0;
                    foreach ($allKeywords as $kw) {
                        if (strlen($kw) > 2 && preg_match('/\b' . preg_quote($kw, '/') . '\b/', $productName)) {
                            $matchCount++;
                        }
                    }

                    // Require at least 50% keyword overlap when we have keywords
                    $kwCount = count(array_filter($allKeywords, fn ($w) => strlen($w) > 2));
                    if ($kwCount > 0 && $matchCount < max(1, (int) ceil($kwCount * 0.5))) {
                        continue;
                    }

                    // --- Gate 5: SKU/model match bonus ---
                    $score = $matchCount;
                    if ($parsed['model'] && str_contains($productSku, strtolower($parsed['model']))) {
                        $score += 10; // Strong signal
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = [
                            'image_url' => $imageUrl,
                            'product_url' => $productUrl,
                        ];
                    }
                }

                if ($bestMatch) {
                    return $bestMatch;
                }
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw $e; // Let caller handle unreachable sites
            } catch (\Throwable $e) {
                Log::channel('horizon')->debug('ScrapeReceiptItemImages: WooCommerce API search failed', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);

                return null; // Don't retry if API errors
            }
        }

        return null;
    }

    /**
     * Discover a vendor's website using Brave Web Search.
     */
    private function discoverVendorWebsite(string $vendorName): ?string
    {
        $apiKey = config('services.brave_search.api_key');

        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'X-Subscription-Token' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => "{$vendorName} official website building materials",
                    'count' => 3,
                ]);

            if (! $response->ok()) {
                return null;
            }

            foreach ($response->json('web.results', []) as $result) {
                $url = $result['url'] ?? null;

                if (! $url) {
                    continue;
                }

                // Skip search engines, directories, and social media
                if (Str::contains($url, ['google.com', 'bing.com', 'yelp.com', 'facebook.com', 'wikipedia', 'linkedin.com', 'bbb.org'])) {
                    continue;
                }

                // Normalize to homepage
                $parsed = parse_url($url);

                return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            }
        } catch (\Throwable $e) {
            Log::channel('horizon')->warning('ScrapeReceiptItemImages: vendor website discovery failed', [
                'vendor' => $vendorName,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Use Browsershot (headless Chrome) to render JS SPA product pages
     * and extract product images that aren't available via simple HTTP.
     *
     * @param  array<int, string>  $urls  Index => product page URL
     * @return array<int, ?string>  Index => extracted image URL or null
     */
    /**
     * Extract product images using Puppeteer with stealth plugin.
     *
     * Spawns a Node.js script that uses puppeteer-extra + stealth to bypass
     * bot protection on sites like homedepot.com, kohler.com, ferguson.com, walmart.com.
     *
     * @param  array<int, string>  $urls  index → product page URL
     * @return array<int, string|null>  index → extracted image URL (or null)
     */
    private function extractImagesWithBrowsershot(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        $nodePath = env('NODE_PATH', 'node');
        $chromePath = env('CHROME_PATH');
        $scriptPath = base_path('scripts/product-image-scraper.cjs');

        // Build config for the Node.js scraper
        $configData = [
            'urls' => array_map('strval', $urls),
            'headless' => true,
            'delayMs' => 3000,
            'timeoutMs' => 20000,
        ];

        if ($chromePath) {
            $configData['chromePath'] = $chromePath;
        }

        $anticaptchaKey = config('services.anticaptcha.api_key');
        $twocaptchaKey = config('services.twocaptcha.api_key');

        if ($anticaptchaKey) {
            $configData['captchaApiKey'] = $anticaptchaKey;
        } elseif ($twocaptchaKey) {
            $configData['twoCaptchaKey'] = $twocaptchaKey;
        }

        // Write temp config file
        $configFile = storage_path('app/product-image-scraper-' . uniqid() . '.json');
        file_put_contents($configFile, json_encode($configData));

        try {
            $command = escapeshellarg($nodePath) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($configFile);

            Log::channel('horizon')->debug('ScrapeReceiptItemImages: Starting Puppeteer stealth extraction', [
                'count' => count($urls),
                'command' => Str::limit($command, 200),
            ]);

            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout (JSON results)
                2 => ['pipe', 'w'],  // stderr (logs)
            ];

            $process = proc_open($command, $descriptors, $pipes);

            if (! is_resource($process)) {
                Log::channel('horizon')->error('ScrapeReceiptItemImages: Failed to spawn Puppeteer process');

                return array_fill_keys(array_keys($urls), null);
            }

            fclose($pipes[0]); // Close stdin

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($stderr) {
                Log::channel('horizon')->debug('ScrapeReceiptItemImages: Puppeteer stderr', [
                    'stderr' => Str::limit($stderr, 1000),
                ]);
            }

            if ($exitCode !== 0) {
                Log::channel('horizon')->error('ScrapeReceiptItemImages: Puppeteer process exited with code ' . $exitCode);

                return array_fill_keys(array_keys($urls), null);
            }

            $parsed = json_decode($stdout, true);

            if (! is_array($parsed)) {
                Log::channel('horizon')->error('ScrapeReceiptItemImages: Puppeteer returned invalid JSON', [
                    'stdout' => Str::limit($stdout, 500),
                ]);

                return array_fill_keys(array_keys($urls), null);
            }

            // Validate each extracted image URL
            $results = [];
            foreach ($urls as $index => $url) {
                $imageUrl = $parsed[(string) $index] ?? null;

                if ($imageUrl && $this->isImageAccessible($imageUrl)) {
                    $results[$index] = $imageUrl;
                } else {
                    $results[$index] = null;
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::channel('horizon')->error('ScrapeReceiptItemImages: Puppeteer extraction failed', [
                'error' => Str::limit($e->getMessage(), 200),
            ]);

            return array_fill_keys(array_keys($urls), null);
        } finally {
            @unlink($configFile);
        }
    }

    /**
     * Parse a product description into structured attributes: brand, line, model, finish, keywords.
     * These drive smart search query construction and relevance scoring.
     *
     * @return array{brand: ?string, line: ?string, model: ?string, finish: ?string, keywords: string[]}
     */
    private function parseProductDescription(string $name): array
    {
        // Normalize newlines to spaces and collapse " -" into "-" to preserve variant suffixes
        // e.g. "HYDRORAIL\n-S-SHOWER" → "HYDRORAIL-S-SHOWER"
        $name = str_replace(["\r\n", "\r", "\n"], ' ', $name);
        $name = preg_replace('/\s+-/', '-', $name);
        $name = preg_replace('/\s{2,}/', ' ', trim($name));

        // Strip *TAG: category labels before processing — these are Studio 41 / POS category
        // tags prepended to the description (e.g. "*TAG: LAV SINK & FAUCET *TAG: MIRRORS").
        // They describe the product category, not the product itself, so they produce
        // false-positive matches when used as search queries.
        $name = preg_replace('/\*TAG:\s*[^*]+/i', ' ', $name);
        $name = trim(preg_replace('/\s{2,}/', ' ', $name));

        $upper = strtoupper($name);

        // Known plumbing/building brands — multi-word brands checked first
        $brands = [
            'AMERICAN STANDARD'  => 'American Standard',
            'CHICAGO FAUCETS'    => 'Chicago Faucets',
            'NEWPORT BRASS'      => 'Newport Brass',
            'MOUNTAIN PLUMBING'  => 'Mountain Plumbing',
            'SIGNATURE HARDWARE' => 'Signature Hardware',
            'PRICE PFISTER'      => 'Pfister',
            'HANSGROHE'          => 'Hansgrohe',
            'WATERMARK'          => 'Watermark',
            'KALLISTA'           => 'Kallista',
            'SPEAKMAN'           => 'Speakman',
            'SYMMONS'            => 'Symmons',
            'BRASSTECH'          => 'Brasstech',
            'JACUZZI'            => 'Jacuzzi',
            'PFISTER'            => 'Pfister',
            'LACAVA'             => 'LaCava',
            'PHYLRICH'           => 'Phylrich',
            'KOHLER'             => 'Kohler',
            'GROHE'              => 'Grohe',
            'ROHL'               => 'Rohl',
            'DELTA'              => 'Delta',
            'MOEN'               => 'Moen',
            'TOTO'               => 'Toto',
            'GRAFF'              => 'Graff',
            'BRIZO'              => 'Brizo',
            'ELJER'              => 'Eljer',
            'DANZE'              => 'Danze',
            'BERTCH'             => 'Bertch',
            'EMSER'              => 'Emser',
            'BLANKE'             => 'Blanke',
        ];

        // Product line → brand mapping (allows brand inference from line name alone)
        $lineToBrand = [
            // Brizo lines (variant-specific entries before base names)
            'SENSORIPLUS' => 'Brizo',
            'BEAUCLERE'  => 'Brizo', 'SENSORI'    => 'Brizo', 'KINTSU'  => 'Brizo',
            'LITZE'      => 'Brizo', 'ODIN'       => 'Brizo', 'VESI'    => 'Brizo',
            'ROOK'       => 'Brizo', 'SOTRIA'     => 'Brizo', 'INVARI'  => 'Brizo',
            'TULHAM'     => 'Brizo', 'JASON WU'   => 'Brizo', 'SIDERNA' => 'Brizo',
            'VIRAGE'     => 'Brizo', 'ALLARIA'    => 'Brizo', 'FRANK LLOYD WRIGHT' => 'Brizo',
            // Kohler lines
            'CORBELLE'     => 'Kohler', 'CAXTON'       => 'Kohler', 'DEVONSHIRE'   => 'Kohler',
            'HIGHLINE'     => 'Kohler', 'CIMARRON'     => 'Kohler', 'MEMOIRS'      => 'Kohler',
            'ARTIFACTS'    => 'Kohler', 'BANCROFT'     => 'Kohler', 'KATHRYN'      => 'Kohler',
            'TRESHAM'      => 'Kohler', 'AWAKEN'       => 'Kohler',
            'HYDRORAIL-S'  => 'Kohler', 'HYDRORAIL-R'  => 'Kohler', 'HYDRORAIL'    => 'Kohler',
            'MASTERSHOWER' => 'Kohler', 'COMPOSED'     => 'Kohler', 'PINSTRIPE'    => 'Kohler',
            'STILLNESS'    => 'Kohler', 'PURIST'       => 'Kohler', 'CERES'        => 'Kohler',
            'CERIC'        => 'Kohler', 'RITE-TEMP'    => 'Kohler', 'UNDERSCORE'   => 'Kohler',
            'ELMBROOK'     => 'Kohler', 'ESCALE'       => 'Kohler', 'WORTH'        => 'Kohler',
            'VEIL'         => 'Kohler', 'IRON WORKS'   => 'Kohler',
            'C3'           => 'Kohler',
            'CASTIA'       => 'Kohler', 'LEVITY'       => 'Kohler', 'MALIN'        => 'Kohler',
            'KERNEN'       => 'Kohler', 'STATEMENT'    => 'Kohler',
            // Moen lines
            'MAGNETIX' => 'Moen', 'BRANTFORD' => 'Moen', 'BANBURY' => 'Moen', 'CALDWELL' => 'Moen',
            // American Standard lines
            'CADET' => 'American Standard', 'CHAMPION' => 'American Standard', 'BOULEVARD' => 'American Standard',
            // Delta lines
            'TRINSIC' => 'Delta', 'LAHARA' => 'Delta', 'CASSIDY' => 'Delta',
            // Toto lines
            'NEOREST' => 'Toto', 'ULTRAMAX' => 'Toto', 'AQUIA' => 'Toto', 'DRAKE' => 'Toto',
        ];

        // Finish detection — longer patterns checked first to prefer e.g. "Polished Chrome" over bare "Chrome"
        $finishes = [
            'VIBRANT BRUSHED MODERNE BRASS' => 'Vibrant Brushed Moderne Brass',
            'VIBRANT BRUSHED NICKEL'        => 'Vibrant Brushed Nickel',
            'VIBRANT BRUSHED ONYX'          => 'Vibrant Brushed Onyx',
            'VIBRANT BRUSHED GOLD'          => 'Vibrant Brushed Gold',
            'OIL RUBBED BRONZE'             => 'Oil-Rubbed Bronze',
            'BRUSHED STAINLESS'             => 'Brushed Stainless',
            'POLISHED CHROME'               => 'Polished Chrome',
            'POLISHED NICKEL'               => 'Polished Nickel',
            'BRUSHED NICKEL'                => 'Brushed Nickel',
            'STAINLESS STEEL'               => 'Stainless Steel',
            'VENETIAN BRONZE'               => 'Venetian Bronze',
            'MATTE BLACK'                   => 'Matte Black',
            'BRUSHED GOLD'                  => 'Brushed Gold',
            'LUXE STAINLESS'                => 'Luxe Stainless',
            'LUXE NICKEL'                   => 'Luxe Nickel',
            'LUXE STEEL'                    => 'Luxe Steel',
            'LUXE GOLD'                     => 'Luxe Gold',
            'SATIN NICKEL'                  => 'Satin Nickel',
            'SATIN GOLD'                    => 'Satin Gold',
            'CHROME'                        => 'Chrome',
            'NICKEL'                        => 'Nickel',
            'BRONZE'                        => 'Bronze',
            'BLACK'                         => 'Black',
            'WHITE'                         => 'White',
            'BRASS'                         => 'Brass',
            'GOLD'                          => 'Gold',
            'BISCUIT'                       => 'Biscuit',
            'ALMOND'                        => 'Almond',
            'BONE'                          => 'Bone',
        ];

        $result = [
            'brand'    => null,
            'line'     => null,
            'model'    => null,
            'finish'   => null,
            'keywords' => [],
        ];

        // Detect brand (multi-word brands checked before single-word brands)
        $brandKeys = array_keys($brands);
        usort($brandKeys, fn ($a, $b) => strlen($b) - strlen($a));
        foreach ($brandKeys as $key) {
            if (str_contains($upper, $key)) {
                $result['brand'] = $brands[$key];
                break;
            }
        }

        // Detect product line — infers brand when brand is not explicitly named
        $lineKeys = array_keys($lineToBrand);
        usort($lineKeys, fn ($a, $b) => strlen($b) - strlen($a));
        foreach ($lineKeys as $lineKey) {
            if (str_contains($upper, $lineKey)) {
                $result['line'] = ucwords(strtolower($lineKey));
                if (! $result['brand']) {
                    $result['brand'] = $lineToBrand[$lineKey];
                }
                break;
            }
        }

        // Detect finish (longer patterns checked first)
        $finishKeys = array_keys($finishes);
        usort($finishKeys, fn ($a, $b) => strlen($b) - strlen($a));
        foreach ($finishKeys as $finishKey) {
            if (str_contains($upper, $finishKey)) {
                $result['finish'] = $finishes[$finishKey];
                break;
            }
        }

        // Extract model number: patterns like C3-455, K-3814, T14476, HL5333-GL
        // Also handles Kohler-style K-702429-L-2MB, K-8304-KS-NA, K-TS35920-4-2MB, K-T35921-4-2MB
        // (optional hyphen before first digit; 0–2 letters may appear after the hyphen before the
        // first digit, e.g. K-TS…; may end with a letter rather than a digit).
        // Must start with 1–4 letters, contain digits, and not be a known unit code
        $skipModels = ['GPF', 'GPM', 'LED', 'ADA', 'UPC', 'SKU', 'NM'];
        if (preg_match('/\b([A-Z]{1,4}-?[A-Z]{0,2}\d[\d\-A-Z]*[A-Z\d])\b/i', $name, $m)) {
            $candidate = strtoupper($m[1]);
            if (! in_array($candidate, $skipModels) && ! ctype_digit(str_replace('-', '', $candidate))) {
                $result['model'] = $m[1];
            }
        }

        // Infer finish from model number suffix when not detected in description text.
        // e.g. K-TS35920-4-2MB → 'Vibrant Brushed Moderne Brass', K-26314-BL → 'Matte Black'
        if (! $result['finish'] && $result['model']) {
            $modelFinishCodes = [
                '2MB' => 'Vibrant Brushed Moderne Brass',
                'VBN' => 'Vibrant Brushed Nickel',
                'VBO' => 'Vibrant Brushed Onyx',
                'CP'  => 'Polished Chrome',
                'BL'  => 'Matte Black',
                'BN'  => 'Brushed Nickel',
                'PN'  => 'Polished Nickel',
                'SN'  => 'Satin Nickel',
                'BG'  => 'Brushed Gold',
            ];
            $modelUpper = strtoupper($result['model']);
            foreach ($modelFinishCodes as $code => $finishName) {
                if (preg_match('/-' . preg_quote($code, '/') . '$/i', $modelUpper)) {
                    $result['finish'] = $finishName;
                    break;
                }
            }
        }

        // Extract meaningful product-type keywords (exclude brand/line/finish/model words)
        $clean = preg_replace('/^[\*\s]+/', '', strtolower($name));
        $clean = preg_replace('/["\'\'"″″\'\'\'\`]+/', '', $clean);
        $clean = trim(preg_replace('/\s{2,}/', ' ', $clean));

        $stopWords      = ['with', 'and', 'the', 'for', 'less', 'other', 'not', 'applicable',
                           'in', 'of', 'by', 'at', 'to', 'from', 'gpm', 'gpf',
                           'comfort', 'height'];
        $brandWords     = $result['brand'] ? array_map('strtolower', explode(' ', $result['brand'])) : [];
        $lineWords      = $result['line'] ? array_map('strtolower', explode(' ', $result['line'])) : [];
        $finishWords    = $result['finish'] ? array_map('strtolower', explode(' ', $result['finish'])) : [];
        $excludeKw      = array_merge($brandWords, $lineWords, $finishWords, $stopWords);

        // Track words that follow "less"/"without" — they describe what's NOT included
        $negatedWords = [];
        $allWords = preg_split('/[\s:,\-\/\(\)]+/', $clean);
        for ($i = 0; $i < count($allWords); $i++) {
            if (in_array($allWords[$i], ['less', 'without']) && isset($allWords[$i + 1])) {
                $negatedWords[] = $allWords[$i + 1];
            }
        }

        foreach ($allWords as $w) {
            if (strlen($w) > 2 && ! is_numeric($w) && ! in_array($w, $excludeKw) && ! in_array($w, $negatedWords)) {
                $result['keywords'][] = $w;
            }
        }
        $result['keywords'] = array_values(array_unique($result['keywords']));

        return $result;
    }

    /**
     * Build an optimized product search query from parsed description attributes.
     * Uses brand + model (most specific), falling back to brand + line + type + finish.
     */
    private function buildSmartQuery(array $parsed, bool $includeFinish = true): ?string
    {
        $parts = [];

        if ($parsed['brand']) {
            $parts[] = $parsed['brand'];
        }

        if ($parsed['model']) {
            // Model number is the most specific identifier — add it with brief type context
            $parts[] = $parsed['model'];
            foreach (array_slice($parsed['keywords'], 0, 2) as $kw) {
                $parts[] = $kw;
            }
        } elseif ($parsed['line']) {
            $parts[] = $parsed['line'];
            foreach (array_slice($parsed['keywords'], 0, 3) as $kw) {
                $parts[] = $kw;
            }
        } else {
            // No brand/line/model — fall back to top keywords
            foreach (array_slice($parsed['keywords'], 0, 5) as $kw) {
                $parts[] = $kw;
            }
        }

        if ($includeFinish && $parsed['finish']) {
            $parts[] = $parsed['finish'];
        }

        return implode(' ', $parts) ?: null;
    }

    /**
     * Score how relevant a Brave search result (url + title + snippet) is to the target product.
     * Returns negative to ~50. Results scoring < 15 should be rejected.
     */
    private function scorePageRelevance(string $url, string $title, string $snippet, array $parsed): int
    {
        $score   = 0;
        $content = strtolower($url . ' ' . $title . ' ' . $snippet);
        $urlLower = strtolower($url);

        // ── Penalize category, listing, store, and non-product pages ──
        $categoryPatterns = [
            '/\\/stores\\//i',         // Amazon store pages
            '/\\/s\\?/i',              // Amazon search results
            '/\\/category\\//i',       // Ferguson/generic categories
            '/\\/pl\\//i',             // Lowes category listings
            '/\\/b\\//i',              // Home Depot browse pages
            '/\\/collections\\//i',    // Shopify collections
            '/\\/dealers\\//i',        // Dealer locator pages
            '/\\/support\\//i',        // Support pages
            '/\\/find-a-/i',          // Find-a-dealer / find-a-store
            '/\\.pdf(\\?|$)/i',        // PDF documents
            '/\\/page\\/[A-Z0-9-]+$/i', // Amazon brand pages
            '/\\/shop-[a-z-]+\\/?$/i', // Kohler/manufacturer shop category pages
            '/prefn\\d=/i',           // Ferguson faceted navigation
            '/rh=n:/i',              // Amazon refinement queries
            '/merchant-items/i',     // Amazon merchant listings
        ];
        foreach ($categoryPatterns as $pattern) {
            if (preg_match($pattern, $urlLower)) {
                $score -= 20;
                break;
            }
        }

        // Penalize bare homepages (e.g. https://www.brizo.com/ )
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if ($path === '/' || $path === '') {
            $score -= 15;
        }

        // ── Positive signals ──
        if ($parsed['brand'] && str_contains($content, strtolower($parsed['brand']))) {
            $score += 5;
        }

        if ($parsed['line'] && str_contains($content, strtolower($parsed['line']))) {
            $score += 8;
        }

        if ($parsed['model']) {
            $modelLower   = strtolower($parsed['model']);
            $modelClean   = str_replace('-', '', $modelLower);
            $contentClean = str_replace('-', '', $content);
            if (str_contains($content, $modelLower) || str_contains($contentClean, $modelClean)) {
                $score += 20;
            }
        }

        if ($parsed['finish']) {
            $finish       = strtolower($parsed['finish']);
            $finishAbbrevs = [
                'polished chrome'        => ['polished chrome', ' pc-', '-pc-', 'polished-chrome'],
                'brushed nickel'         => ['brushed nickel', ' bn-', '-bn-', 'brushed-nickel'],
                'vibrant brushed nickel' => ['vibrant brushed nickel', 'vbn'],
                'matte black'            => ['matte black', 'matte-black', '-bl-', '-mb-'],
                'oil-rubbed bronze'      => ['oil rubbed bronze', 'oil-rubbed', ' orb-', '-orb-'],
                'polished nickel'        => ['polished nickel', ' pn-', '-pn-'],
                'white'                  => ['white', '-wh-', '-0 ', '-0/'],
                'brushed gold'           => ['brushed gold', 'luxe gold', '-gl-', '-bg-'],
            ];
            $checks = $finishAbbrevs[$finish] ?? [$finish];
            foreach ($checks as $check) {
                if (str_contains($content, $check)) {
                    $score += 5;
                    break;
                }
            }
        }

        foreach ($parsed['keywords'] as $kw) {
            if (str_contains($content, $kw)) {
                $score += 2;
            }
        }

        return $score;
    }

    /**
     * Return true if a host looks like a legitimate product or manufacturer commerce site.
     * Rejects non-commerce domains like streaming, social media, dealer locators, etc.
     */
    private function isLikelyProductDomain(string $host, string $context = ''): bool
    {
        $host    = strtolower($host);
        $context = strtolower($context);

        // Hard-reject domains that are never product pages
        $rejectDomains = [
            'spotify.com', 'youtube.com', 'facebook.com', 'twitter.com', 'x.com',
            'instagram.com', 'tiktok.com', 'reddit.com', 'pinterest.com', 'linkedin.com',
            'wikipedia.org', 'wikimedia.org', 'yelp.com', 'bbb.org', 'glassdoor.com',
            'indeed.com', 'craigslist.org', 'nextdoor.com', 'trustpilot.com',
            'schrock.com',
        ];
        foreach ($rejectDomains as $domain) {
            if (str_contains($host, $domain)) {
                return false;
            }
        }

        // Always accept known manufacturer / retailer domains
        $allowDomains = [
            'kohler.com', 'us.kohler.com', 'brizo.com', 'moen.com', 'deltafaucet.com',
            'grohe.com', 'hansgrohe.com', 'toto.com', 'graff-faucets.com', 'rohlhome.com',
            'kallista.com', 'pfister.com', 'ferguson.com', 'homedepot.com', 'lowes.com',
            'amazon.com', 'build.com', 'qualitybath.com', 'supplybuild.com',
            'swplumbing.com', 'firstsupply.com', 'menards.com', 'acehardware.com',
        ];
        foreach ($allowDomains as $domain) {
            if (str_contains($host, $domain)) {
                return true;
            }
        }

        // Accept hosts with plumbing/home-improvement keywords
        $allowPatterns = [
            'plumb', 'bath', 'faucet', 'fixture', 'hardware', 'kitchen',
            'tile', 'floor', 'cabinet', 'depot', 'lumber', 'supply',
        ];
        foreach ($allowPatterns as $pat) {
            if (str_contains($host, $pat)) {
                return true;
            }
        }

        // Reject clearly non-product domain patterns
        $rejectPatterns = [
            'fitness', 'medical', 'health', 'hospital', 'clinic', 'doctor', 'dental',
            'attorney', 'lawyer', 'insurance', 'casino', 'poker', 'news', 'church',
            'togel', 'togle', 'situs', 'slot', 'bet', 'adult', 'magazine', 'journal',
            'dealer', 'cabinetry',
        ];
        foreach ($rejectPatterns as $pat) {
            if (str_contains($host, $pat)) {
                return false;
            }
        }

        // .edu / .gov / .mil are never product sites
        if (preg_match('/\.(edu|gov|mil)$/', $host)) {
            return false;
        }

        // If page context mentions product-related words, accept
        $productWords = ['faucet', 'toilet', 'shower', 'valve', 'trim', 'bath', 'fixture',
                         'plumbing', 'kitchen', 'sink', 'tub', 'tile', 'floor'];
        foreach ($productWords as $w) {
            if (str_contains($context, $w)) {
                return true;
            }
        }

        // Default: accept (avoid over-filtering)
        return true;
    }

    /**
     * Return true if a product name contains a finish that CONFLICTS with the required finish.
     * Only blocks unambiguous conflicts (Brushed Nickel vs. Polished Chrome).
     */
    private function hasConflictingFinish(string $productName, string $requiredFinish): bool
    {
        $productLower  = strtolower($productName);
        $requiredLower = strtolower($requiredFinish);

        $finishSignals = [
            'polished chrome'        => ['polished chrome', 'polished-chrome'],
            'brushed nickel'         => ['brushed nickel', 'brushed-nickel'],
            'vibrant brushed nickel' => ['vibrant brushed nickel'],
            'matte black'            => ['matte black', 'matte-black'],
            'oil-rubbed bronze'      => ['oil rubbed bronze', 'oil-rubbed bronze'],
            'polished nickel'        => ['polished nickel', 'polished-nickel'],
            'white'                  => ['in white', 'color white', '- white'],
            'brushed gold'           => ['brushed gold', 'luxe gold'],
            'brushed stainless'      => ['brushed stainless', 'stainless steel'],
        ];

        $requiredSignals = $finishSignals[$requiredLower] ?? null;
        if (! $requiredSignals) {
            return false; // Cannot determine conflict — allow
        }

        $productHasFinish       = false;
        $productMatchesRequired = false;

        foreach ($finishSignals as $finish => $signals) {
            foreach ($signals as $signal) {
                if (str_contains($productLower, $signal)) {
                    $productHasFinish = true;
                    if ($finish === $requiredLower) {
                        $productMatchesRequired = true;
                    }
                }
            }
        }

        // Conflict only when the product has a definite finish AND it is not the required one
        return $productHasFinish && ! $productMatchesRequired;
    }

    private function extractImageFromProductPage(string $url): string|false|null
    {
        $response = Http::timeout(8)->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ])->get($url);

        if (! $response->successful()) {
            // 404/410 = bad URL, discard. 403/429/5xx = bot-blocked but URL may be valid.
            if ($response->status() === 404 || $response->status() === 410) {
                return false;
            }

            return null; // URL might be valid, just can't extract image
        }

        $html = $response->body();
        $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);

        // Finish-aware image selection: when the product URL contains a known finish code
        // (e.g. /kohler-k-ts35920-4-2mb-castia/), prefer images whose URL also contains that
        // code. This prevents WooCommerce variation pages from returning the wrong-finish image.
        $urlPathLower = strtolower(parse_url($url, PHP_URL_PATH) ?: '');

        // First, try model-code matching against /images/W/ URLs (handles kbauthority and similar
        // sites with any finish code, not just the hardcoded list below).
        // Image filenames look like: K-32254-SC03-2GL_Kohler_Brushed_Moderne_Brass.jpg
        // We strip the underscore suffix to get the model code and check if it appears in the URL path.
        preg_match_all('/https?:\/\/[^\s"\'<>]+\/images\/W\/\d+\/([A-Za-z0-9][^\s"\'<>\?#]+\.(?:jpe?g|png|webp))/i', $html, $wImagesMatch);
        if (!empty($wImagesMatch[0])) {
            foreach ($wImagesMatch[1] as $idx => $wFilename) {
                $modelCode = strtolower(preg_replace('/_.*$/', '', $wFilename)); // e.g. k-32254-sc03-2gl
                if ($modelCode && str_contains($urlPathLower, $modelCode) && ! $this->looksLikeLogo($wImagesMatch[0][$idx])) {
                    return $this->resolveImageUrl($wImagesMatch[0][$idx], $baseUrl);
                }
            }
        }

        foreach (['2mb', 'vbn', 'vbo', 'pn', 'sn', 'bg', 'bn', 'bl', 'cp'] as $fc) {
            if (preg_match('/-' . preg_quote($fc, '/') . '(?:[-\/]|$)/i', $urlPathLower)) {
                preg_match_all('/data-large_image=["\']([^"\']+)["\']/i', $html, $wooLargeAll);
                preg_match_all('/https?:\/\/[^\s"\'<>]+\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>]*)?/i', $html, $allFinishImgs);
                foreach (array_merge($wooLargeAll[1] ?? [], $allFinishImgs[0] ?? []) as $candidate) {
                    $cLower = strtolower($candidate);
                    if ((str_contains($cLower, '-' . $fc . '-') || str_contains($cLower, '-' . $fc . '.')
                         || str_contains($cLower, '-' . $fc . '_')
                         || str_contains($cLower, '_' . $fc . '_')
                         || str_ends_with(preg_replace('/\?.*$/', '', $cLower), '-' . $fc))
                        && ! $this->looksLikeLogo($candidate)) {
                        return $this->resolveImageUrl($candidate, $baseUrl);
                    }
                }
                break;
            }
        }

        // WooCommerce product gallery: data-large_image or data-thumb (preferred — actual product photo)
        if (preg_match('/data-large_image=["\']([^"\']+)["\']/', $html, $wooLarge)) {
            $resolved = $this->resolveImageUrl($wooLarge[1], $baseUrl);
            if (! $this->looksLikeLogo($resolved)) {
                return $resolved;
            }
        }

        if (preg_match('/data-thumb=["\']([^"\']+\.(jpe?g|png|webp))["\']/', $html, $wooThumb)) {
            $resolved = $this->resolveImageUrl($wooThumb[1], $baseUrl);
            if (! $this->looksLikeLogo($resolved)) {
                return $resolved;
            }
        }

        // og:image — skip if it looks like a site logo or is SVG
        if (preg_match('/<meta\s+(?:property|name)=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $ogImage = $this->resolveImageUrl($m[1], $baseUrl);
            if (! $this->looksLikeLogo($ogImage)) {
                return $ogImage;
            }
        }

        if (preg_match_all('/<img[^>]+srcset=["\']([^"\']+)["\'][^>]*>/i', $html, $srcsetMatches)) {
            foreach ($srcsetMatches[1] as $srcset) {
                if (preg_match('#(https?://[^\s,]+)\s+2x#', $srcset, $hiRes)) {
                    if (! $this->looksLikeLogo($hiRes[1])) {
                        return $hiRes[1];
                    }
                }
                if (preg_match('#(https?://[^\s,]+)#', $srcset, $first)) {
                    if (! $this->looksLikeLogo($first[1])) {
                        return $first[1];
                    }
                }
            }
        }

        if (preg_match_all('/<img[^>]+>/i', $html, $imgTags)) {
            foreach ($imgTags[0] as $imgTag) {
                if (Str::contains($imgTag, ['.svg', 'data:', '1x1', 'pixel', 'favicon', 'cart', 'logo', 'icon', 'menu', 'close', 'hamburger', 'carat'])) {
                    continue;
                }
                if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $imgTag, $srcMatch)) {
                    $src = $srcMatch[1];
                    if (preg_match('/\.(jpe?g|png|webp)/i', $src)) {
                        return $this->resolveImageUrl($src, $baseUrl);
                    }
                }
            }
        }

        return null;
    }

    private function looksLikeLogo(string $url): bool
    {
        if (preg_match('/logo|\.svg(\?|$)|favicon|placeholder|banner/i', $url)) {
            return true;
        }

        // Reject obvious spam/gambling content sometimes injected into og:image tags
        if (preg_match('/togel|casino|poker|judi|slot\d|sabung|bandar|sbobet|gacor|betting/i', $url)) {
            return true;
        }

        // Reject Kohler Scene7 category/template placeholder images — these are composite
        // templates that render as category banners, not clean product shots.
        // Real product images look like: /is/image/PAWEB/K-10124_2MB_S4
        // Template images look like:     /is/image/PAWEB/Category_Template?$PDPcon$&...
        if (preg_match('/scene7\.com.*Category_Template/i', $url)) {
            return true;
        }

        return false;
    }

    private function resolveImageUrl(string $src, string $baseUrl): string
    {
        if (Str::startsWith($src, '//')) {
            return "https:{$src}";
        }

        if (Str::startsWith($src, '/')) {
            return $baseUrl . $src;
        }

        if (! Str::startsWith($src, 'http')) {
            return $baseUrl . '/' . $src;
        }

        return $src;
    }

    /**
     * Check if an image URL is accessible.
     * Tries HEAD first; falls back to a ranged GET (some CDNs/servers block HEAD).
     */
    private function isImageAccessible(string $url): bool
    {
        // Reject SVGs and logo-like URLs upfront — these are never product images
        if ($this->looksLikeLogo($url)) {
            return false;
        }

        try {
            $response = Http::timeout(6)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'])
                ->head($url);

            if ($response->successful()) {
                $ct = $response->header('Content-Type', '');

                return str_contains($ct, 'image') && ! str_contains($ct, 'svg');
            }

            // HEAD blocked (403/405) — try a ranged GET
            if (in_array($response->status(), [403, 405, 501])) {
                $response = Http::timeout(6)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Range' => 'bytes=0-1023',
                    ])
                    ->get($url);

                if ($response->successful() || $response->status() === 206) {
                    $ct = $response->header('Content-Type', '');

                    return str_contains($ct, 'image') && ! str_contains($ct, 'svg');
                }
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Search Amazon for product URLs and images.
     *
     * @param array $needsSearch
     * @return array
     */
    private function searchAmazonSite(array $needsSearch): array
    {
        $results = [];

        foreach ($needsSearch as $index => $item) {
            $searchTerm = $item['name'] ?? $item['Description'] ?? '';
            if (empty($searchTerm)) {
                continue;
            }
            $query = urlencode($searchTerm);
            $url = "https://www.amazon.com/s?k={$query}";

            try {
                $html = \Spatie\Browsershot\Browsershot::url($url)
                    ->newHeadless()
                    ->timeout(20)
                    ->setDelay(3000)
                    ->waitUntilNetworkIdle()
                    ->addChromiumArguments([
                        'no-sandbox',
                        'disable-setuid-sandbox',
                        'disable-dev-shm-usage',
                        'disable-gpu',
                        'disable-blink-features=AutomationControlled',
                    ])
                    ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                    ->bodyHtml();

                // Extract product URL and image URL
                preg_match('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', $html, $productMatch);
                preg_match('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $html, $imageMatch);

                $results[$index] = [
                    'product_url' => isset($productMatch[1]) ? 'https://www.amazon.com' . $productMatch[1] : null,
                    'image_url' => $imageMatch[1] ?? null,
                ];
            } catch (\Throwable $e) {
                // Log error and continue
                \Log::error("Amazon scraping failed for query: {$query}", ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }
}
