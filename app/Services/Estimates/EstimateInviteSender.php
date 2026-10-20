<?php

namespace App\Services\Estimates;

use App\Mail\EstimateSigningInvite;
use App\Mail\WelcomeClient;
use App\Models\Estimate;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "Send Invite" emails for an estimate — the shared body of
 * App\Livewire\Estimates\EstimateShow::sendInvite() and the Hive Contractors
 * app's EstimateInviteController::send(), so both surfaces decide and send
 * identically:
 *
 * - Once the vendor has signed but the contract isn't fully signed yet, this
 *   sends a contract SIGNING invite to every client user with an email who
 *   hasn't signed yet.
 * - Otherwise (no vendor signature on file), this sends the general
 *   WELCOME invite to every client user with an email, so they can register
 *   and later sign once the vendor has.
 */
class EstimateInviteSender
{
    public const KIND_SIGNING = 'signing';

    public const KIND_WELCOME = 'welcome';

    /**
     * @return array{kind: string, sent: int, reason: ?string}
     */
    public function send(Estimate $estimate): array
    {
        $found = Estimate::withoutGlobalScopes()
            ->with(['signatures', 'project.client.users'])
            ->find($estimate->id);

        if (! $found) {
            return ['kind' => '', 'sent' => 0, 'reason' => 'Unable to send invites for this estimate.'];
        }

        if ($found->isVendorSigned() && ! $found->isFullySigned()) {
            $clientUsers = $found->project?->client?->users ?? collect();
            $signedUserIds = $found->signatures->pluck('user_id')->all();
            $pendingRecipients = $clientUsers
                ->filter(fn ($user) => filled($user->email) && ! in_array($user->id, $signedUserIds));

            if ($pendingRecipients->isEmpty()) {
                return ['kind' => self::KIND_SIGNING, 'sent' => 0, 'reason' => 'All client users have signed or are missing an email address.'];
            }

            $sent = 0;
            foreach ($pendingRecipients as $user) {
                Mail::mailer('mailtrap-sdk')->to($user->email)->send(
                    new EstimateSigningInvite($found, $user->first_name ?? '')
                );
                $sent++;
            }

            return ['kind' => self::KIND_SIGNING, 'sent' => $sent, 'reason' => null];
        }

        $client = $estimate->client;
        $vendorId = $estimate->belongs_to_vendor_id;
        $allUsers = $client?->users ?? collect();

        // The registration.registered flag is unreliable on legacy/imported
        // users, so send to every client user with an email and let
        // recipients ignore it if they're already signed up.
        $users = $allUsers->filter(fn ($u) => filled($u->email));

        if ($users->isEmpty()) {
            return ['kind' => self::KIND_WELCOME, 'sent' => 0, 'reason' => 'No client users with an email address.'];
        }

        $sent = 0;
        foreach ($users as $user) {
            if (! $user->email) {
                continue;
            }

            Mail::mailer('mailtrap-sdk')->to($user->email)->send(
                new WelcomeClient($vendorId, $user->first_name ?? '')
            );
            $sent++;
        }

        return ['kind' => self::KIND_WELCOME, 'sent' => $sent, 'reason' => null];
    }
}
