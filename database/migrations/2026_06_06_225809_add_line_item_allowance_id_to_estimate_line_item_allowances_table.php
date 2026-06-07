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
            $table->unsignedBigInteger('line_item_allowance_id')->nullable()->after('estimate_line_item_id');

            $table->foreign('line_item_allowance_id')
                ->references('id')
                ->on('line_item_allowances')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_line_item_allowances', function (Blueprint $table) {
            $table->dropForeign(['line_item_allowance_id']);
            $table->dropColumn('line_item_allowance_id');
        });
    }
};
