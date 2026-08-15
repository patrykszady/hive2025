<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the query shapes the payroll/finance pages run constantly.
 *
 * Measured with EXPLAIN before adding (dev copy of prod data):
 *   timesheets  — NO indexes at all beyond PRIMARY; every payroll lookup was
 *                 a full scan of ~7,200 rows (type=ALL).
 *   checks      — same, full scan of ~3,800 rows on every checks query.
 *   expenses    — fell back to the check_id index and examined ~13,200 rows
 *                 (half the table) for paid_by / reimbursment lookups.
 *   transactions— bank_account_id alone, so date-bounded reads still ranged
 *                 over the account's whole history.
 *
 * Composite column order follows the WHERE clauses (equality first, then the
 * range/secondary filter), so these serve the existing queries unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->index(['user_id', 'check_id'], 'timesheets_user_check_index');
            $table->index(['paid_by', 'check_id'], 'timesheets_paid_by_check_index');
            $table->index('date', 'timesheets_date_index');
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->index(['belongs_to_vendor_id', 'date'], 'checks_owner_date_index');
            $table->index('vendor_id', 'checks_vendor_id_index');
            $table->index('user_id', 'checks_user_id_index');
            $table->index('bank_account_id', 'checks_bank_account_id_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['paid_by', 'check_id'], 'expenses_paid_by_check_index');
            $table->index(['reimbursment', 'check_id'], 'expenses_reimbursment_check_index');
            $table->index(['belongs_to_vendor_id', 'date'], 'expenses_owner_date_index');
            $table->index('project_id', 'expenses_project_id_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['bank_account_id', 'transaction_date'], 'transactions_account_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropIndex('timesheets_user_check_index');
            $table->dropIndex('timesheets_paid_by_check_index');
            $table->dropIndex('timesheets_date_index');
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->dropIndex('checks_owner_date_index');
            $table->dropIndex('checks_vendor_id_index');
            $table->dropIndex('checks_user_id_index');
            $table->dropIndex('checks_bank_account_id_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_paid_by_check_index');
            $table->dropIndex('expenses_reimbursment_check_index');
            $table->dropIndex('expenses_owner_date_index');
            $table->dropIndex('expenses_project_id_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_account_date_index');
        });
    }
};
