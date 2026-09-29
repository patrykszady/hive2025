<?php

use App\Http\Controllers\Api\Admin\V1\PlatformsController;
use SsSystems\Platform\Http\Admin\CapabilityRegistry;
use Illuminate\Support\Facades\Route;

// The Platforms screen: a read-only, server-managed Search Console status
// card, the admin-writable Bing Webmaster Tools key the SEO screen's
// Connect Services modal drives, and (2026-09-26) the connections that
// apply to a software company — Google sign-in (the one shared OAuth
// client, kit 0.14.0), Google Business Profile (connect, one listing,
// reviews) and Meta (Facebook + Instagram). See PlatformsController's
// docblock; every gbp/google endpoint below is the kit's ServesGbpPlatform.
CapabilityRegistry::declare('platforms');

Route::get('platforms/status', [PlatformsController::class, 'status'])->name('platforms.status');
// ss.systems' Platforms Refresh: pull Search Console now (PlatformsController::syncGsc).
Route::post('platforms/gsc/sync', [PlatformsController::class, 'syncGsc'])->name('platforms.gsc.sync');
Route::post('platforms/bing/credentials', [PlatformsController::class, 'saveBingCredentials'])->name('platforms.bing.save');
Route::delete('platforms/bing/credentials', [PlatformsController::class, 'clearBingCredentials'])->name('platforms.bing.clear');

// The other three global SEO-source credentials the Connect Services modal
// drives — see PlatformsController's clarityStatus()/pagespeedStatus()/
// dataForSeoStatus() docblocks.
Route::post('platforms/clarity/credentials', [PlatformsController::class, 'saveClarityCredentials'])->name('platforms.clarity.save');
Route::delete('platforms/clarity/credentials', [PlatformsController::class, 'clearClarityCredentials'])->name('platforms.clarity.clear');
Route::post('platforms/pagespeed/credentials', [PlatformsController::class, 'savePagespeedCredentials'])->name('platforms.pagespeed.save');
Route::delete('platforms/pagespeed/credentials', [PlatformsController::class, 'clearPagespeedCredentials'])->name('platforms.pagespeed.clear');
Route::post('platforms/dataforseo/credentials', [PlatformsController::class, 'saveDataForSeoCredentials'])->name('platforms.dataforseo.save');
Route::delete('platforms/dataforseo/credentials', [PlatformsController::class, 'clearDataForSeoCredentials'])->name('platforms.dataforseo.clear');

Route::post('platforms/seo-credentials/import', [PlatformsController::class, 'importSeoCredentialsFromEnv'])->name('platforms.seo-credentials.import');

// The Google sign-in client is ONE shared client set in the server
// configuration (GOOGLE_OAUTH_CLIENT_ID/_SECRET — kit 0.14.0, "one
// Google"), so both of these refuse in a sentence and store nothing; kept
// routed so ss.systems' card gets that answer rather than a 404.
Route::post('platforms/google/credentials', [PlatformsController::class, 'saveGoogleCredentials'])->name('platforms.google.save');
Route::delete('platforms/google/credentials', [PlatformsController::class, 'clearGoogleCredentials'])->name('platforms.google.clear');

// Which Business Profile listing this app's grant reads reviews from — the
// ids only exist after the OAuth grant, so they are discovered here and
// stored in platform_settings' gbp.* keys (the kit's
// PlatformSettingListingStore).
Route::get('platforms/gbp/listings', [PlatformsController::class, 'gbpListings'])->name('platforms.gbp.listings');
Route::post('platforms/gbp/listing', [PlatformsController::class, 'saveGbpListing'])->name('platforms.gbp.listing.save');

// One listing's Google reviews, for the central admin's import into
// `testimonials` — see TestimonialController's review_urls mapping.
Route::get('platforms/gbp/reviews', [PlatformsController::class, 'gbpReviews'])->name('platforms.gbp.reviews');

// Media: read-only pass-through; POST/DELETE/ledger refuse with a 405 —
// this app has no project photos (see PlatformsController::
// gbpMediaRefusal()).
Route::get('platforms/gbp/media', [PlatformsController::class, 'gbpListMedia'])->name('platforms.gbp.media.index');
Route::post('platforms/gbp/media', [PlatformsController::class, 'uploadGbpMedia'])->name('platforms.gbp.media.store');
Route::delete('platforms/gbp/media', [PlatformsController::class, 'deleteGbpMedia'])->name('platforms.gbp.media.destroy');
Route::put('platforms/gbp/media/ledger', [PlatformsController::class, 'saveGbpMediaLedger'])->name('platforms.gbp.media.ledger');

// Connect / disconnect for both OAuth providers this app has: Google
// Business Profile ('gbp') and Meta ('meta'). Search Console runs on the
// server-held service account, so there is no 'gsc' provider here.
Route::get('platforms/{provider}/oauth-url', [PlatformsController::class, 'oauthUrl'])
    ->whereIn('provider', ['gbp', 'meta'])
    ->name('platforms.oauth-url');
Route::delete('platforms/{provider}', [PlatformsController::class, 'disconnect'])
    ->whereIn('provider', ['gbp', 'meta'])
    ->name('platforms.disconnect');

// Meta (Facebook Page + Instagram Business) — the Platforms card's own
// connection test.
Route::post('platforms/meta/test-connection', [PlatformsController::class, 'testMetaConnection'])->name('platforms.meta.test-connection');
