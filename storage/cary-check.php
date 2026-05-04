<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ce = App\Models\CompanyEmail::where("email","patryk@gs.construction")->first();
$svc = app(App\Services\NylasService::class);
$msgs = $svc->getMessages($ce->grant_id, ["search_query_native" => "\"Village of Cary\" Order Receipt", "limit" => 10], false, $ce);
foreach($msgs["data"] ?? [] as $m){
  if(stripos($m["subject"] ?? "", "order receipt")!==false){
    echo "id=".$m["id"]."\n";
    echo "date=".date("c", $m["date"])." (".round((time()-$m["date"])/86400, 1)." days ago)\n";
    echo "from=".json_encode($m["from"])."\n";
    echo "subj=".$m["subject"]."\n";
    echo "folders=".json_encode($m["folders"] ?? null)."\n";
    echo "---\n";
  }
}
