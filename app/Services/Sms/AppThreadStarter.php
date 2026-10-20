<?php

namespace App\Services\Sms;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
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
     * @param  array<int, string>  $phones  With $clientId or $vendorId: the web's finer
     *     per-person picker (GET recipient-presets) narrows to this subset of the
     *     client's/vendor's own numbers; empty (the default) still means "everyone",
     *     same as before this picker existed.
     */
    public function start(array $phones, ?int $clientId, string $body, User $actingUser, ?int $vendorId = null): SmsGroupThread
    {
        if ($vendorId) {
            // Deliberately NOT scoped to the acting vendor's own related
            // vendors — same reasoning SmsNewThread's vendor branch and
            // AssignThreadRequest's vendor_id rule use: texting a vendor not
            // yet related to this tenant is how a new sub relationship starts.
            $vendor = Vendor::query()->withoutGlobalScopes()->with('users')->find($vendorId);

            if (! $vendor) {
                throw ValidationException::withMessages(['vendor_id' => 'The selected vendor is invalid.']);
            }

            $allowedPhones = $this->vendorPhones($vendor);

            if ($allowedPhones === []) {
                throw ValidationException::withMessages(['vendor_id' => 'This vendor has no phone numbers on file.']);
            }

            $phones = $this->resolveRequestedPhones($phones, $allowedPhones);
        } elseif ($clientId) {
            // withoutGlobalScopes(): the client may be hidden by ClientScope
            // in the acting user's vendor context — same reasoning as
            // ConversationPresenter::loadThreadForDisplay().
            $client = Client::query()->withoutGlobalScopes()->with('users')->find($clientId);

            if (! $client) {
                throw ValidationException::withMessages(['client_id' => 'The selected client is invalid.']);
            }

            $allowedPhones = $this->clientPhones($client);

            if ($allowedPhones === []) {
                throw ValidationException::withMessages(['client_id' => 'This client has no phone numbers on file.']);
            }

            $phones = $this->resolveRequestedPhones($phones, $allowedPhones);
        } else {
            $phones = collect($phones)
                ->map(fn (string $phone): string => GroupSmsService::formatE164($phone))
                ->unique()
                ->values()
                ->all();
        }

        $existing = $this->findExistingThread($phones, $clientId, $vendorId);

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
            $vendorId,
        );
    }

    /**
     * Narrow to the caller's requested numbers when they picked specific
     * ones (the recipient-presets picker) — but only the subset that
     * actually belongs to this client/vendor, so a caller can never slip in
     * an unrelated number by hand. An empty/entirely-foreign request falls
     * back to every number on file, same as before this picker existed.
     *
     * @param  array<int, string>  $requested
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private function resolveRequestedPhones(array $requested, array $allowed): array
    {
        $requested = collect($requested)
            ->map(fn (string $phone): string => GroupSmsService::formatE164($phone))
            ->unique()
            ->values()
            ->all();

        $filtered = array_values(array_intersect($requested, $allowed));

        return $filtered !== [] ? $filtered : $allowed;
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
     * Every phone number on file for a vendor: its business line plus each
     * of its users' cells — mirrors
     * SmsNewThread::addVendorPhoneNumbers()/buildVendorRecipientPresetOptions().
     *
     * @return array<int, string>
     */
    private function vendorPhones(Vendor $vendor): array
    {
        $phones = collect();

        $businessPhone = $vendor->getRawOriginal('business_phone');
        if (is_string($businessPhone) && $businessPhone !== '') {
            $phones->push(GroupSmsService::formatE164($businessPhone));
        }

        foreach ($vendor->users as $user) {
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
    private function findExistingThread(array $phones, ?int $clientId, ?int $vendorId = null): ?SmsGroupThread
    {
        $query = SmsGroupThread::query();

        if ($vendorId) {
            $query->where('subject_vendor_id', $vendorId);
        } elseif ($clientId) {
            $query->where('client_id', $clientId);
        } else {
            $query->whereNull('client_id')->whereNull('subject_vendor_id');
        }

        foreach ($phones as $phone) {
            $query->whereJsonContains('participants', $phone);
        }

        return $query->whereJsonLength('participants', count($phones))->first();
    }
}
