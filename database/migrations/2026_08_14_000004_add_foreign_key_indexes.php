<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-wide foreign-key index pass, from an information_schema audit that
 * found dozens of *_id columns with no index. The ones below back queries the
 * app actually runs (relations, global scopes, latestOfMany aggregates).
 *
 * project_status gets a composite (project_id, start_date) because
 * Project::latestStatus is a latestOfMany over MAX(start_date) GROUP BY
 * project_id — same shape as the sms_messages index added earlier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('project_id', 'tasks_project_id_index');
            $table->index('vendor_id', 'tasks_vendor_id_index');
            $table->index('belongs_to_vendor_id', 'tasks_belongs_to_vendor_id_index');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index('client_id', 'projects_client_id_index');
            $table->index('belongs_to_vendor_id', 'projects_belongs_to_vendor_id_index');
        });

        Schema::table('project_status', function (Blueprint $table) {
            $table->index(['project_id', 'start_date'], 'project_status_project_start_index');
            $table->index('belongs_to_vendor_id', 'project_status_belongs_to_vendor_id_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('project_id', 'payments_project_id_index');
            $table->index('check_id', 'payments_check_id_index');
            $table->index('distribution_id', 'payments_distribution_id_index');
            $table->index('belongs_to_vendor_id', 'payments_belongs_to_vendor_id_index');
        });

        Schema::table('expense_splits', function (Blueprint $table) {
            $table->index('expense_id', 'expense_splits_expense_id_index');
            $table->index('project_id', 'expense_splits_project_id_index');
            $table->index('distribution_id', 'expense_splits_distribution_id_index');
            $table->index('belongs_to_vendor_id', 'expense_splits_belongs_to_vendor_id_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index('parent_expense_id', 'expenses_parent_expense_id_index');
            $table->index('distribution_id', 'expenses_distribution_id_index');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->index('check_id', 'timesheets_check_id_index');
            $table->index('project_id', 'timesheets_project_id_index');
            $table->index('vendor_id', 'timesheets_vendor_id_index');
        });

        Schema::table('hours', function (Blueprint $table) {
            $table->index('user_id', 'hours_user_id_index');
            $table->index('timesheet_id', 'hours_timesheet_id_index');
            $table->index('project_id', 'hours_project_id_index');
            $table->index('vendor_id', 'hours_vendor_id_index');
        });

        Schema::table('estimate_sections', function (Blueprint $table) {
            $table->index('estimate_id', 'estimate_sections_estimate_id_index');
            $table->index('bid_id', 'estimate_sections_bid_id_index');
        });

        Schema::table('estimate_line_item', function (Blueprint $table) {
            $table->index('section_id', 'estimate_line_item_section_id_index');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->index('project_id', 'estimates_project_id_index');
            $table->index('belongs_to_vendor_id', 'estimates_belongs_to_vendor_id_index');
        });

        Schema::table('expense_receipts_data', function (Blueprint $table) {
            $table->index('expense_id', 'expense_receipts_data_expense_id_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('vendor_id', 'transactions_vendor_id_index');
            $table->index('plaid_transaction_id', 'transactions_plaid_transaction_id_index');
        });

        Schema::table('vendors_vendor', function (Blueprint $table) {
            // VendorScope filters by belongs_to_vendor_id; the existing
            // composite leads with vendor_id so it can't serve that.
            $table->index('belongs_to_vendor_id', 'vendors_vendor_belongs_to_vendor_id_index');
        });

        Schema::table('distribution_project', function (Blueprint $table) {
            $table->index(['distribution_id', 'project_id'], 'distribution_project_dist_project_index');
            $table->index(['project_id', 'distribution_id'], 'distribution_project_project_dist_index');
        });

        Schema::table('lead_statuses', function (Blueprint $table) {
            $table->index('lead_id', 'lead_statuses_lead_id_index');
        });

        Schema::table('checks', function (Blueprint $table) {
            $table->index('created_by_user_id', 'checks_created_by_user_id_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('primary_vendor_id', 'users_primary_vendor_id_index');
        });
    }

    public function down(): void
    {
        $drops = [
            'tasks' => ['tasks_project_id_index', 'tasks_vendor_id_index', 'tasks_belongs_to_vendor_id_index'],
            'projects' => ['projects_client_id_index', 'projects_belongs_to_vendor_id_index'],
            'project_status' => ['project_status_project_start_index', 'project_status_belongs_to_vendor_id_index'],
            'payments' => ['payments_project_id_index', 'payments_check_id_index', 'payments_distribution_id_index', 'payments_belongs_to_vendor_id_index'],
            'expense_splits' => ['expense_splits_expense_id_index', 'expense_splits_project_id_index', 'expense_splits_distribution_id_index', 'expense_splits_belongs_to_vendor_id_index'],
            'expenses' => ['expenses_parent_expense_id_index', 'expenses_distribution_id_index'],
            'timesheets' => ['timesheets_check_id_index', 'timesheets_project_id_index', 'timesheets_vendor_id_index'],
            'hours' => ['hours_user_id_index', 'hours_timesheet_id_index', 'hours_project_id_index', 'hours_vendor_id_index'],
            'estimate_sections' => ['estimate_sections_estimate_id_index', 'estimate_sections_bid_id_index'],
            'estimate_line_item' => ['estimate_line_item_section_id_index'],
            'estimates' => ['estimates_project_id_index', 'estimates_belongs_to_vendor_id_index'],
            'expense_receipts_data' => ['expense_receipts_data_expense_id_index'],
            'transactions' => ['transactions_vendor_id_index', 'transactions_plaid_transaction_id_index'],
            'vendors_vendor' => ['vendors_vendor_belongs_to_vendor_id_index'],
            'distribution_project' => ['distribution_project_dist_project_index', 'distribution_project_project_dist_index'],
            'lead_statuses' => ['lead_statuses_lead_id_index'],
            'checks' => ['checks_created_by_user_id_index'],
            'users' => ['users_primary_vendor_id_index'],
        ];

        foreach ($drops as $tableName => $indexes) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $index) {
                    $table->dropIndex($index);
                }
            });
        }
    }
};
