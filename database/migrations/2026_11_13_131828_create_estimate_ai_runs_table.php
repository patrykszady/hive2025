<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of the AI estimate generator over a whole estimate: what was
 * asked, the sections it was to fill, and how the run is going. The run is
 * drafted by a queued job (a whole estimate takes longer than a web request
 * may), so this row is also what the generator's window follows while the
 * lines land. Each section the run fills keeps its own estimate_ai_drafts
 * row, tied back here by run_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('request_id')->nullable();
            $table->string('model')->nullable();
            // queued → drafting → finished | failed | stopped (stopping while a stop is asked for)
            $table->string('status', 20)->default('queued');
            $table->text('inquiry');
            // The sections the estimator listed, each with its room measurements; empty when the model names them.
            $table->json('plan')->nullable();
            // The whole plan's numbers, as sent.
            $table->json('floorplan')->nullable();
            // The sections as they were drafted: which estimate section each landed in and whether the run made it.
            $table->json('sections')->nullable();
            $table->text('reasoning')->nullable();
            $table->json('usage')->nullable();
            $table->string('stop_reason')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['estimate_id', 'status']);
        });

        Schema::table('estimate_ai_drafts', function (Blueprint $table) {
            $table->foreignId('run_id')->nullable()->after('section_id')->constrained('estimate_ai_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_ai_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('run_id');
        });

        Schema::dropIfExists('estimate_ai_runs');
    }
};
