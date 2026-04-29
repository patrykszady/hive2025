<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google SERP client backed by Bright Data's synchronous /request endpoint
 * (brd_json=1). Returns SerpAPI-shaped arrays (organic_results,
 * shopping_results, images_results) so callers stay format-agnostic.
 */
class SerpClient
{
    public static function make(): self
    {
        return new self();
    }

    /**
     * Google Shopping. Returns ['shopping_results' => [...]].
     */
    public function shopping(string $query): array
    {
        $json = $this->brightDataSerp($query, ['tbm' => 'shop']);
        return [
            'shopping_results' => $this->mapBrightDataShopping($json),
        ];
    }

    /**
     * Google Web. Returns ['organic_results' => [...], 'shopping_results' => [...]].
     */
    public function web(string $query, int $count = 10): array
    {
        $json = $this->brightDataSerp($query, []);
        return [
            'organic_results'  => $this->mapBrightDataOrganic($json),
            'shopping_results' => $this->mapBrightDataShopping($json),
        ];
    }

    /**
     * Google Images. Returns ['images_results' => [...]].
     * $tbs e.g. 'isz:l' for large images.
     */
    public function images(string $query, ?string $tbs = null): array
    {
        $extra = ['tbm' => 'isch'];
        if ($tbs) {
            $extra['tbs'] = $tbs;
        }
        $json = $this->brightDataSerp($query, $extra);
        return [
            'images_results' => $this->mapBrightDataImages($json),
        ];
    }

    /**
     * Synchronous Bright Data SERP API call.
     * Returns parsed JSON from Google search via brd_json=1.
     */
    private function brightDataSerp(string $query, array $extraParams = []): array
    {
        $token = config('services.brightdata.api_token');
        $zone  = config('services.brightdata.serp_zone');
        if (! $token || ! $zone) {
            return [];
        }

        $params = array_merge([
            'q'        => $query,
            'brd_json' => '1',
            'hl'       => 'en',
            'gl'       => 'us',
        ], $extraParams);

        $url = 'https://www.google.com/search?' . http_build_query($params);

        try {
            $response = Http::withToken($token)
                ->timeout(60)
                ->acceptJson()
                ->post('https://api.brightdata.com/request', [
                    'zone'   => $zone,
                    'url'    => $url,
                    'format' => 'raw',
                ]);
        } catch (Throwable $e) {
            Log::warning('SerpClient BrightData exception', ['msg' => $e->getMessage(), 'q' => $query]);
            return [];
        }

        if (! $response->successful()) {
            Log::warning('SerpClient BrightData failed', [
                'status' => $response->status(),
                'q'      => $query,
                'body'   => substr($response->body(), 0, 200),
            ]);
            return [];
        }

        $body = $response->json();
        return is_array($body) ? $body : [];
    }

    /**
     * Map Bright Data organic[] to organic_results shape.
     */
    private function mapBrightDataOrganic(array $json): array
    {
        $rows = $json['organic'] ?? [];
        return array_map(static fn ($r) => [
            'title'    => $r['title'] ?? '',
            'link'     => $r['link'] ?? '',
            'snippet'  => $r['description'] ?? ($r['snippet'] ?? ''),
            'source'   => $r['source'] ?? '',
            'position' => $r['rank'] ?? ($r['global_rank'] ?? null),
        ], $rows);
    }

    /**
     * Map Bright Data shopping/PLA/organic results to shopping_results shape.
     * On tbm=shop pages BD parses items under 'organic' with shopping fields.
     */
    private function mapBrightDataShopping(array $json): array
    {
        $out = [];

        foreach (($json['pla'] ?? []) as $r) {
            $out[] = $this->shoppingRow($r);
        }
        foreach (($json['shopping'] ?? []) as $r) {
            $out[] = $this->shoppingRow($r);
        }
        foreach (($json['organic'] ?? []) as $r) {
            $out[] = $this->shoppingRow($r);
        }

        return array_values(array_filter($out, static fn ($r) => ! empty($r['link']) || ! empty($r['title'])));
    }

    private function shoppingRow(array $r): array
    {
        $price = $r['price'] ?? null;
        if (! $price && ! empty($r['extensions']) && is_array($r['extensions'])) {
            foreach ($r['extensions'] as $ext) {
                if (is_array($ext) && isset($ext['text']) && preg_match('/\$\s?\d/', (string) $ext['text'])) {
                    $price = $ext['text'];
                    break;
                }
            }
        }

        return [
            'title'     => $r['title'] ?? '',
            'link'      => $r['link'] ?? ($r['url'] ?? ''),
            'thumbnail' => $r['image'] ?? ($r['thumbnail'] ?? ($r['image_base64'] ?? null)),
            'source'    => $r['source'] ?? ($r['merchant'] ?? ($r['seller'] ?? '')),
            'price'     => $price ?? '',
        ];
    }

    /**
     * Map Bright Data images[] to images_results shape.
     * Prefer non-base64 source URLs when available.
     */
    private function mapBrightDataImages(array $json): array
    {
        $rows = $json['images'] ?? [];
        return array_map(static function ($r) {
            $thumb = $r['image'] ?? ($r['thumbnail'] ?? null);
            if (is_string($thumb) && str_starts_with($thumb, 'data:')) {
                $thumb = $r['source_url'] ?? ($r['original_image'] ?? $thumb);
            }
            return [
                'title'     => $r['title'] ?? ($r['image_alt'] ?? ''),
                'link'      => $r['source_url'] ?? ($r['link'] ?? ''),
                'thumbnail' => $thumb,
                'original'  => $r['original_image'] ?? ($r['source_url'] ?? null),
            ];
        }, $rows);
    }
}
