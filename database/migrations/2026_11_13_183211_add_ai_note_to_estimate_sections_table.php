<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The estimator's own note for a section — typed on the AI generator,
     * sent to Claude for that section alone — kept on the section it drafted,
     * beside the scope Claude wrote back (ai_scope).
     */
    public function up(): void
    {
        Schema::table('estimate_sections', function (Blueprint $table) {
            $table->text('ai_note')->nullable()->after('ai_scope');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_sections', function (Blueprint $table) {
            $table->dropColumn('ai_note');
        });
    }
};
