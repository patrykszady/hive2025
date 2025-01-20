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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('primary');
            $table->string('detailed');
            $table->string('friendly_primary'); //TRANSFER_IN = Transfer In
            $table->string('friendly_detailed'); //TV_AND_MOVIES = Tv and Movies
            $table->string('icon_url');
            $table->timestamps();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->integer('category_id')->after('invoice')->unsigned()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('category_id');
        });
    }
};
