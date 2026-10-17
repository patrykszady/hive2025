<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team member's Hive Contractors app calling login (a Telnyx telephony
 * credential on the app connection).
 */
class UserVoiceCredential extends Model
{
    /** @use HasFactory<\Database\Factories\UserVoiceCredentialFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'telnyx_credential_id', 'sip_username', 'last_token_at'];

    protected function casts(): array
    {
        return [
            'last_token_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Where Hive dials to ring this person's app or browser. */
    public function sipUri(): string
    {
        return 'sip:'.$this->sip_username.'@sip.telnyx.com';
    }
}
