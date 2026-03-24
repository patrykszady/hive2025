<?php

namespace App\Console\Commands;

use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use Illuminate\Console\Command;

class FixGroupMmsThread extends Command
{
    protected $signature = 'app:fix-group-mms-thread';

    protected $description = 'Move misrouted group MMS messages (J Peterson + Lunardinis) into a dedicated thread';

    public function handle(): int
    {
        $gsNumber = '+12247354200';
        $jenniferPhone = '+18478097344';
        $christinePhone = '+18479773481';
        $scottPhone = '+12243305634';

        $groupPhones = [$jenniferPhone, $christinePhone, $scottPhone];

        // Find messages on threads 17 or 24 that are actually group MMS
        // (raw_payload.to contains all 3 external phones + our GS number).
        $misrouted = SmsMessage::query()
            ->whereIn('thread_id', function ($q) use ($gsNumber) {
                $q->select('id')
                    ->from('sms_group_threads')
                    ->where('from_number', $gsNumber)
                    ->whereIn('client_id', [143, 250]);
            })
            ->where('direction', 'inbound')
            ->whereIn('from_number', $groupPhones)
            ->get()
            ->filter(function (SmsMessage $msg) use ($groupPhones, $gsNumber) {
                $to = data_get($msg->raw_payload, 'to', []);
                $toPhones = collect($to)->pluck('phone_number')->all();
                $allParticipants = array_merge($toPhones, [$msg->from_number]);

                // Must contain our number + all 3 external phones
                return in_array($gsNumber, $allParticipants)
                    && count(array_intersect($groupPhones, $allParticipants)) === count($groupPhones);
            });

        if ($misrouted->isEmpty()) {
            $this->info('No misrouted group MMS messages found. Already fixed or no data to migrate.');

            return self::SUCCESS;
        }

        $this->info("Found {$misrouted->count()} misrouted group MMS message(s).");
        $misrouted->each(fn ($m) => $this->line("  - #{$m->id} on thread {$m->thread_id}: {$m->from_number} ({$m->created_at})"));

        // Find or create the combined group thread.
        $thread = SmsGroupThread::where('from_number', $gsNumber)
            ->whereJsonContains('participants', $jenniferPhone)
            ->whereJsonContains('participants', $christinePhone)
            ->whereJsonContains('participants', $scottPhone)
            ->first();

        if (! $thread) {
            $thread = SmsGroupThread::create([
                'from_number' => $gsNumber,
                'client_id' => 143,
                'participants' => $groupPhones,
                'name' => 'J Peterson Designs, Christine & Scott Lunardini',
                'name_data' => [
                    ['label' => 'J Peterson Designs', 'client_id' => 143],
                    [
                        'label' => 'Christine & Scott Lunardini',
                        'client_id' => 250,
                        'phones' => ['📞 (847) 977-3481', '📞 (224) 330-5634'],
                    ],
                ],
            ]);
            $this->info("Created new group thread #{$thread->id}.");
        } else {
            $this->info("Found existing group thread #{$thread->id}.");
        }

        // Move messages to the group thread.
        $moved = SmsMessage::whereIn('id', $misrouted->pluck('id'))
            ->update(['thread_id' => $thread->id]);

        $this->info("Moved {$moved} message(s) to thread #{$thread->id}.");

        return self::SUCCESS;
    }
}
