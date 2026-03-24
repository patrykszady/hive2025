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
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('expense_id');
            $table->index('bank_account_id');
            $table->index('check_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index('date');
            $table->index('vendor_id');
            $table->index('category_id');
            $table->index('check_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['expense_id']);
            $table->dropIndex(['bank_account_id']);
            $table->dropIndex(['check_id']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['date']);
            $table->dropIndex(['vendor_id']);
            $table->dropIndex(['category_id']);
            $table->dropIndex(['check_id']);
        });
    }
};
