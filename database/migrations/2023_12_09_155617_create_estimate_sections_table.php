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
        Schema::create('estimate_sections', function (Blueprint $table) {
            $table->id();
            $table->integer('estimate_id')->unsigned();
            $table->integer('index');
            $table->string('name')->nullable();
            $table->decimal('total');
            $table->integer('bid_id')->unsigned()->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->renameColumn('sections', 'options');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_sections');

        Schema::table('estimates', function (Blueprint $table) {
            $table->renameColumn('options', 'sections');
        });
    }
};
