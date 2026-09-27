<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rich-result (structured data) validation issues from GSC's URL
 * Inspection API — ported verbatim from dawnsellshomes' identical table
 * (itself ported from jpeterson-design/gsc), minus site_id (single tenant
 * here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_rich_result_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('url', 2048);
            $table->string('rich_result_type', 120)->nullable()->index();
            $table->string('issue_severity', 40)->nullable()->index();
            $table->string('issue_type', 191)->nullable();
            $table->text('issue_message')->nullable();
            $table->string('verdict', 40)->nullable()->index();
            $table->timestamp('inspected_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_rich_result_issues');
    }
};
