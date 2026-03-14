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
        Schema::table('receipt_accounts', function (Blueprint $table) {
            $table->dropColumn(['distribution_id', 'project_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipt_accounts', function (Blueprint $table) {
            $table->integer('project_id')->nullable();
            $table->integer('distribution_id')->nullable();
        });
    }
};
