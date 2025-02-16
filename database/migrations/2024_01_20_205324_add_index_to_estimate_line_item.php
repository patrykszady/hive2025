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
            // $table->integer('project_id')->unsigned()->nullable();
            $table->integer('section_index')->after('section_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->dropColumn('section_index');
        });
    }
};
