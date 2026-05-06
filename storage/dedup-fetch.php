<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CompanyEmail;
$ce = CompanyEmail::withoutGlobalScopes()->find(31);
$svc = app(App\Services\NylasService::class);
$messageId = 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A23n86TJcJU_kYhT4djVwqgALIXfE0gAA';
$msg = $svc->getMessage($ce->grant_id, $messageId);
$data = $msg['data'] ?? $msg;
echo "Subject: ".($data['subject'] ?? '')."\n";
echo "From: ".($data['from'][0]['email'] ?? '')."\n";
echo "Date: ".date('Y-m-d H:i:s', $data['date'] ?? 0)."\n";
echo "Attachments:\n";
$dir = storage_path('app/_temp_ocr/dedup-test');
@mkdir($dir, 0775, true);
foreach ($data['attachments'] ?? [] as $i => $att) {
    echo "  [$i] id=".$att['id']." filename=".($att['filename']??'?')." size=".($att['size']??'?')." type=".($att['content_type']??'?')."\n";
    $bin = $svc->downloadAttachment($att['id'], $ce->grant_id, $messageId);
    $fname = $dir.'/att-'.$i.'-'.($att['filename'] ?? 'file.pdf');
    file_put_contents($fname, $bin);
    echo "      saved to $fname (".strlen($bin)." bytes, sha1=".sha1($bin).")\n";
}
