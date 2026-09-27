<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business identity
    |--------------------------------------------------------------------------
    |
    | Read by two things: ss-systems' /api/admin/v1/ping "brand" block is
    | built in App\Http\Controllers\Api\Admin\V1\PingController from
    | config('app.name') directly (this file is not that source — do not
    | duplicate it there), and App\Support\Seo\Reports\ConfigSiteIdentity,
    | which feeds the shared SEO report library's seo:gbp-parity NAP check.
    | hive.contractors is a SaaS marketing site for a PM tool, not a local
    | service business with a storefront address — there is no public phone
    | number or street address on the site to be consistent about, so both
    | stay null and seo:gbp-parity's phone/address checks skip cleanly
    | rather than fabricate a "consistency" that doesn't apply here (see
    | SsSystems\Platform\Reports\Contracts\SiteIdentity's docblock: null
    | means "the phone-parity check is then skipped, exactly like today").
    */
    'name' => 'Hive Contractors',

    'phone' => null,
    'address' => null,
];
