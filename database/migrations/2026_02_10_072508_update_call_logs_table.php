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
        Schema::table('call_logs', function (Blueprint $table) {
            // Make call_id nullable
            $table->string('call_id')->nullable()->change();

            // Add Telnyx-specific columns
            if (! Schema::hasColumn('call_logs', 'call_control_id')) {
                $table->string('call_control_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('call_logs', 'call_session_id')) {
                $table->string('call_session_id')->nullable()->after('call_control_id')->index();
            }
            if (! Schema::hasColumn('call_logs', 'call_leg_id')) {
                $table->string('call_leg_id')->nullable()->after('call_session_id');
            }
            if (! Schema::hasColumn('call_logs', 'connection_id')) {
                $table->string('connection_id')->nullable()->after('call_leg_id');
            }
            if (! Schema::hasColumn('call_logs', 'forwarded_to')) {
                $table->string('forwarded_to')->nullable()->after('status');
            }
            if (! Schema::hasColumn('call_logs', 'hangup_cause')) {
                $table->string('hangup_cause')->nullable()->after('recording_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('call_id')->nullable(false)->change();

            $columns = ['call_control_id', 'call_session_id', 'call_leg_id', 'connection_id', 'forwarded_to', 'hangup_cause'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('call_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
