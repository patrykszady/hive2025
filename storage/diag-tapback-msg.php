<?php

$id = (int) ($argv[1] ?? 41950);

$bootstrap = __DIR__ . '/../bootstrap/app.php';
$app = require $bootstrap;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$m = \App\Models\SmsMessage::find($id);
if (! $m) {
    echo "Message $id not found\n";
    exit(1);
}

echo "=== Message $id ===\n";
echo "thread_id: {$m->thread_id}\n";
echo "TEXT: {$m->text}\n";
echo "HEX:  " . bin2hex($m->text) . "\n";
echo "LEN bytes=" . strlen($m->text) . " mb=" . mb_strlen($m->text) . "\n\n";

$parsed = $m->parseTapback();
echo "parseTapback() => ";
var_export($parsed);
echo "\n\n";

if (! $parsed) {
    exit(0);
}

$quoted = mb_strtolower(trim($parsed['quoted']));
echo "QUOTED (lc): {$quoted}\n\n";

echo "Searching thread {$m->thread_id} for original message...\n";
$candidates = \App\Models\SmsMessage::where('thread_id', $m->thread_id)
    ->where('id', '!=', $m->id)
    ->whereNotNull('text')
    ->orderByDesc('id')
    ->limit(50)
    ->get();

foreach ($candidates as $c) {
    $cand = mb_strtolower(trim((string) $c->display_text));
    if ($cand === '') {
        continue;
    }
    $hit = str_contains($cand, $quoted) || str_contains($quoted, $cand);
    if ($hit) {
        echo "  MATCH id={$c->id}: " . mb_substr($cand, 0, 80) . "\n";
    }
}
