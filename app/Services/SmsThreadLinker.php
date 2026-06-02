<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfills SmsGroupThread.client_id for unlinked threads when a phone number
 * later becomes associated with a client (new client, new user-client pivot,
 * or cell_phone update).
 */
class SmsThreadLinker
{
    /**
     * Link any unmatched threads whose participants contain $phone to $clientId.
     */
    public function linkThreadsForPhoneToClient(?string $phone, int $clientId): int
    {
        if (! $phone) {
            return 0;
        }

        $e164 = GroupSmsService::formatE164($phone);
        if (! $e164) {
            return 0;
        }

        $updated = SmsGroupThread::query()
            ->whereNull('client_id')
            ->whereJsonContains('participants', $e164)
            ->update([
                'client_id' => $clientId,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            Log::channel('telnyx')->info('Backfilled client_id on inbound SMS threads', [
                'client_id' => $clientId,
                'phone' => $e164,
                'threads_updated' => $updated,
            ]);
        }

        return $updated;
    }

    /**
     * Link unmatched threads using every phone known for a client
     * (home_phone + every attached user's cell_phone).
     */
    public function linkThreadsForClient(Client $client): int
    {
        $client->loadMissing('users');

        $phones = collect([$client->getRawOriginal('home_phone')])
            ->merge($client->users->pluck('cell_phone'))
            ->filter()
            ->unique()
            ->values();

        $total = 0;
        foreach ($phones as $phone) {
            $total += $this->linkThreadsForPhoneToClient($phone, $client->id);
        }

        return $total;
    }

    /**
     * When a user's cell_phone changes (or is first set), link any unmatched
     * threads to every client this user belongs to.
     */
    public function linkThreadsForUser(User $user): int
    {
        $phone = $user->cell_phone;
        if (! $phone) {
            return 0;
        }

        $clientIds = DB::table('client_user')
            ->where('user_id', $user->id)
            ->pluck('client_id');

        $total = 0;
        foreach ($clientIds as $clientId) {
            $total += $this->linkThreadsForPhoneToClient($phone, (int) $clientId);
        }

        return $total;
    }
}
