<?php

use App\Http\Controllers\Api\Admin\V1\TrafficController;
use Illuminate\Support\Facades\Route;
use SsSystems\Platform\Http\Admin\CapabilityRegistry;

// Where visitors come from (kit 0.17) — the SEO screen's traffic card.
CapabilityRegistry::declare('traffic');

Route::get('traffic/sources', [TrafficController::class, 'sources'])->name('traffic.sources');
