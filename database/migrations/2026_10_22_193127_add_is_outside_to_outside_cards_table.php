<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cards are now judged automatically (OutsideCardService); a row here is
     * a person's override: is_outside = false is "It's ours" on a card the
     * app took for someone else's.
     */
    public function up(): void
    {
        Schema::table('outside_cards', function (Blueprint $table) {
            $table->boolean('is_outside')->default(true)->after('last_four');
        });
    }

    public function down(): void
    {
        Schema::table('outside_cards', function (Blueprint $table) {
            $table->dropColumn('is_outside');
        });
    }
};
