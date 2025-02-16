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
        Schema::create('hours', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->decimal('hours');
            $table->integer('project_id')->unsigned();
            $table->integer('user_id')->unsigned();
            $table->integer('vendor_id')->unsigned();
            $table->integer('timesheet_id')->unsigned()->nullable();
            $table->integer('created_by_user_id');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hours');
    }
};
