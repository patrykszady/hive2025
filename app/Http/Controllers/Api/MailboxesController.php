<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mailboxes this vendor has connected here — the addresses a partner
 * site (gs.construction) reads for email enquiries, so that every lead
 * starts on ss.systems. Scoped to the token's vendor exactly like the
 * leads API: a partner token only ever sees its own.
 *
 * A mailbox is a company email with a Nylas grant. The crew shared inbox
 * has no grant of its own and is read through a colleague's, so it is
 * listed with that grant and marked shared.
 */
class MailboxesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $vendorId = $request->user()?->vendor?->id;
        if (! $vendorId) {
            return response()->json([
                'message' => 'Authenticated user is not associated with a vendor.',
            ], 403);
        }

        $own = CompanyEmail::query()
            ->where('vendor_id', $vendorId)
            ->whereNotNull('grant_id')
            ->orderBy('id')
            ->get()
            ->map(fn (CompanyEmail $row) => [
                'email' => mb_strtolower(trim((string) $row->email)),
                'grant_id' => (string) $row->grant_id,
                'shared' => false,
            ]);

        $shared = [];
        $crew = config('nylas.crew_leads');
        $crewMailbox = mb_strtolower(trim((string) ($crew['mailbox'] ?? '')));
        $crewGrant = (string) (((array) ($crew['grant_ids'] ?? []))[0] ?? '');

        if ((int) ($crew['vendor_id'] ?? 0) === (int) $vendorId && $crewMailbox !== '' && $crewGrant !== '') {
            $shared[] = ['email' => $crewMailbox, 'grant_id' => $crewGrant, 'shared' => true];
        }

        return response()->json([
            'data' => array_values(array_merge($shared, $own->all())),
        ]);
    }
}
