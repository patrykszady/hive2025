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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('business_name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::table('vendor_docs', function (Blueprint $table) {
            $table->integer('agent_id')->after('number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');

        Schema::table('vendor_docs', function (Blueprint $table) {
            $table->dropColumn('agent_id');
        });
    }
};
