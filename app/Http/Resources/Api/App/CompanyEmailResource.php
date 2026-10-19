<?php

namespace App\Http\Resources\Api\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected mailbox as the app's Email Accounts screen shows it — same
 * fields CompanyEmailsIndex's index.blade.php reads off `$email->health`.
 *
 * @mixin \App\Models\CompanyEmail
 */
class CompanyEmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{state: string, reason: ?string, provider: ?string, grant_email: ?string, checked_at: string} $health */
        $health = $this->health;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'grant_id' => $this->grant_id,
            'state' => $health['state'],
            'reason' => $health['reason'],
            'provider' => $health['provider'],
            'grant_email' => $health['grant_email'],
            'checked_at' => $health['checked_at'],
        ];
    }
}
