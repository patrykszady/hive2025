<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('parent_task_id')->nullable()->after('id')->constrained('tasks')->onDelete('cascade');
            $table->index('parent_task_id');
        });
    }

    public function down()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['parent_task_id']);
            $table->dropIndex(['parent_task_id']);
            $table->dropColumn('parent_task_id');
        });
    }
};
