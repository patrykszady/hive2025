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
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->integer('user_id')->unsigned();
            $table->integer('vendor_id')->unsigned()->nullable();
            $table->integer('project_id')->unsigned()->nullable();
            $table->integer('hours');
            $table->decimal('amount');
            $table->integer('paid_by')->nullable();
            $table->integer('check_id')->unsigned()->nullable();
            $table->integer('hourly');
            $table->string('invoice')->nullable();
            $table->string('note')->nullable();
            $table->integer('created_by_user_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timesheets');
    }
};
