<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The drawing a window or door line carries (App\Support\Windows\WindowDiagram):
 * type, size, hinge side, slide direction, tilt — set when a supplier quote is
 * imported, null on every other line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->json('window_spec')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->dropColumn('window_spec');
        });
    }
};
