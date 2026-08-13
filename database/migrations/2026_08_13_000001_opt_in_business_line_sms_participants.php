<?php

use App\Models\SmsGroupThread;
use App\Models\SmsThreadParticipant;
use App\Services\GroupSmsService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Office lines (vendor business_phone) never belong in group messages —
     * they can't reply START and shouldn't receive thread traffic meant for
     * people. Remove them from every thread that has other participants
     * (both the participant ledger row and the thread's send list), and mark
     * the rare office-only thread as consented so it never shows "Awaiting
     * START reply". Sends nothing.
     */
    public function up(): void
    {
        SmsGroupThread::query()
            ->with('threadParticipants')
            ->each(function (SmsGroupThread $thread) {
                $participants = $thread->threadParticipants;

                if ($participants->isEmpty()) {
                    return;
                }

                $businessLines = $participants->filter(
                    fn (SmsThreadParticipant $p) => GroupSmsService::isBusinessLine($p->phone_number)
                );

                if ($businessLines->isEmpty()) {
                    return;
                }

                if ($businessLines->count() < $participants->count()) {
                    // People are on the thread — the office line leaves it.
                    $removedNumbers = $businessLines
                        ->map(fn (SmsThreadParticipant $p) => GroupSmsService::formatE164($p->phone_number))
                        ->all();

                    SmsThreadParticipant::whereIn('id', $businessLines->pluck('id'))->delete();

                    $thread->update([
                        'participants' => collect($thread->participants ?? [])
                            ->map(fn (string $phone) => GroupSmsService::formatE164($phone))
                            ->reject(fn (string $phone) => in_array($phone, $removedNumbers, true))
                            ->values()
                            ->all(),
                    ]);

                    return;
                }

                // Office-only thread (deliberate solo text): keep it, but it
                // never needs START consent.
                $businessLines->each(function (SmsThreadParticipant $p) {
                    if ($p->opted_in_at === null) {
                        $p->update([
                            'opted_in_at' => now(),
                            'manual_opt_in_reason' => 'Office line — consent not required',
                        ]);
                    }
                });
            });
    }

    public function down(): void
    {
        // Removed participant rows are not restorable; nothing to undo safely.
    }
};
