<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-writable settings store, the same table jpeterson-design and
 * dawnsellshomes carry: one row per key, values encrypted at the model
 * layer (App\Models\PlatformSetting's 'encrypted' cast), no site_id since
 * this app is single-tenant. Backs what the central admin saves here — the
 * Bing key from the SEO screen's Connect Services modal, the Social Media
 * profile addresses — so nothing an owner types lands in the environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable(); // encrypted at the model layer
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
