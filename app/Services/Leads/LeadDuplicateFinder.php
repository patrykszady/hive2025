<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;

/**
 * A lead or client contact already on file with this email or phone — shown
 * as a warning before creating a brand new lead by hand, so the same person
 * doesn't end up with two lead histories or a "lead" record duplicating an
 * existing client. Shared by App\Livewire\Leads\LeadCreate::save() (which
 * delegates its findExistingContact() here) and the Hive Contractors app's
 * lead creation endpoint, so a hand-typed duplicate reads the same warning
 * on both.
 */
class LeadDuplicateFinder
{
    /**
     * @return array{kind: string, label: string, lead_id: int|null, client_id: int|null}|null
     */
    public function find(?string $email, ?string $phone, int $vendorId): ?array
    {
        $email = strtolower(trim((string) $email));
        $digits = preg_replace('/\D/', '', (string) $phone) ?: null;
        if ($digits && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        $lead = Lead::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $vendorId)
            ->where(function ($q) use ($email, $digits) {
                $q->when($email !== '', fn ($inner) => $inner->where('lead_data->email', $email));
                $q->when($digits, fn ($inner) => $inner->orWhere('lead_data->phone', $digits));
            })
            ->latest('id')
            ->first();

        if ($lead) {
            return [
                'kind' => 'lead',
                'label' => trim((string) ($lead->lead_data['name'] ?? 'a lead')).' — '.($lead->last_status?->title ?? 'lead').' since '.$lead->date?->format('M j'),
                'lead_id' => $lead->id,
                'client_id' => null,
            ];
        }

        $user = User::query()
            ->where(function ($q) use ($email, $digits) {
                $q->when($email !== '', fn ($inner) => $inner->whereRaw('LOWER(email) = ?', [$email]));
                $q->when($digits, fn ($inner) => $inner->orWhere('cell_phone', $digits));
            })
            ->first();

        if ($user) {
            // Scoped to THIS tenant — otherwise the label below could name a
            // client that belongs to a different company entirely.
            $client = $user->clients()
                ->withoutGlobalScopes()
                ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
                ->first();

            return [
                'kind' => $client ? 'client' : 'user',
                'label' => trim($user->full_name).($client ? ' — existing client '.$client->name : ' — existing contact'),
                'lead_id' => null,
                'client_id' => $client?->id,
            ];
        }

        return null;
    }
}
