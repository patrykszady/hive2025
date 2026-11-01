<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings hive's existing psi_snapshots table (migration
 * 2026_09_27_030000, written for this app's own retired
 * App\Console\Commands\SeoPsiSync) in line with ss-systems/platform-kit's
 * PsiSnapshotStore — see the kit's resources/stubs/seo/
 * create_psi_snapshots_table.php.stub docblock: `site_id` is new,
 * nullable and indexed, folded into the unique key. This site binds
 * PsiSnapshotStore with no tenant column (single-tenant, same as
 * jpeterson-design/dawnsellshomes), so every row's `site_id` stays null —
 * the column only exists so the shape matches the kit's own migration
 * byte-for-byte, with no later migration needed if this site ever becomes
 * multi-tenant. Every existing row/column is kept; nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psi_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->nullable()->index()->after('id');
        });

        Schema::table('psi_snapshots', function (Blueprint $table) {
            $table->dropUnique('psi_snapshots_unique');
            $table->dropIndex('psi_snapshots_url_strategy_date_idx');
        });

        Schema::table('psi_snapshots', function (Blueprint $table) {
            $table->index(['site_id', 'url', 'strategy', 'date']);
            $table->unique(['site_id', 'date', 'url', 'strategy'], 'psi_unique');
        });
    }

    public function down(): void
    {
        Schema::table('psi_snapshots', function (Blueprint $table) {
            $table->dropUnique('psi_unique');
            $table->dropIndex(['site_id', 'url', 'strategy', 'date']);
        });

        Schema::table('psi_snapshots', function (Blueprint $table) {
            $table->index(['url', 'strategy', 'date'], 'psi_snapshots_url_strategy_date_idx');
            $table->unique(['date', 'url', 'strategy'], 'psi_snapshots_unique');
        });

        Schema::table('psi_snapshots', function (Blueprint $table) {
            $table->dropColumn('site_id');
        });
    }
};
