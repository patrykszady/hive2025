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
        if (! Schema::hasColumn('tasks', 'end_date')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->date('end_date')->after('start_date')->nullable();
            });
        }

        if (! Schema::hasColumn('tasks', 'options')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->json('options')->after('user_id')->nullable();
            });
        }

        if (Schema::hasColumn('tasks', 'position') && ! Schema::hasColumn('tasks', 'duration')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('position', 'duration');
            });
        }

        if (! Schema::hasColumn('tasks', 'order')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->integer('order')->after('duration');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'end_date')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('end_date');
            });
        }

        if (Schema::hasColumn('tasks', 'options')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('options');
            });
        }

        if (Schema::hasColumn('tasks', 'order')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('order');
            });
        }

        if (Schema::hasColumn('tasks', 'duration') && ! Schema::hasColumn('tasks', 'position')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('duration', 'position');
            });
        }
    }
};
