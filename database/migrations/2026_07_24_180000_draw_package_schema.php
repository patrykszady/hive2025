<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the draw-package / lien-waiver-scan pipeline needs, squashed
 * into one migration (replaces 8 incremental ones that never deployed):
 * user_vendor.position, work_types + vendors.work_type_id, the full
 * sworn_statements table, lien_waivers.sworn_statement_id, and the removal
 * of the e-sign lien_waiver_signatures table (waivers are wet-signed +
 * notarized on paper and returned via the waivers@ scan ingest).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Affiant position ("Secretary") printed on GCSS / affidavits.
        Schema::table('user_vendor', function (Blueprint $table) {
            $table->string('position')->nullable()->after('role_id');
        });

        // Kind-of-work presets, remembered per vendor.
        Schema::create('work_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->string('name');
            $table->timestamps();

            $table->unique(['belongs_to_vendor_id', 'name']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedBigInteger('work_type_id')->nullable()->after('category_id');
        });

        // One GCSS sworn statement per draw; anchors the draw's waivers.
        Schema::create('sworn_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->decimal('this_payment', 12, 2)->default(0);
            // Draft → Sent (draw package emailed) → Signed (notarized scan
            // returned via the waivers@ barcode ingest).
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('path')->nullable();
            $table->string('signed_path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('lien_waivers', function (Blueprint $table) {
            $table->unsignedBigInteger('sworn_statement_id')->nullable()->after('project_id')->index();
        });

        // E-sign workflow removed — no signature pad, no signature records.
        Schema::dropIfExists('lien_waiver_signatures');
    }

    public function down(): void
    {
        Schema::table('lien_waivers', function (Blueprint $table) {
            $table->dropColumn('sworn_statement_id');
        });

        Schema::dropIfExists('sworn_statements');

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('work_type_id');
        });

        Schema::dropIfExists('work_types');

        Schema::table('user_vendor', function (Blueprint $table) {
            $table->dropColumn('position');
        });

        // lien_waiver_signatures is not recreated: the e-sign flow is gone.
    }
};
