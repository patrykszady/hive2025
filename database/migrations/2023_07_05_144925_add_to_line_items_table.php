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
        Schema::table('line_items', function (Blueprint $table) {
            //longText
            $table->longText('desc')->nullable()->change();
            $table->longText('notes')->nullable()->change();
        });

        Schema::table('estimate_line_item', function (Blueprint $table) {
            //longText
            $table->longText('desc')->nullable()->change();
            $table->longText('notes')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
