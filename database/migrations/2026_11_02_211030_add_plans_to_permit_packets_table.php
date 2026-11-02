<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A permit packet takes any number of plan PDFs (2026-11-02): the
 * architect's set, the lighting plan, the survey — each its own file,
 * merged in upload order. The one file a packet could hold so far becomes
 * the first entry of the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permit_packets', function (Blueprint $table) {
            $table->json('plans')->nullable()->after('include_contract');
        });

        DB::table('permit_packets')->whereNotNull('plans_path')->orderBy('id')->each(function (object $row): void {
            DB::table('permit_packets')->where('id', $row->id)->update([
                'plans' => json_encode([['path' => $row->plans_path, 'name' => $row->plans_name ?: basename((string) $row->plans_path)]]),
            ]);
        });

        Schema::table('permit_packets', function (Blueprint $table) {
            $table->dropColumn(['plans_path', 'plans_name']);
        });
    }

    public function down(): void
    {
        Schema::table('permit_packets', function (Blueprint $table) {
            $table->string('plans_path')->nullable()->after('include_contract');
            $table->string('plans_name')->nullable()->after('plans_path');
        });

        DB::table('permit_packets')->whereNotNull('plans')->orderBy('id')->each(function (object $row): void {
            $first = json_decode((string) $row->plans, true)[0] ?? null;
            DB::table('permit_packets')->where('id', $row->id)->update([
                'plans_path' => $first['path'] ?? null,
                'plans_name' => $first['name'] ?? null,
            ]);
        });

        Schema::table('permit_packets', function (Blueprint $table) {
            $table->dropColumn('plans');
        });
    }
};
