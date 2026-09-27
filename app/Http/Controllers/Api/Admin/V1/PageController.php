<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Support\Pages\HivePageOverrideStore;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Pages\Contracts\PageOverrideStore;
use SsSystems\Platform\Pages\Http\Concerns\ServesPages;

/**
 * ss-systems' Pages screen for hive.contractors — see
 * App\Support\Pages\HivePageOverrideStore for the actual storage
 * (route+params-keyed PageSeoOverride rows over the marketing site's
 * Blade views) and the kit's
 * SsSystems\Platform\Pages\Http\Concerns\ServesPages for the shared HTTP
 * shaping (index/types/show/update/store/destroy) every one of the four
 * sites' PageController now shares.
 */
class PageController extends Controller
{
    use BuildsApiResponses, ServesPages;

    protected function pageOverrideStore(): PageOverrideStore
    {
        return new HivePageOverrideStore;
    }
}
