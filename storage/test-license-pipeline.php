<?php

use App\Models\User;
use App\Models\VendorDoc;
use Illuminate\Support\Facades\Auth;

// Authenticate as the GC user so trait/scope behaviour matches the UI flow.
$user = User::where('email', 'patryk@gs.construction')->first();
Auth::login($user);

$component = new class {
    use \App\Traits\ProcessesVendorDocs {
        handleVendorDocProcessing as public;
    }

    public function moveEmailBasedOnMatchingResults(...$args): void {}
};

$cases = [
    ['temp_vendor_docs/058-test-run.jpg', 4, $user->vendor->id],
    ['temp_vendor_docs/055-test-run.jpg', 4, $user->vendor->id],
];

foreach ($cases as [$path, $vendorId, $belongsTo]) {
    echo "\n=== Processing {$path} ===\n";

    $before = VendorDoc::withoutGlobalScopes()->where('vendor_id', $vendorId)->count();
    $result = $component->handleVendorDocProcessing($path, 'jpg', $vendorId, $belongsTo);
    $after  = VendorDoc::withoutGlobalScopes()->where('vendor_id', $vendorId)->count();

    echo "result: ".var_export($result, true)."\n";
    echo "doc count for vendor {$vendorId}: {$before} -> {$after}\n";
}

echo "\n--- Vendor 4 docs ---\n";
foreach (VendorDoc::withoutGlobalScopes()->where('vendor_id', 4)->orderByDesc('id')->limit(10)->get() as $d) {
    printf("id=%d type=%s number=%s eff=%s exp=%s file=%s\n",
        $d->id,
        $d->getRawOriginal('type'),
        $d->number,
        optional($d->effective_date)->format('Y-m-d'),
        optional($d->expiration_date)->format('Y-m-d'),
        $d->doc_filename
    );
}
