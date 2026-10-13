<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where the built package (certificates PDF, Excel) is kept for download. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->string('pdf_path', 255)->nullable()->after('vendors_checked');
            $table->string('xlsx_path', 255)->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn(['pdf_path', 'xlsx_path']);
        });
    }
};
