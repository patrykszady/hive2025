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
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('end_date')->after('start_date')->nullable();
            $table->json('options')->after('user_id')->nullable();
            $table->renameColumn('position', 'duration');
            $table->integer('order')->after('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('end_date');
            $table->dropColumn('options');
            $table->dropColumn('order');
            $table->renameColumn('duration', 'position');
        });
    }
};
