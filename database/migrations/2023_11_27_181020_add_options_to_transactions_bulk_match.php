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
        Schema::table('transactions_bulk_match', function (Blueprint $table) {
            $table->json('options')->after('belongs_to_vendor_id')->nullable();
            $table->integer('distribution_id')->unsigned()->nullable()->change();
        });

        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->decimal('quantity')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions_bulk_match', function (Blueprint $table) {
            $table->dropColumn('options');
            $table->integer('distribution_id')->unsigned();
        });

        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });
    }
};
