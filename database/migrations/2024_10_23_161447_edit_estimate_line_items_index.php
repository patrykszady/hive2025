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
        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->renameColumn('section_index', 'order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->renameColumn('order', 'section_index');
        });
    }
};
