<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of the iPhone app's on-device calling log — calls placed
     * while the app is suspended, backgrounded, or driven from Recents,
     * Siri, or the locked screen run where the Metro log channel is dead,
     * so the app keeps its own log and uploads it here once it can reach
     * the server again. See App\Services\Calls\AppCallingDiagnostics.
     */
    public function up(): void
    {
        Schema::create('app_calling_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null once the call either wasn't given, or wasn't one this
            // user may see — see AppCallingDiagnostics::visibleCallLogId().
            $table->foreignId('call_log_id')->nullable()->constrained()->nullOnDelete();
            $table->string('call_uuid')->nullable();
            $table->string('level', 10);
            $table->string('message', 500);
            $table->json('context')->nullable();
            // The app's own clock, when the event actually happened on the
            // device — may lag created_at by however long the upload waited.
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'occurred_at']);
            $table->index('call_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_calling_events');
    }
};
