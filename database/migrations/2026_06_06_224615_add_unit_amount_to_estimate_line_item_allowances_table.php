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
        Schema::table('estimate_line_item_allowances', function (Blueprint $table) {
            $table->decimal('unit_amount', 10, 2)->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_line_item_allowances', function (Blueprint $table) {
            $table->dropColumn('unit_amount');
        });
    }
};
