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
        Schema::table('project_status', function (Blueprint $table) {
            $table->date('start_date')->after('title')->nullable();
            // $table->date('end_date')->after('start_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_status', function (Blueprint $table) {
            $table->dropColumn('start_date');
            // $table->dropColumn('end_date');
        });
    }
};
