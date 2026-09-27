<?php

/*
|--------------------------------------------------------------------------
| Citations — business profiles and directory listings we build ourselves
|--------------------------------------------------------------------------
| Ported from gsc's citation builder (via dawnsellshomes' single-tenant
| adaptation). On those sites this opens each directory in a headed
| Chromium on the server's virtual display, prefills the listing from the
| canonical payload (App\Support\Citations\ListingPayload), and hands the
| browser to the admin (noVNC) for whatever a human must do.
|
| That browser automation is NOT installed here (see the kit's Citations\
| UnavailableSession::checkRequirements, bound in AppServiceProvider) and,
| unlike gsc/dawnsellshomes, this port does not stand up the Xvfb/x11vnc/
| websockify/Puppeteer machinery at all — it only reports plainly what is
| missing. The board, the canonical payload and manual status/URL/note
| edits are the real feature; every directory is built by hand from the
| payload below it.
|
| Directory roster rewritten for a software company, not a contractor or a
| realtor: review/comparison sites (G2, Capterra, GetApp, Software Advice,
| Product Hunt), a funding/company-profile site (Crunchbase), a B2B
| services directory (Clutch), the LinkedIn company page already tracked
| on Social Media, Google Business Profile and the Better Business Bureau.
*/

return [

    // Where payloads and any future run state would live (one folder per
    // directory). Kept even without a real runner so dirFor()/the
    // screenshot route have a stable, predictable path.
    'storage_dir' => env('CITATIONS_STORAGE_DIR', storage_path('app/citations')),

    // The binaries checkRequirements() looks for. Distinct from the
    // Menards remote-browser feature's own display/ports (:98, 5998,
    // 6098 — see App\Services\MenardsRemoteBrowserService) so the two
    // features can never collide if both are ever installed on the same
    // host.
    'session' => [
        'node_binary' => env('CITATIONS_NODE_BINARY', 'node'),
        'xvfb_binary' => env('CITATIONS_REMOTE_XVFB', 'Xvfb'),
        'x11vnc_binary' => env('CITATIONS_REMOTE_X11VNC', 'x11vnc'),
        'websockify_binary' => env('CITATIONS_REMOTE_WEBSOCKIFY', 'websockify'),
        // Kit 0.11.0: the puppeteer check that used to be hardcoded inside
        // App\Services\Citations\CitationSessionService::checkRequirements()
        // now reads this list (SsSystems\Platform\Citations\
        // UnavailableSession, config('citations.session.node_packages', []))
        // — unchanged set, same reason it was never installed (see that
        // class's docblock: listed in package.json for the unrelated
        // Menards remote-browser feature, `npm install` never run here).
        'node_packages' => ['puppeteer', 'puppeteer-extra', 'puppeteer-extra-plugin-stealth'],
    ],

    /*
    | Directory registry. Keys are the citation slugs.
    |   tier       0 profile exists (complete it), 1 established platform
    |              to claim/create
    |   mechanism  form | account | claim | api | partner | none | dead | farm
    |   needs      what a human will likely be asked for
    |   start_url  where a person would start (homepage when unknown)
    */
    'directories' => [

        // ---- Tier 0: a profile that already exists elsewhere — complete
        // it here rather than create a second one.
        'linkedin' => ['name' => 'LinkedIn company page', 'tier' => 0, 'mechanism' => 'account', 'homepage' => 'https://www.linkedin.com/', 'start_url' => 'https://www.linkedin.com/company/setup/new/', 'needs' => ['account'], 'photos' => true, 'note' => 'Matches the LinkedIn address saved on Social Media — confirm the existing page rather than creating a second one.'],
        'google_business_profile' => ['name' => 'Google Business Profile', 'tier' => 0, 'mechanism' => 'claim', 'homepage' => 'https://www.google.com/business/', 'start_url' => 'https://business.google.com/', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'A software company still benefits from brand and local search presence — claim/confirm the profile and the website link.'],

        // ---- Tier 1: established software-buyer directories to claim/create.
        'g2' => ['name' => 'G2', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://www.g2.com/', 'start_url' => 'https://www.g2.com/products/new', 'needs' => ['account', 'email'], 'photos' => true, 'note' => 'Buyer-review site for B2B software. Verify with a work email address.'],
        'capterra' => ['name' => 'Capterra', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://www.capterra.com/', 'start_url' => 'https://www.capterra.com/vendors/', 'needs' => ['account', 'email'], 'photos' => true],
        'getapp' => ['name' => 'GetApp', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://www.getapp.com/', 'start_url' => 'https://www.getapp.com/getlisted', 'needs' => ['account', 'email'], 'photos' => true, 'note' => 'A Gartner Digital Markets sibling of Capterra/Software Advice — often appears on its own once one of those two is listed; confirm rather than assume.'],
        'software_advice' => ['name' => 'Software Advice', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://www.softwareadvice.com/', 'start_url' => 'https://www.softwareadvice.com/getlisted/', 'needs' => ['account', 'email'], 'photos' => true, 'note' => 'The other Gartner Digital Markets sibling — same note as GetApp.'],
        'product_hunt' => ['name' => 'Product Hunt', 'tier' => 1, 'mechanism' => 'account', 'homepage' => 'https://www.producthunt.com/', 'start_url' => 'https://www.producthunt.com/posts/new', 'needs' => ['account'], 'photos' => true, 'note' => 'A launch post, not a standing profile — post once, then keep the maker profile itself current.'],
        'crunchbase' => ['name' => 'Crunchbase', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://www.crunchbase.com/', 'start_url' => 'https://www.crunchbase.com/organization/add-organization', 'needs' => ['account'], 'photos' => false, 'note' => 'The company profile investors and press check first.'],
        'clutch' => ['name' => 'Clutch', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://clutch.co/', 'start_url' => 'https://clutch.co/get-listed', 'needs' => ['account', 'email'], 'photos' => false, 'note' => 'B2B services directory; may ask for client reviews to verify the listing.'],
        'bbb' => ['name' => 'Better Business Bureau', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://www.bbb.org/', 'start_url' => 'https://www.bbb.org/get-accredited', 'needs' => ['email'], 'photos' => false],
    ],

];
