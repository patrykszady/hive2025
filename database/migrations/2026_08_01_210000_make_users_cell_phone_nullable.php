<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contact we have no phone number for must record that honestly. The column
 * was NOT NULL, which is stricter than the app itself (UserForm already
 * validates cell_phone as `nullable`) and forced provisioning to invent a
 * stand-in number just to satisfy the schema.
 *
 * NULL is safe under the existing UNIQUE index: MySQL treats NULLs as
 * distinct, so any number of users can have no phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cell_phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cell_phone')->nullable(false)->change();
        });
    }
};
