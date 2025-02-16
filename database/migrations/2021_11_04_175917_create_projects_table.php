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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_name');
            $table->integer('client_id')->unsigned();
            $table->integer('belongs_to_vendor_id');
            // $table->integer('created_by_user_id');
            $table->string('note')->nullable();
            $table->string('do_not_include')->nullable();
            $table->string('address');
            $table->string('address_2')->nullable();
            $table->string('city');
            $table->string('state');
            $table->integer('zip_code');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
