<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier's quote imported as an estimate section (2026-10-26) rides the
 * same table as a sub's invoice compared against the estimate: `kind` tells
 * them apart, `markup_percent` is what was applied to the supplier's list
 * prices, and `estimate_section_id` is the section the import created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_sub_documents', function (Blueprint $table) {
            $table->string('kind', 16)->default('invoice')->after('status');
            $table->decimal('markup_percent', 5, 2)->default(0)->after('kind');
            $table->unsignedBigInteger('estimate_section_id')->nullable()->after('markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_sub_documents', function (Blueprint $table) {
            $table->dropColumn(['kind', 'markup_percent', 'estimate_section_id']);
        });
    }
};
