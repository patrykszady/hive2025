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
        Schema::create('project_vendor', function (Blueprint $table) {
            $table->id();
            $table->integer('project_id')->unsigned();
            $table->integer('vendor_id')->unsigned();
            $table->integer('client_id')->unsigned();
            // $table->string('belongs_to_vendor_id');
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->integer('vendor_id')->after('zip_code')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->integer('distribution_id')->after('project_id')->nullable();
            $table->integer('project_id')->nullable()->change();
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->json('registration')->after('business_type')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('registration')->after('primary_vendor_id')->nullable();
            $table->dropColumn('email_verified_at');
            $table->string('password')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_vendor');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('vendor_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('distribution_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('registration');
        });
    }
};
