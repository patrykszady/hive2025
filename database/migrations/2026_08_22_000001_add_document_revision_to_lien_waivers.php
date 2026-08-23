<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which generation of a waiver's document is current.
 *
 * A draw stays editable until each waiver comes back signed, so an unsigned
 * waiver's amount can move after its PDF was already emailed. The barcode
 * ("HLW-{id}") identifies the waiver but not the version, so a vendor signing
 * the copy still sitting in their inbox would scan back in and be accepted
 * against a figure that no longer exists.
 *
 * Every revision past the first prints "REV-{n}" in the footer; the scan
 * ingest reads it back and rejects anything older than this column.
 * Everything issued before this feature stays at 1 and is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lien_waivers', function (Blueprint $table) {
            $table->unsignedInteger('document_revision')->default(1)->after('document_hash');
        });
    }

    public function down(): void
    {
        Schema::table('lien_waivers', function (Blueprint $table) {
            $table->dropColumn('document_revision');
        });
    }
};
