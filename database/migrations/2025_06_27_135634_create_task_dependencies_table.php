<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predecessor_task_id')->constrained('tasks')->onDelete('cascade');
            $table->foreignId('successor_task_id')->constrained('tasks')->onDelete('cascade');
            $table->enum('type', ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'])
                  ->default('finish_to_start');
            $table->integer('lag_days')->default(0); // Positive = delay, negative = overlap
            $table->timestamps();

            // Prevent duplicate dependencies
            $table->unique(['predecessor_task_id', 'successor_task_id'], 'unique_task_dependency');

            // Add indexes for better query performance
            $table->index('predecessor_task_id');
            $table->index('successor_task_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('task_dependencies');
    }
};
