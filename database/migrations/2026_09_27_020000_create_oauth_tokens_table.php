<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from dawnsellshomes.com's create_oauth_tokens_table migration
 * (itself ported from jpeterson-design's/gsc's). This app is single-tenant
 * too, so `provider` alone is unique — no site_id. Only ever holds a
 * 'google_business_profile' row (App\Services\GoogleBusinessProfileService)
 * — Search Console runs on a server-held service account
 * (App\Support\Google\ServiceAccountToken), never OAuth, so there is no
 * 'google_search_console' row here. Meta's grant lives in `platform_settings`
 * instead (App\Services\MetaSocialService's docblock explains why), so it
 * never gets a row in this table either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->text('access_token')->nullable();
            $table->text('refresh_token');
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('granted_by_email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_tokens');
    }
};
