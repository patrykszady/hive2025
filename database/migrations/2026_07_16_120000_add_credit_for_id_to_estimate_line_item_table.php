<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_line_item', function (Blueprint $table) {
            // Links a "Credit: …" line item back to the signed line item it
            // offsets, so the original can badge "Credit on line item 3.1".
            $table->unsignedBigInteger('credit_for_id')->nullable()->after('line_item_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->dropColumn('credit_for_id');
        });
    }
};
