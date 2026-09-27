<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hive has no `pages` table — the marketing site is Blade views enumerated
 * from routes/web.php's {locale} group plus config('marketing.areas')
 * (App\Support\MarketingPages), never database rows. This table is the ONLY
 * thing an admin edit to a page's SEO actually persists: one row per route
 * (route_name + the non-locale route params, e.g. welcome.feature's
 * area/card), lazily created the first time that page is listed
 * (PageSeoOverride::findOrCreateFor) so its `id` becomes the Pages API's
 * stable integer id — every override column starts null (no edit yet).
 *
 * `params_key` is a deterministic string encoding of the route's params
 * (empty string when there are none) rather than indexing the `route_params`
 * JSON column directly — MySQL cannot put a JSON column in a UNIQUE index.
 * Named explicitly per ss-systems/CLAUDE.md's rule (MySQL's 64-char
 * identifier limit bites Laravel's derived name on a multi-column unique
 * index) even though route_name+params_key is short today.
 *
 * The Services screen (App\Http\Controllers\Api\Admin\V1\ServiceController)
 * reuses these SAME rows for the site's 9 top-level area pages
 * (welcome.{areaKey}) rather than a second table — renaming a "service"
 * there writes the identical title/meta_description columns the Pages
 * screen reads for that page, exactly like dawnsellshomes' ServiceController
 * forwarding onto its PageController@update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_seo_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('route_name');
            $table->json('route_params')->nullable();
            $table->string('params_key')->default('');
            $table->string('title')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();

            $table->unique(['route_name', 'params_key'], 'page_seo_overrides_route_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_seo_overrides');
    }
};
