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
            $table->string('recording_disk')->nullable()->after('recording_url');
            $table->string('recording_path')->nullable()->after('recording_disk');
            $table->string('recording_telnyx_id')->nullable()->after('recording_path');
            $table->timestamp('recording_started_at')->nullable()->after('recording_telnyx_id');
            $table->boolean('recording_disclosure_played')->default(false)->after('recording_started_at');
            $table->string('language', 8)->nullable()->after('recording_disclosure_played');
            $table->timestamp('purge_after')->nullable()->after('language')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn([
                'recording_disk',
                'recording_path',
                'recording_telnyx_id',
                'recording_started_at',
                'recording_disclosure_played',
                'language',
                'purge_after',
            ]);
        });
    }
};
