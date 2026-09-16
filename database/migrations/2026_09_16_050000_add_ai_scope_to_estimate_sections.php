<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the estimator asked the AI generator for, and how it read the scope,
 * kept on the section the draft went into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_sections', function (Blueprint $table) {
            $table->text('ai_inquiry')->nullable()->after('bid_id');
            $table->text('ai_scope')->nullable()->after('ai_inquiry');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_sections', function (Blueprint $table) {
            $table->dropColumn(['ai_inquiry', 'ai_scope']);
        });
    }
};
