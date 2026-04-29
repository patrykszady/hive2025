<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$urls = [
    'idx=0' => [
        'https://www.fergusonhome.com/product/summary/2053727',
        'https://plumbtile.com/products/brizo-65365lf-lhp',
        'https://www.carrplumbingsupply.com/Jackson-Brandon-Canton/Brizo-65365LF-PCLHP-Chrome-Widespread-Bathroom-Sink-Faucet.HTM',
    ],
    'idx=12' => [
        'https://sinkandspout.com/product/0-20130008',
        'https://www.dahlcospringskitchenandbath.com/Brizo-T66T065-PCLHP-Chrome-Shower-Faucet-Trim.HTM',
        'https://products.bayplumbingsupply.com/Santa-Cruz-California/Brizo-T66T065-PCLHP-Chrome-Shower-Faucet-Trim.HTM',
    ],
];

foreach ($urls as $label => $list) {
    foreach ($list as $u) {
        echo "═══ $label  $u\n";
        try {
            $r = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(15)->get($u);
            if (! $r->successful()) {
                echo "  HTTP ".$r->status()."\n";
                continue;
            }
            $h = $r->body();
            if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $h, $m)) {
                echo "  og: ".$m[1]."\n";
            }
            preg_match_all('#https?://[^"\s)]+\.(?:jpe?g|png|webp)#i', $h, $imgs);
            $uniq = array_values(array_unique($imgs[0]));
            // Filter to ones that look product-related (contain MPN base)
            $mpnBase = $label === 'idx=0' ? '65365' : 'T66T065';
            $relevant = array_values(array_filter($uniq, fn($x) => stripos($x, $mpnBase) !== false));
            foreach (array_slice($relevant, 0, 8) as $i) {
                echo "  PROD: $i\n";
            }
            if (! $relevant) {
                foreach (array_slice($uniq, 0, 5) as $i) {
                    echo "  - $i\n";
                }
            }
        } catch (\Throwable $e) {
            echo "  ERR ".$e->getMessage()."\n";
        }
    }
}
