<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sub paid for an expense out of pocket (2026-11-02: Stan Palupski bought
 * the basement windows for 3154 Violet, expense 29009), the way an employee
 * does through `paid_by`. The company owes the vendor and settles it on the
 * vendor's next payment check (/vendors/{id}/payment), where the expense is
 * added to the check instead of deducted; the client can still reimburse
 * the company for it (`reimbursment`), independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_by_vendor_id')->nullable()->after('paid_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['paid_by_vendor_id']);
            $table->dropColumn('paid_by_vendor_id');
        });
    }
};
