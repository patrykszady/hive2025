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
        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->json('name_data')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->dropColumn('name_data');
        });
    }
};
