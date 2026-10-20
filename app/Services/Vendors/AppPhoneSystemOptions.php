<?php

namespace App\Services\Vendors;

use App\Livewire\Vendors\PhoneSystemOptions;
use App\Models\User;
use App\Models\Vendor;

/**
 * The app's Phone System screen — the same fields and save rules as the
 * web's /phone-system page (App\Livewire\Vendors\PhoneSystemOptions).
 * Reuses that class's DEFAULT_* message constants directly rather than
 * copying them, so a default ever changed there changes here too.
 *
 * TTS preview (PhoneSystemOptions::previewTts()) stays web-only — see this
 * track's final report for why.
 */
class AppPhoneSystemOptions
{
    /**
     * @return array<string, mixed>
     */
    public function present(Vendor $vendor): array
    {
        $vendorCallRecipients = (array) data_get($vendor->options, 'vendor_call_recipients', []);

        return [
            'call_recipients' => $this->orderedIds($vendor, (array) data_get($vendor->options, 'call_recipients', [])),
            'vendor_call_order_enabled' => $vendorCallRecipients !== [],
            'vendor_call_recipients' => $this->orderedIds($vendor, $vendorCallRecipients),
            'vendor_call_ring_mode' => data_get($vendor->options, 'vendor_call_ring_all') ? 'all' : 'one_by_one',
            'call_welcome_enabled' => (bool) data_get($vendor->options, 'call_welcome_enabled', true),
            'voicemail_enabled' => (bool) data_get($vendor->options, 'voicemail_enabled', true),
            'welcome_message' => data_get($vendor->options, 'welcome_message', '') ?: PhoneSystemOptions::DEFAULT_WELCOME,
            'welcome_message_unknown' => data_get($vendor->options, 'welcome_message_unknown', '') ?: PhoneSystemOptions::DEFAULT_WELCOME_UNKNOWN,
            'screening_message' => data_get($vendor->options, 'screening_message', '') ?: PhoneSystemOptions::DEFAULT_SCREENING,
            'screening_enabled' => (bool) data_get($vendor->options, 'screening_enabled', true),
            'missed_call_text' => data_get($vendor->options, 'missed_call_text', '') ?: PhoneSystemOptions::DEFAULT_MISSED_CALL_TEXT,
            'inbound_callback_text' => data_get($vendor->options, 'inbound_callback_text', '') ?: PhoneSystemOptions::DEFAULT_INBOUND_CALLBACK_TEXT,
            'voicemail_message' => data_get($vendor->options, 'voicemail_message', '') ?: PhoneSystemOptions::DEFAULT_VOICEMAIL,
            'voicemail_message_unknown' => data_get($vendor->options, 'voicemail_message_unknown', '') ?: PhoneSystemOptions::DEFAULT_VOICEMAIL_UNKNOWN,
            'ivr_press1_message' => data_get($vendor->options, 'ivr_press1_message', '') ?: PhoneSystemOptions::DEFAULT_IVR_PRESS1,
            'ivr_press2_message' => data_get($vendor->options, 'ivr_press2_message', '') ?: PhoneSystemOptions::DEFAULT_IVR_PRESS2,
            'voicemail_greeting' => data_get($vendor->options, 'voicemail_greeting', '') ?: PhoneSystemOptions::DEFAULT_VOICEMAIL_GREETING,
            'spam_message' => data_get($vendor->options, 'spam_message', '') ?: PhoneSystemOptions::DEFAULT_SPAM,
            'after_hours_message' => data_get($vendor->options, 'after_hours_message', '') ?: PhoneSystemOptions::DEFAULT_AFTER_HOURS,
            'recording_disclosure_message' => data_get($vendor->options, 'recording_disclosure_message', '') ?: PhoneSystemOptions::DEFAULT_RECORDING_DISCLOSURE,
            'admin_users' => $this->adminUsers($vendor),
        ];
    }

    /**
     * @return list<array{id: int, name: string, cell_phone: ?string}>
     */
    public function adminUsers(Vendor $vendor): array
    {
        return $vendor->getAdminUsersWithCellPhones()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'cell_phone' => $user->cell_phone,
            ])
            ->values()
            ->all();
    }

    /**
     * Ticked ids in call order, limited to admins who can receive calls —
     * mirrors PhoneSystemOptions::orderedIds(). The app sends the full
     * reordered array back on save (its "move up/down" is just a client-side
     * array move), so there is no separate move endpoint.
     *
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function orderedIds(Vendor $vendor, array $ids): array
    {
        $offered = $vendor->getAdminUsersWithCellPhones()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => in_array($id, $offered, true)
        )));
    }

    /**
     * @param  array{call_recipients: list<int>, vendor_call_order_enabled: bool, vendor_call_recipients: list<int>, vendor_call_ring_mode: string, call_welcome_enabled: bool, voicemail_enabled: bool, welcome_message: ?string, welcome_message_unknown: ?string, screening_message: ?string, screening_enabled: bool, missed_call_text: ?string, inbound_callback_text: ?string, voicemail_message: ?string, voicemail_message_unknown: ?string, ivr_press1_message: ?string, ivr_press2_message: ?string, voicemail_greeting: ?string, spam_message: ?string, after_hours_message: ?string, recording_disclosure_message: ?string}  $data
     */
    public function update(Vendor $vendor, array $data): Vendor
    {
        $options = (array) ($vendor->options ?? []);
        $options['call_recipients'] = $this->orderedIds($vendor, $data['call_recipients'] ?? []);
        // Empty = vendors and subs ring the Call Recipients order — same as the web.
        $options['vendor_call_recipients'] = ($data['vendor_call_order_enabled'] ?? false)
            ? $this->orderedIds($vendor, $data['vendor_call_recipients'] ?? [])
            : [];
        $options['vendor_call_ring_all'] = ($data['vendor_call_order_enabled'] ?? false) && ($data['vendor_call_ring_mode'] ?? null) === 'all';
        $options['call_welcome_enabled'] = $data['call_welcome_enabled'] ?? true;
        $options['voicemail_enabled'] = $data['voicemail_enabled'] ?? true;
        $options['screening_enabled'] = $data['screening_enabled'] ?? true;

        // Free-text prompts: every one is optional (nullable on the web
        // too), so an absent key clears it back to the built-in default
        // rather than crashing on a missing array key.
        foreach ([
            'welcome_message', 'welcome_message_unknown', 'screening_message', 'missed_call_text',
            'inbound_callback_text', 'voicemail_message', 'voicemail_message_unknown', 'ivr_press1_message',
            'ivr_press2_message', 'voicemail_greeting', 'spam_message', 'after_hours_message',
            'recording_disclosure_message',
        ] as $key) {
            $options[$key] = $data[$key] ?? null ?: null;
        }

        $vendor->options = $options;
        $vendor->save();

        return $vendor;
    }
}
