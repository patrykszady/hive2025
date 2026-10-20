<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateSignature;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Link estimate signatures saved without a user (39 on production, Feb–Jul
 * 2026) to the person who signed. Without the link Estimate::isFullySigned()
 * can't count them, so a signed contract's sections never lock and the
 * line-item "Credit" to a change order never appears (Jordans' #224 read as
 * unsigned with all four signatures on it).
 *
 * A signature is matched by its email among the estimate's team and client
 * contacts, then by that email among all users, then by first and last name
 * (middle initials ignored) among the estimate's people — and only linked
 * when exactly one person fits. Safe to run again.
 */
class BackfillEstimateSignatureUsers extends Command
{
    protected $signature = 'estimates:backfill-signature-users
        {--dry-run : List the matches without saving them}';

    protected $description = 'Link estimate signatures saved without a user to the person who signed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $linked = 0;
        $unmatched = 0;

        $signatures = EstimateSignature::query()->whereNull('user_id')->orderBy('id')->get();

        foreach ($signatures as $signature) {
            $estimate = Estimate::withoutGlobalScopes()->withTrashed()->find($signature->estimate_id);
            $user = $estimate ? $this->signerFor($signature, $estimate) : null;

            if (! $user || $estimate->signatures()->where('user_id', $user->id)->exists()) {
                $this->warn("#{$signature->id} estimate {$signature->estimate_id} \"{$signature->signer_name}\" <{$signature->signer_email}> — no single match");
                $unmatched++;

                continue;
            }

            $this->line("#{$signature->id} estimate {$signature->estimate_id} \"{$signature->signer_name}\" → user {$user->id} ({$user->first_name} {$user->last_name})");
            $linked++;

            if (! $dryRun) {
                $signature->forceFill(['user_id' => $user->id])->save();
            }
        }

        $this->info(sprintf('%s %d, %d left unmatched.', $dryRun ? 'Would link' : 'Linked', $linked, $unmatched));

        return self::SUCCESS;
    }

    protected function signerFor(EstimateSignature $signature, Estimate $estimate): ?User
    {
        $people = $this->peopleOn($estimate);
        $email = mb_strtolower(trim((string) $signature->signer_email));

        if ($email !== '') {
            $byEmail = $people->filter(fn (User $user) => mb_strtolower((string) $user->email) === $email);
            if ($byEmail->count() === 1) {
                return $byEmail->first();
            }

            $anywhere = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->get();
            if ($anywhere->count() === 1) {
                return $anywhere->first();
            }
        }

        $words = preg_split('/\s+/', mb_strtolower(trim((string) $signature->signer_name)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) {
            return null;
        }
        [$first, $last] = [$words[0], $words[count($words) - 1]];

        $byName = $people->filter(fn (User $user) => mb_strtolower((string) $user->last_name) === $last
            && in_array($first, [mb_strtolower((string) $user->first_name), mb_strtolower((string) $user->nickname)], true));

        return $byName->count() === 1 ? $byName->first() : null;
    }

    /**
     * Everyone who could have signed: the estimate company's team and the
     * project's client contacts (by projects.client_id — Project::client()
     * is scoped to whoever is signed in).
     *
     * @return Collection<int, User>
     */
    protected function peopleOn(Estimate $estimate): Collection
    {
        $team = $estimate->vendor()->withoutGlobalScopes()->first()?->users()->withoutGlobalScopes()->get() ?? collect();

        $clientId = $estimate->project()->withoutGlobalScopes()->value('client_id');
        $clients = $clientId ? (Client::withoutGlobalScopes()->find($clientId)?->users()->withoutGlobalScopes()->get() ?? collect()) : collect();

        return $team->concat($clients)->unique('id')->values();
    }
}
