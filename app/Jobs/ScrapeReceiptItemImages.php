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

        foreach ($items as $index => &$item) {
            if (! empty($item['image_url'])) {
                // Still sync to receipt_line_item_descs if not already there
                ReceiptLineItemDesc::updateOrCreate(
                    ['expense_receipt_id' => $this->receipt->id, 'item_index' => $index],
                    [
                        'vendor_id' => $vendorId,
                        'sku' => $item['ProductCode'] ?? null,
                        'product_image_url' => $item['image_url'] ?? null,
                        'product_url' => $item['product_url'] ?? null,
                        'area' => $item['Area'] ?? null,
                    ]
                );

                continue;
            }

            $sku = $item['ProductCode'] ?? null;
            $name = $item['Description'] ?? null;

            if (! $sku && ! $name) {
                continue;
            }

            // Check existing receipt_line_item_descs for this SKU + vendor
            // Also check sibling items in the same receipt that already have images
            if ($sku) {
                $existing = null;

                // Priority 1: exact match by SKU + vendor
                if ($vendorId) {
                    $existing = ReceiptLineItemDesc::where('sku', $sku)
                        ->where('vendor_id', $vendorId)
                        ->whereNotNull('product_image_url')
                        ->first();
                }

                // Priority 2: sibling items in current receipt with same SKU
                if (! $existing) {
                    foreach ($items as $siblingItem) {
                        if (($siblingItem['ProductCode'] ?? null) === $sku && isset($siblingItem['image_url']) && $siblingItem['image_url']) {
                            $existing = (object) [
                                'product_image_url' => $siblingItem['image_url'],
                                'product_url' => $siblingItem['product_url'] ?? null,
                            ];
                            break;
                        }
                    }
                }

                // Priority 3: any vendor with same SKU (SKUs are typically unique across vendors)
                if (! $existing) {
                    $existing = ReceiptLineItemDesc::where('sku', $sku)
                        ->whereNotNull('product_image_url')
                        ->first();
                }

                if ($existing) {
                    $item['image_url'] = $existing->product_image_url;
                    $item['product_url'] = $existing->product_url;
                    $updated = true;

                    ReceiptLineItemDesc::updateOrCreate(
                        ['expense_receipt_id' => $this->receipt->id, 'item_index' => $index],
                        [
                            'vendor_id' => $vendorId,
                            'sku' => $sku,
                            'product_image_url' => $existing->product_image_url,
                            'product_url' => $existing->product_url,
                            'area' => $item['Area'] ?? null,
                        ]
                    );

                    continue;
                }
            }

            // Web search for product page + image
            $result = $this->findProductImage($sku, $name, $vendorName, $vendorWebsite);

            if ($result) {
                $item['image_url'] = $result['image_url'] ?? null;
                $item['product_url'] = $result['product_url'] ?? null;
                $updated = true;

                // Store in receipt_line_item_descs
                ReceiptLineItemDesc::updateOrCreate(
                    ['expense_receipt_id' => $this->receipt->id, 'item_index' => $index],
                    [
                        'vendor_id' => $vendorId,
                        'sku' => $sku,
                        'product_image_url' => $result['image_url'] ?? null,
                        'product_url' => $result['product_url'] ?? null,
                        'area' => $item['Area'] ?? null,
                    ]
                );
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

    private function discoverVendorWebsite(string $vendorName): ?string
    {
        $endpoint = config('services.azure_cu.endpoint');
        $apiKey = config('services.azure_cu.api_key');

        if (! $endpoint || ! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("https://{$endpoint}/openai/responses?api-version=2025-03-01-preview", [
                    'model'       => 'gpt-4o-mini',
                    'input'       => "What is the official website URL for \"{$vendorName}\"? This is a building materials, tile, flooring, or construction supply vendor. Return ONLY the homepage URL (e.g. https://www.example.com) or NONE if not found.",
                    'tools'       => [['type' => 'web_search_preview']],
                    'tool_choice' => 'required',
                    'stream'      => false,
                ]);

            if (! $response->successful()) {
                return null;
            }

            foreach ($response->json('output', []) as $output) {
                if (($output['type'] ?? '') === 'message') {
                    foreach ($output['content'] ?? [] as $content) {
                        $text = $content['text'] ?? '';
                        if (preg_match('#(https?://[^\s\]\)\"<>,]+)#', strip_tags($text), $match)) {
                            $url = rtrim($match[1], '.,;');

                            if (Str::contains($url, ['bing.com', 'google.com', 'duckduckgo', 'wikipedia', 'NONE'])) {
                                return null;
                            }

                            // Normalize to homepage
                            $parsed = parse_url($url);

                            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('horizon')->warning('ScrapeReceiptItemImages: vendor website discovery failed', [
                'vendor'  => $vendorName,
                'error'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function findProductImage(?string $sku, ?string $name, ?string $vendorName, ?string $vendorWebsite): ?array
    {
        $endpoint = config('services.azure_cu.endpoint');
        $apiKey = config('services.azure_cu.api_key');

        if (! $endpoint || ! $apiKey) {
            return null;
        }

        // Strip quotes, inch marks, and other special chars that confuse web search
        if ($name) {
            $name = preg_replace('/["\'"″″\'\'`]+/', '', $name);
            $name = preg_replace('/\s{2,}/', ' ', trim($name));
        }

        $vendorSite = null;
        if ($vendorWebsite) {
            $vendorSite = parse_url($vendorWebsite, PHP_URL_HOST) ?: $vendorWebsite;
        }

        $sitePrefix = $vendorSite ? "site:{$vendorSite} " : '';
        $vendor = $vendorSite ?? ($vendorName ?? '');

        $queries = [];

        // Priority 1: search the vendor's own website first
        if ($vendorSite) {
            if ($sku) {
                $queries[] = "{$sitePrefix}{$sku}";
            }
            if ($sku && $name) {
                $queries[] = "{$sitePrefix}{$sku} {$name}";
            }
            if ($name) {
                $queries[] = "{$sitePrefix}{$name}";
            }
        }

        // Priority 2: broader web search with vendor name
        if ($sku) {
            $queries[] = trim("{$vendor} {$sku}");
        }
        if ($sku && $name) {
            $queries[] = trim("{$vendor} {$sku} {$name}");
        }
        if ($name) {
            $queries[] = trim("{$vendor} {$name}");
        }

        // Priority 3: generic search without vendor (for vendors whose sites lack product pages)
        if ($sku && $name) {
            $queries[] = "{$sku} {$name} porcelain tile";
        }
        if ($name) {
            $queries[] = "{$name} porcelain tile product";
        }

        $fallbackResult = null;
        $rejectedUrls = [];

        foreach ($queries as $searchTerm) {
            $result = $this->webSearch($searchTerm, $endpoint, $apiKey);
            if ($result) {
                if ($this->urlMatchesProduct($result['product_url'] ?? null, $name)) {
                    if (! empty($result['image_url'])) {
                        return $result;
                    }
                    // URL matched but page had no extractable image (e.g. 404) — save as fallback
                    $fallbackResult ??= $result;
                } else {
                    $rejectedUrls[] = $result['product_url'];
                }
            }
        }

        // Try dimension correction on rejected URLs (e.g. swap "4x24" → "12x24")
        if (! $fallbackResult && ! empty($rejectedUrls) && $name) {
            $nameDimensions = [];
            foreach (preg_split('/[\s\/]+/', strtolower($name)) as $w) {
                if (preg_match('/^\d+x\d+$/i', $w)) {
                    $nameDimensions[] = $w;
                }
            }

            if (! empty($nameDimensions)) {
                foreach ($rejectedUrls as $url) {
                    foreach ($nameDimensions as $dim) {
                        $correctedUrl = preg_replace('/\d+x\d+/', $dim, $url, 1);
                        if ($correctedUrl !== $url) {
                            $imageUrl = $this->extractImageFromProductPage($correctedUrl);
                            if ($imageUrl && $imageUrl !== false) {
                                return [
                                    'image_url' => $imageUrl,
                                    'product_url' => $correctedUrl,
                                ];
                            }
                            if ($imageUrl === null) {
                                $fallbackResult ??= ['product_url' => $correctedUrl];
                            }
                        }
                    }
                }
            }
        }

        return $fallbackResult;
    }

    /**
     * Verify the product URL slug contains key words from the product name.
     * Rejects URLs like "portraits-newport" when the product is "PORTRAITS ERICE".
     */
    private function urlMatchesProduct(?string $url, ?string $name): bool
    {
        if (! $url || ! $name) {
            return true; // Can't validate without both, let it through
        }

        $slug = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        // Extract distinguishing words from the product name (skip short/generic words and dimensions)
        $cleanName = preg_replace('/["\'"″″\'\'`]+/', '', $name);
        $words = preg_split('/[\s\/]+/', strtolower($cleanName));
        $skipWords = ['the', 'and', 'for', 'new', 'pkg', 'rect', 'matte', 'honed', 'polished', 'field', 'tile', 'mosaic', 'bar', 'liner', 'round'];
        $significantWords = array_filter($words, function ($w) use ($skipWords) {
            return strlen($w) > 2
                && ! is_numeric($w)
                && ! preg_match('/^\d+x\d+$/i', $w)
                && ! preg_match('/^\d+-\d+/', $w) // fraction fragments like 1-1 from "1-1/4"
                && ! in_array($w, $skipWords);
        });

        if (empty($significantWords)) {
            return true;
        }

        // All significant words from the name should appear in the URL slug
        foreach ($significantWords as $word) {
            if (! str_contains($slug, $word)) {
                return false;
            }
        }

        // Verify dimensions match (e.g. "12x24" in name must match URL, reject "4x24")
        $nameDimensions = array_filter($words, fn ($w) => preg_match('/^\d+x\d+$/i', $w));
        if (! empty($nameDimensions)) {
            $dimensionFound = false;
            foreach ($nameDimensions as $dim) {
                if (str_contains($slug, $dim)) {
                    $dimensionFound = true;
                    break;
                }
            }

            // URL has a dimension but it doesn't match the product — wrong size
            if (! $dimensionFound && preg_match('/\d+x\d+/', $slug)) {
                return false;
            }
        }

        return true;
    }

    private function webSearch(string $searchTerm, string $endpoint, string $apiKey): ?array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("https://{$endpoint}/openai/responses?api-version=2025-03-01-preview", [
                    'model'       => 'gpt-4o-mini',
                    'input'       => "Search for: {$searchTerm}\n\nReturn the URL of the specific product detail page (PDP) for this EXACT product — the page that shows ONE product with its image, specs, and price. The product name in the URL and page title MUST match the search term. For example, if searching for \"PORTRAITS ERICE\", do NOT return a page for \"PORTRAITS NEWPORT\" or any other variant — only the exact product name. STRONGLY prefer the vendor's own website over third-party retailers or resellers. Do NOT return category pages, collection pages, product-line overview pages, or search result pages. Return ONLY the URL or NONE if no specific product page is found.",
                    'tools'       => [['type' => 'web_search_preview']],
                    'tool_choice' => 'required',
                    'stream'      => false,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $productUrl = null;
            $annotations = [];

            foreach ($response->json('output', []) as $output) {
                if (($output['type'] ?? '') === 'message') {
                    foreach ($output['content'] ?? [] as $content) {
                        $text = $content['text'] ?? '';
                        if (preg_match('#https?://[^\s\]\)\"<>]+#', strip_tags($text), $urlMatch)) {
                            $productUrl = rtrim($urlMatch[0], '.,;');
                        }

                        foreach ($content['annotations'] ?? [] as $annotation) {
                            if (($annotation['type'] ?? '') === 'url_citation' && ! empty($annotation['url'])) {
                                $annotations[] = $annotation['url'];
                            }
                        }
                    }
                }
            }

            $productUrl = $annotations[0] ?? $productUrl;

            if (! $productUrl || Str::contains($productUrl, ['bing.com', 'google.com', 'duckduckgo', 'NONE'])) {
                return null;
            }

            // Reject collection/category pages — these never have the right product image
            if (preg_match('#/product-lines/[^/]+/?$|/category/[^/]+/?$|/collections/[^/]+/?$|/c/[^/]+/?$#i', $productUrl)) {
                return null;
            }

            $imageUrl = $this->extractImageFromProductPage($productUrl);

            // false = page was unreachable (404/5xx) — discard the URL entirely
            if ($imageUrl === false) {
                return null;
            }

            return array_filter([
                'image_url'   => $imageUrl,
                'product_url' => $productUrl,
            ]) ?: null;
        } catch (\Throwable $e) {
            Log::channel('horizon')->warning('ScrapeReceiptItemImages: web search failed', [
                'receipt_id' => $this->receipt->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractImageFromProductPage(string $url): string|false|null
    {
        $response = Http::timeout(15)->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; HiveBot/1.0)',
        ])->get($url);

        if (! $response->successful()) {
            return false; // Page unreachable — discard URL so caller tries next query
        }

        $html = $response->body();
        $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);

        if (preg_match('/<meta\s+(?:property|name)=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return $this->resolveImageUrl($m[1], $baseUrl);
        }

        if (preg_match_all('/<img[^>]+srcset=["\']([^"\']+)["\'][^>]*>/i', $html, $srcsetMatches)) {
            foreach ($srcsetMatches[1] as $srcset) {
                if (preg_match('#(https?://[^\s,]+)\s+2x#', $srcset, $hiRes)) {
                    return $hiRes[1];
                }
                if (preg_match('#(https?://[^\s,]+)#', $srcset, $first)) {
                    return $first[1];
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
}
