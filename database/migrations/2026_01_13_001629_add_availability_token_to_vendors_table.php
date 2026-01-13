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
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('availability_token', 64)
                ->nullable()
                ->unique()
                ->after('business_phone');

            $table->string('availability_short_url')
                ->nullable()
                ->after('availability_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique(['availability_token']);
            $table->dropColumn('availability_short_url');
            $table->dropColumn('availability_token');
        });
    }
};
