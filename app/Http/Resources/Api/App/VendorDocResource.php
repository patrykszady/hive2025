<?php

namespace App\Http\Resources\Api\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A single certificate/license as the app's Vendor Documents screen shows
 * it — mirrors VendorDocsCard's columns (type, expiration, policy number,
 * verification) plus a signed link to the file itself (vendor-docs.file.signed).
 *
 * @mixin \App\Models\VendorDoc
 */
class VendorDocResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $verification = $this->verification_summary;

        return [
            'id' => $this->id,
            'type' => (string) $this->getRawOriginal('type'),
            'type_label' => $this->type_label,
            'number' => $this->number,
            'effective_date' => $this->effective_date?->toDateString(),
            'expiration_date' => $this->expiration_date?->toDateString(),
            'expired' => $this->expiration_date !== null && $this->expiration_date->lte(today()),
            'agent' => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->name, 'email' => $this->agent->email] : null,
            'verified' => (bool) ($verification['verified'] ?? false),
            'verified_at' => $verification['verified_at'] ?? null,
            'alert' => (bool) ($verification['alert'] ?? false),
            'tip' => (string) ($verification['tip'] ?? ''),
            'file_url' => $this->doc_filename
                ? URL::temporarySignedRoute('vendor-docs.file.signed', now()->addHours(24), ['vendorDoc' => $this->id])
                : null,
        ];
    }
}
