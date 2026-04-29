<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$key = config('services.serpapi.api_key');
if (! $key) { fwrite(STDERR, "no serpapi key\n"); exit(1); }

// Items to research. Hand-built better queries (mfr+model when known).
$items = [
    [
        'idx' => 4,  'vc' => '3075697',
        'desc' => 'CERES 59" FREESTANDING ACWH TUB, INTERNAL DRAIN & OF WH',
        'queries' => ['Barclay Ceres 59 acrylic freestanding tub white'],
    ],
    [
        'idx' => 7,  'vc' => '3419587',
        'desc' => 'BEAUCLERE : SINGLE-HANDLE FLOOR MOUNT TUB FILLER LEVER HANDLE',
        'queries' => [
            'Brizo Beauclere floor mount tub filler lever',
            'Brizo Beauclere T70265 floor mount tub filler',
        ],
    ],
    [
        'idx' => 17, 'vc' => '686266',
        'desc' => 'KOHLER CORBELLE CH EB SKIRTED BOWL WHITE',
        'queries' => [
            'Kohler Corbelle skirted bowl K-5626 white',
            'Kohler K-5626-0 Corbelle elongated skirted bowl',
        ],
    ],
    [
        'idx' => 26, 'vc' => '643511',
        'desc' => 'KOHLER UNIVERSAL RITE-TEMP PB VALVE KIT, STOP',
        'queries' => [
            'Kohler universal Rite-Temp pressure balancing valve kit with stops',
            'Kohler K-8304-KS-NA Rite-Temp valve body cartridge kit stops',
        ],
    ],
];

$preferred = ['supplyhouse.com','plumbersstock.com','ferguson.com','build.com','wayfair.com','homedepot.com','lowes.com','wallingtonplumbingsupply.com','barclayproducts.com','brizo.com','kohler.com','focalpointhardware.com','qualitybath.com','plumbtile.com'];

function rankHost($url, $preferred) {
    $h = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    $h = preg_replace('/^www\./', '', $h);
    foreach ($preferred as $i => $p) {
        if (str_ends_with($h, $p)) return $i;
    }
    return 999;
}

foreach ($items as $it) {
    echo "═══════════════════════════════════════════════════════════\n";
    echo "idx={$it['idx']}  VC={$it['vc']}\n";
    echo "  {$it['desc']}\n";
    echo "═══════════════════════════════════════════════════════════\n";

    $allOrganic = [];
    $allShopping = [];
    foreach ($it['queries'] as $q) {
        echo "\n  Q: $q\n";
        try {
            $r = Http::timeout(20)->get('https://serpapi.com/search.json', [
                'engine' => 'google', 'q' => $q, 'num' => 10,
                'hl' => 'en', 'gl' => 'us', 'api_key' => $key,
            ]);
        } catch (\Throwable $e) { echo "  ERR: ".$e->getMessage()."\n"; continue; }
        if (! $r->successful()) { echo "  HTTP ".$r->status()."\n"; continue; }
        $data = $r->json();
        foreach ($data['organic_results'] ?? [] as $row) {
            $allOrganic[] = ['title'=>$row['title']??'', 'link'=>$row['link']??'', 'snippet'=>$row['snippet']??''];
        }
        foreach ($data['shopping_results'] ?? [] as $row) {
            $allShopping[] = ['title'=>$row['title']??'', 'link'=>$row['link']??$row['product_link']??'', 'thumb'=>$row['thumbnail']??'', 'price'=>$row['price']??'', 'source'=>$row['source']??''];
        }
        usleep(250000);
    }

    // Dedupe by link, sort by preferred host
    $seen = [];
    $org = [];
    foreach ($allOrganic as $r) {
        $k = $r['link']; if (!$k || isset($seen[$k])) continue; $seen[$k] = 1;
        $r['rank'] = rankHost($r['link'], $preferred);
        $org[] = $r;
    }
    usort($org, fn($a,$b) => $a['rank'] <=> $b['rank']);

    echo "\n  ── ORGANIC (top 8 by host preference) ──\n";
    foreach (array_slice($org, 0, 8) as $r) {
        echo "  • ".substr($r['title'],0,80)."\n";
        echo "    {$r['link']}\n";
        if ($r['snippet']) echo "    ".substr($r['snippet'],0,120)."\n";
    }

    $seen = [];
    $shop = [];
    foreach ($allShopping as $r) {
        $k = $r['link'].'|'.$r['title']; if (isset($seen[$k])) continue; $seen[$k] = 1;
        $r['rank'] = rankHost($r['link'], $preferred);
        $shop[] = $r;
    }
    usort($shop, fn($a,$b) => $a['rank'] <=> $b['rank']);

    echo "\n  ── SHOPPING (top 5) ──\n";
    foreach (array_slice($shop, 0, 5) as $r) {
        echo "  • ".substr($r['title'],0,80)." [{$r['source']} {$r['price']}]\n";
        echo "    URL: {$r['link']}\n";
        echo "    IMG: {$r['thumb']}\n";
    }
    echo "\n";
}
