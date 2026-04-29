<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->json('from_subject_new')->nullable()->after('from_subject');
        });

        DB::table('receipts')
            ->whereNotNull('from_subject')
            ->orderBy('id')
            ->each(function ($row) {
                $value = (string) $row->from_subject;
                $parts = array_values(array_filter(
                    array_map('trim', explode('|', $value)),
                    fn ($v) => $v !== ''
                ));

                DB::table('receipts')
                    ->where('id', $row->id)
                    ->update([
                        'from_subject_new' => empty($parts) ? null : json_encode($parts),
                    ]);
            });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('from_subject');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->renameColumn('from_subject_new', 'from_subject');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('from_subject_old', 255)->nullable()->after('from_subject');
        });

        DB::table('receipts')
            ->whereNotNull('from_subject')
            ->orderBy('id')
            ->each(function ($row) {
                $decoded = json_decode((string) $row->from_subject, true);
                $value = is_array($decoded) ? implode('|', $decoded) : (string) $row->from_subject;

                DB::table('receipts')
                    ->where('id', $row->id)
                    ->update(['from_subject_old' => $value !== '' ? $value : null]);
            });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('from_subject');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->renameColumn('from_subject_old', 'from_subject');
        });
    }
};
