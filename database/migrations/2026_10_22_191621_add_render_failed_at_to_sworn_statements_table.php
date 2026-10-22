<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a draw's GCSS fails to re-render (Chrome can't start), the old
     * document is kept and the statement is flagged, so the next download
     * renders it again. Draw 3 on project 364 gained Climate Solutions on
     * 2026-10-22 while renders were failing, and kept a statement without it.
     */
    public function up(): void
    {
        Schema::table('sworn_statements', function (Blueprint $table) {
            $table->timestamp('render_failed_at')->nullable()->after('filename');
        });

        // Open statements that gained a waiver after their document was last
        // written are stale the same way.
        DB::table('sworn_statements')
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'signed'))
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('lien_waivers')
                ->whereColumn('lien_waivers.sworn_statement_id', 'sworn_statements.id')
                ->whereNull('lien_waivers.deleted_at')
                ->whereColumn('lien_waivers.created_at', '>', 'sworn_statements.updated_at'))
            ->update(['render_failed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('sworn_statements', function (Blueprint $table) {
            $table->dropColumn('render_failed_at');
        });
    }
};
