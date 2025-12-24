<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Add belongs_to_vendor_id and backfill to 1.
        if (! Schema::hasColumn('email_tracking', 'belongs_to_vendor_id')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->unsignedBigInteger('belongs_to_vendor_id')
                    ->nullable()
                    ->after('project_id');
            });
        }

        DB::statement('UPDATE email_tracking SET belongs_to_vendor_id = 1 WHERE belongs_to_vendor_id IS NULL');

        // 2) Normalize nylas_* columns to message_id/thread_id.
        $hasLegacyMessage = Schema::hasColumn('email_tracking', 'nylas_message_id');
        $hasLegacyThread = Schema::hasColumn('email_tracking', 'nylas_thread_id');

        if ($hasLegacyMessage && ! Schema::hasColumn('email_tracking', 'message_id')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->string('message_id')->nullable()->after('project_id');
            });
        }

        if ($hasLegacyThread && ! Schema::hasColumn('email_tracking', 'thread_id')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->string('thread_id')->nullable()->after(Schema::hasColumn('email_tracking', 'message_id') ? 'message_id' : 'project_id');
            });
        }

        if ($hasLegacyMessage && Schema::hasColumn('email_tracking', 'message_id')) {
            DB::statement('UPDATE email_tracking SET message_id = nylas_message_id WHERE (message_id IS NULL OR message_id = \'\') AND nylas_message_id IS NOT NULL');
        }

        if ($hasLegacyThread && Schema::hasColumn('email_tracking', 'thread_id')) {
            DB::statement('UPDATE email_tracking SET thread_id = nylas_thread_id WHERE (thread_id IS NULL OR thread_id = \'\') AND nylas_thread_id IS NOT NULL');
        }

        // 3) Drop legacy indexes (if they exist), then drop legacy columns.
        $this->dropIndexIfExists('email_tracking', 'email_tracking_nylas_message_id_index');
        $this->dropIndexIfExists('email_tracking', 'email_tracking_nylas_message_id_event_type_index');
        $this->dropIndexIfExists('email_tracking', 'email_tracking_nylas_thread_id_index');

        if ($hasLegacyMessage) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->dropColumn('nylas_message_id');
            });
        }

        if ($hasLegacyThread) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->dropColumn('nylas_thread_id');
            });
        }

        // 4) Add normalized indexes (use new names to avoid collisions).
        $this->createIndexIfMissing('email_tracking', 'email_tracking_message_id_index', 'message_id');
        $this->createIndexIfMissing('email_tracking', 'email_tracking_message_id_event_type_index', ['message_id', 'event_type']);
        $this->createIndexIfMissing('email_tracking', 'email_tracking_thread_id_index', 'thread_id');
        $this->createIndexIfMissing('email_tracking', 'email_tracking_belongs_to_vendor_id_index', 'belongs_to_vendor_id');
    }

    public function down(): void
    {
        // Best-effort rollback.
        $this->dropIndexIfExists('email_tracking', 'email_tracking_message_id_index');
        $this->dropIndexIfExists('email_tracking', 'email_tracking_message_id_event_type_index');
        $this->dropIndexIfExists('email_tracking', 'email_tracking_thread_id_index');
        $this->dropIndexIfExists('email_tracking', 'email_tracking_belongs_to_vendor_id_index');

        if (! Schema::hasColumn('email_tracking', 'nylas_message_id')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->string('nylas_message_id')->nullable()->after('project_id');
            });
        }

        if (! Schema::hasColumn('email_tracking', 'nylas_thread_id')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->string('nylas_thread_id')->nullable()->after('nylas_message_id');
            });
        }

        if (Schema::hasColumn('email_tracking', 'message_id')) {
            DB::statement('UPDATE email_tracking SET nylas_message_id = message_id WHERE (nylas_message_id IS NULL OR nylas_message_id = \'\') AND message_id IS NOT NULL');
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->dropColumn('message_id');
            });
        }

        if (Schema::hasColumn('email_tracking', 'thread_id')) {
            DB::statement('UPDATE email_tracking SET nylas_thread_id = thread_id WHERE (nylas_thread_id IS NULL OR nylas_thread_id = \'\') AND thread_id IS NOT NULL');
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->dropColumn('thread_id');
            });
        }

        if (Schema::hasColumn('email_tracking', 'belongs_to_vendor_id')) {
            Schema::table('email_tracking', function (Blueprint $table) {
                $table->dropColumn('belongs_to_vendor_id');
            });
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $exists = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        if (count($exists) === 0) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
    }

    /**
     * @param  string|array<int, string>  $columns
     */
    private function createIndexIfMissing(string $table, string $indexName, string|array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = is_array($columns) ? $columns : [$columns];

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $exists = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        if (count($exists) > 0) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($columns, $indexName) {
            $tableBlueprint->index($columns, $indexName);
        });
    }
};
