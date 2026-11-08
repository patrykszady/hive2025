<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One Expo push token for one installed copy of the Hive Contractors app —
 * see the migration's docblock for why rows are keyed on `token`, not
 * `user_id`. App\Jobs\SendExpoPushForNotification reads these to fan an
 * AppNotification out to every device a user has registered.
 */
class PushToken extends Model
{
    use HasFactory;

    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'token',
        'platform',
        'device_name',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }
}
