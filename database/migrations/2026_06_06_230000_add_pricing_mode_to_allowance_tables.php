<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('estimate_line_item_allowances', function (Blueprint $table) {
            $table->string('pricing_mode')->default('per_unit')->after('description');
        });

        Schema::table('line_item_allowances', function (Blueprint $table) {
            $table->string('pricing_mode')->default('per_unit')->after('description');
        });

        DB::table('estimate_line_item_allowances')
            ->whereNull('unit_amount')
            ->where('description', 'not like', '%$%')
            ->update(['pricing_mode' => 'lump_sum']);

        DB::table('line_item_allowances')
            ->whereNull('unit_amount')
            ->where('description', 'not like', '%$%')
            ->update(['pricing_mode' => 'lump_sum']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_line_item_allowances', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });

        Schema::table('line_item_allowances', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
