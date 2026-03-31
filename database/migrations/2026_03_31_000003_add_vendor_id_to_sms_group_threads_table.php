<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sms_group_threads', 'vendor_id')) {
            return;
        }

        Schema::table('sms_group_threads', function (Blueprint $table): void {
            $table->foreignId('vendor_id')
                ->nullable()
                ->after('client_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['vendor_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sms_group_threads', 'vendor_id')) {
            return;
        }

        Schema::table('sms_group_threads', function (Blueprint $table): void {
            $table->dropIndex('sms_group_threads_vendor_id_last_activity_at_index');
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};