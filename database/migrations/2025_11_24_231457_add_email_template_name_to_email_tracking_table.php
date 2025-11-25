<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_tracking', function (Blueprint $table) {
            $table->string('email_template_name')->nullable()->after('nylas_thread_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_tracking', function (Blueprint $table) {
            $table->dropColumn('email_template_name');
        });
    }
};
