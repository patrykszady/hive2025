<?php

namespace App\Http\Controllers\Api\Admin\V1;

use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use SsSystems\Platform\Pulse\Concerns\InteractsWithJsErrorGroups;

/**
 * Management API for ss-systems' JS Errors board (Livewire\Admin\
 * JsErrorsBoard / PlatformJsErrors) — same endpoints and response shapes as
 * jpeterson-design's/dawnsellshomes' Api\Admin\V1\JsErrorController, but
 * this app has no dedicated ingest table: every row is
 * SsSystems\Platform\Pulse\JsErrorGroups' live grouping of
 * SsSystems\Platform\Pulse's `jserr` site_events rows. See that class's
 * docblock for the grouping/resolve/delete semantics, and
 * App\Providers\AppServiceProvider for the PulseStorage/JsErrorGroups
 * bindings (this app's own JsErrorState model) that
 * InteractsWithJsErrorGroups reads through.
 */
class JsErrorController extends Controller
{
    use BuildsApiResponses;
    use InteractsWithJsErrorGroups;
}
