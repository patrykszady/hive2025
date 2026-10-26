<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The time of a visit an action item came from ("measurement Thursday
 * 10/29 between 11-1pm" → 11:00–13:00), carried into the task it becomes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_action_items', function (Blueprint $table) {
            $table->string('start_time', 5)->nullable()->after('due_on');
            $table->string('end_time', 5)->nullable()->after('start_time');
        });
    }

    public function down(): void
    {
        Schema::table('project_action_items', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
