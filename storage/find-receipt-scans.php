<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CompanyEmail;
$svc = app(App\Services\NylasService::class);
foreach ([31, 34] as $id) {
    $ce = CompanyEmail::withoutGlobalScopes()->find($id);
    foreach (['INBOX_FOLDER', 'HIVE_RECEIPTS_FOLDER'] as $key) {
        $fid = $ce->api_json[$key] ?? null;
        if (!$fid) continue;
        $res = $svc->getMessages($ce->grant_id, [
            'in' => $fid,
            'limit' => 25,
            'received_after' => strtotime('2026-05-05 00:00:00 UTC'),
        ]);
        echo "== ce {$id} {$key} status=".($res['status'] ?? '?').' count='.count($res['data'] ?? [])."\n";
        foreach ($res['data'] ?? [] as $m) {
            $sub = $m['subject'] ?? '';
            $from = $m['from'][0]['email'] ?? '?';
            if (stripos($sub, 'receipt') !== false || stripos($sub, 'scan') !== false || $from === 'noreply@print.epsonconnect.com') {
                echo '  '.$m['id'].' | '.date('Y-m-d H:i', $m['date'] ?? 0).' | '.substr($sub, 0, 60).' | from='.$from.' | atts='.count($m['attachments'] ?? [])."\n";
            }
        }
    }
}
