<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The membership pivots had NO indexes beyond their id primary keys, so every
 * lookup was a full scan. Individually cheap (hundreds of rows), but these
 * tables sit inside correlated EXISTS subqueries (the SMS unread badge walked
 * client_vendor + project_vendor once per thread — ~240k row visits, 69ms on
 * every page load) and inside every policy check and global scope.
 *
 * Both directions are indexed because the app queries them both ways
 * (user->vendors and vendor->users, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_vendor', function (Blueprint $table) {
            $table->index(['client_id', 'vendor_id'], 'client_vendor_client_vendor_index');
            $table->index(['vendor_id', 'client_id'], 'client_vendor_vendor_client_index');
        });

        Schema::table('project_vendor', function (Blueprint $table) {
            $table->index(['project_id', 'vendor_id'], 'project_vendor_project_vendor_index');
            $table->index(['vendor_id', 'project_id'], 'project_vendor_vendor_project_index');
            $table->index('client_id', 'project_vendor_client_id_index');
        });

        Schema::table('user_vendor', function (Blueprint $table) {
            $table->index(['user_id', 'vendor_id'], 'user_vendor_user_vendor_index');
            $table->index(['vendor_id', 'user_id'], 'user_vendor_vendor_user_index');
            $table->index('via_vendor_id', 'user_vendor_via_vendor_id_index');
        });

        Schema::table('client_user', function (Blueprint $table) {
            $table->index(['client_id', 'user_id'], 'client_user_client_user_index');
            $table->index(['user_id', 'client_id'], 'client_user_user_client_index');
        });
    }

    public function down(): void
    {
        Schema::table('client_vendor', function (Blueprint $table) {
            $table->dropIndex('client_vendor_client_vendor_index');
            $table->dropIndex('client_vendor_vendor_client_index');
        });

        Schema::table('project_vendor', function (Blueprint $table) {
            $table->dropIndex('project_vendor_project_vendor_index');
            $table->dropIndex('project_vendor_vendor_project_index');
            $table->dropIndex('project_vendor_client_id_index');
        });

        Schema::table('user_vendor', function (Blueprint $table) {
            $table->dropIndex('user_vendor_user_vendor_index');
            $table->dropIndex('user_vendor_vendor_user_index');
            $table->dropIndex('user_vendor_via_vendor_id_index');
        });

        Schema::table('client_user', function (Blueprint $table) {
            $table->dropIndex('client_user_client_user_index');
            $table->dropIndex('client_user_user_client_index');
        });
    }
};
