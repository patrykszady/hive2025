<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Livewire\Livewire;

$out = getenv('OUT') ?: '/tmp/claude-1000/-home-patryk-web-hive2025/5c216b16-a982-47c2-8bd4-5635c46604a2/scratchpad/loading';
@mkdir($out, 0777, true);

// authenticate as a normal vendor user
$user = \App\Models\User::query()
    ->whereHas('vendors')
    ->orderBy('id')
    ->first();
if (! $user) {
    $user = \App\Models\User::query()->orderBy('id')->first();
}
auth()->login($user);
echo "AUTH as user #{$user->id} ({$user->email}) browsing_as_client=".var_export((bool) $user->is_browsing_as_client, true)."\n";

$project = \App\Models\Project::query()->orderBy('id')->first();
$projectId = $project?->id;
echo "project #{$projectId}\n";

$cases = [
    'projects-table'        => [\App\Livewire\Projects\ProjectsTable::class, []],
    'leads'                 => [\App\Livewire\Leads\LeadsIndex::class, []],
    'expenses'              => [\App\Livewire\Expenses\ExpenseIndex::class, []],
    'expenses-projectshow'  => [\App\Livewire\Expenses\ExpenseIndex::class, ['project_id' => $projectId, 'view' => 'projects.show']],
    'checks'                => [\App\Livewire\Checks\ChecksIndex::class, []],
    'payments'              => [\App\Livewire\Payments\PaymentsIndex::class, []],
    'payments-projectshow'  => [\App\Livewire\Payments\PaymentsIndex::class, ['project' => $project, 'view' => 'projects.show']],
    'vendors'               => [\App\Livewire\Vendors\VendorsIndex::class, []],
    'clients-table'         => [\App\Livewire\Clients\ClientsTable::class, []],
    'timesheets'            => [\App\Livewire\Timesheets\TimesheetsIndex::class, []],
    'estimates'             => [\App\Livewire\Estimates\EstimatesIndex::class, []],
    'estimates-projectshow' => [\App\Livewire\Estimates\EstimatesIndex::class, ['project' => $project, 'view' => 'projects.show']],
    'receipts'              => [\App\Livewire\Receipts\ReceiptsIndex::class, []],
    'lien-waivers'          => [\App\Livewire\LienWaivers\Index::class, []],
    'distributions-list'    => [\App\Livewire\Distributions\DistributionsList::class, []],
    'distribution-projects' => [\App\Livewire\Distributions\DistributionProjectsTable::class, ['type' => 'unpaid']],
];

foreach ($cases as $name => [$class, $params]) {
    try {
        $html = Livewire::test($class, $params)->html();
        file_put_contents("$out/$name.html", $html);
        echo str_pad($name, 24)." OK  ".strlen($html)." bytes\n";
    } catch (\Throwable $e) {
        echo str_pad($name, 24)." ERR ".get_class($e).': '.substr($e->getMessage(), 0, 200)."\n";
    }
}

// Also render the placeholder() output directly for lazy-mounted components
$lazy = [
    'PH-projects-table'        => [\App\Livewire\Projects\ProjectsTable::class, 'placeholder', []],
    'PH-clients-table'         => [\App\Livewire\Clients\ClientsTable::class, 'placeholder', []],
    'PH-expenses'              => [\App\Livewire\Expenses\ExpenseIndex::class, 'placeholder', []],
    'PH-payments'              => [\App\Livewire\Payments\PaymentsIndex::class, 'placeholder', []],
    'PH-estimates'             => [\App\Livewire\Estimates\EstimatesIndex::class, 'placeholder', []],
    'PH-lien-waivers'          => [\App\Livewire\LienWaivers\Index::class, 'placeholder', []],
    'PH-distribution-projects' => [\App\Livewire\Distributions\DistributionProjectsTable::class, 'placeholder', []],
];
foreach ($lazy as $name => [$class, $method, $args]) {
    try {
        $c = new $class;
        $r = new ReflectionMethod($class, $method);
        $view = $r->getNumberOfParameters() ? $c->{$method}(...$args) : $c->{$method}();
        $html = is_string($view) ? $view : $view->render();
        file_put_contents("$out/$name.html", $html);
        echo str_pad($name, 26)." OK  ".strlen($html)." bytes  params=".$r->getNumberOfParameters()."\n";
    } catch (\Throwable $e) {
        echo str_pad($name, 26)." ERR ".get_class($e).': '.substr($e->getMessage(), 0, 200)."\n";
    }
}
