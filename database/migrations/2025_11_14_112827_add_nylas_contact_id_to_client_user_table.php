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
        Schema::table('client_user', function (Blueprint $table) {
            // Store Nylas contact IDs as JSON: { "grant_id_1": "contact_id_1", "grant_id_2": "contact_id_2" }
            // This allows multiple contacts per user (one per vendor's grant_id)
            $table->json('nylas_contact_ids')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_user', function (Blueprint $table) {
            $table->dropColumn('nylas_contact_ids');
        });
    }
};
