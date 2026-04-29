<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$key = config('services.serpapi.api_key');

$queries = [
    'idx=0 LESS HANDLES'  => 'Brizo 65365LF-PCLHP Beauclere widespread lavatory faucet less handles',
    'idx=3 sink no HD'    => 'Kohler K-20000-0 Caxton undermount bathroom sink white site:build.com OR site:wayfair.com OR site:fergusonshowrooms.com OR site:supplyhouse.com OR site:kohler.com',
    'idx=3 sink fallback' => 'Kohler 20000-0 Caxton undermount bathroom sink white',
    'idx=7 handle kit'    => 'Brizo HL7065-PC Beauclere lever handle kit floor mount tub filler',
    'idx=12 less handle'  => 'Brizo T66T065-PCLHP Beauclere Sensori thermostatic valve trim less handle',
];

foreach ($queries as $label => $q) {
    echo PHP_EOL.'═══ '.$label.' ═══'.PHP_EOL.'  Q: '.$q.PHP_EOL;
    $r = Http::timeout(20)->get('https://serpapi.com/search.json', [
        'engine'=>'google','q'=>$q,'num'=>8,'hl'=>'en','gl'=>'us','api_key'=>$key,
    ]);
    if (!$r->successful()) { echo '  HTTP '.$r->status().PHP_EOL; continue; }
    foreach (($r->json()['organic_results'] ?? []) as $row) {
        echo '  - '.($row['link']??'').PHP_EOL.'    '.substr($row['title']??'', 0, 90).PHP_EOL;
    }
    usleep(300000);
}
