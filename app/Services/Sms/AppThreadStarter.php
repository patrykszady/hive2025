<?php

namespace App\Services\Sms;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Services\GroupSmsService;
use Illuminate\Validation\ValidationException;

/**
 * Starts a new SMS thread from the app the way the web's
 * App\Livewire\Sms\SmsNewThread::send() starts one: resolve the participant
 * phones, reuse an existing thread for that exact participant set instead of
 * duplicating it, then hand off to GroupSmsService for the real send
 * (consent prompt, opt-in bookkeeping, broadcast) — nothing here re-sends
 * anything itself.
 *
 * GroupSmsService::sendNewGroup()'s initial message is always its own
 * auto-built greeting + START prompt; it ignores whatever text is passed in,
 * exactly like the web's new-thread modal does (SmsNewThreadTest covers this
 * for the web: a thread started with "Ignored manual text" sends the
 * canonical consent message instead). The $body this class is given is
 * therefore never the first SMS — it exists only to satisfy the same
 * validation the web modal has, for parity. Once recipients reply START, a
 * real first message goes through
 * Api\App\V1\ThreadMessageController::store().
 */
class AppThreadStarter
{
    public function __construct(private GroupSmsService $smsService)
    {
    }

    /**
     * @param  array<int, string>  $phones  Ignored when $clientId is given.
     */
    public function start(array $phones, ?int $clientId, string $body, User $actingUser): SmsGroupThread
    {
        if ($clientId) {
            // withoutGlobalScopes(): the client may be hidden by ClientScope
            // in the acting user's vendor context — same reasoning as
            // ConversationPresenter::loadThreadForDisplay().
            $client = Client::query()->withoutGlobalScopes()->with('users')->find($clientId);

            if (! $client) {
                throw ValidationException::withMessages(['client_id' => 'The selected client is invalid.']);
            }

            $phones = $this->clientPhones($client);

            if ($phones === []) {
                throw ValidationException::withMessages(['client_id' => 'This client has no phone numbers on file.']);
            }
        } else {
            $phones = collect($phones)
                ->map(fn (string $phone): string => GroupSmsService::formatE164($phone))
                ->unique()
                ->values()
                ->all();
        }

        $existing = $this->findExistingThread($phones, $clientId);

        if ($existing) {
            return $existing;
        }

        return $this->smsService->sendNewGroup(
            $phones,
            $body,
            null,
            $clientId,
            $actingUser->id,
            $actingUser->vendor?->id,
            null,
        );
    }

    /**
     * Every phone number on file for a client: home phone plus each
     * household user's cell — mirrors
     * SmsNewThread::addClientPhoneNumbers().
     *
     * @return array<int, string>
     */
    private function clientPhones(Client $client): array
    {
        $phones = collect();

        $homePhone = $client->getRawOriginal('home_phone');
        if (is_string($homePhone) && $homePhone !== '') {
            $phones->push(GroupSmsService::formatE164($homePhone));
        }

        foreach ($client->users as $user) {
            $cellPhone = $user->getRawOriginal('cell_phone');
            if (is_string($cellPhone) && $cellPhone !== '') {
                $phones->push(GroupSmsService::formatE164($cellPhone));
            }
        }

        return $phones->unique()->values()->all();
    }

    /**
     * A thread already addressed to exactly this participant set — mirrors
     * the duplicate check in SmsNewThread::send(), except the app returns
     * the existing thread instead of erroring, since there is no "pick the
     * existing one" picker on this side.
     *
     * @param  array<int, string>  $phones
     */
    private function findExistingThread(array $phones, ?int $clientId): ?SmsGroupThread
    {
        $query = SmsGroupThread::query()->when(
            $clientId,
            fn ($q) => $q->where('client_id', $clientId),
            fn ($q) => $q->whereNull('client_id'),
        );

        foreach ($phones as $phone) {
            $query->whereJsonContains('participants', $phone);
        }

        return $query->whereJsonLength('participants', count($phones))->first();
    }
}
