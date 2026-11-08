<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One Expo push token for one installed copy of the Hive Contractors app —
 * see the migration's docblock for why rows are keyed on `token`, not
 * `user_id`. App\Services\Notifications\AppDevicePush reads these to fan a
 * push out to every device a user has registered, honouring the switches
 * the users page's Notification Settings card sets on each row.
 */
class PushToken extends Model
{
    use HasFactory;

    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    /**
     * The per-device preference columns, named exactly as on
     * push_subscriptions so the users page treats a phone and a browser
     * alike: realtime = task changes during the notification window,
     * morning/evening = the two digests, sms_inbound = text activity (a
     * client's texts and teammates' replies on client threads). `enabled`
     * is the phone's own master switch and deliberately not in this list.
     *
     * @var list<string>
     */
    public const PREFERENCES = ['realtime_enabled', 'morning_enabled', 'evening_enabled', 'sms_inbound_enabled'];

    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'token',
        'platform',
        'device_name',
        'enabled',
        'realtime_enabled',
        'morning_enabled',
        'evening_enabled',
        'sms_inbound_enabled',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'realtime_enabled' => 'boolean',
            'morning_enabled' => 'boolean',
            'evening_enabled' => 'boolean',
            'sms_inbound_enabled' => 'boolean',
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

    /** Phones their owner has not switched off on the users page. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** Switched-on phones that also opted into one of self::PREFERENCES. */
    public function scopeWanting(Builder $query, string $preference): Builder
    {
        if (! in_array($preference, self::PREFERENCES, true)) {
            throw new InvalidArgumentException("Unknown push preference [{$preference}].");
        }

        return $query->where('enabled', true)->where($preference, true);
    }

    /** The device list's label: "Patryk's iPhone", or the platform when the app sent no name. */
    public function displayName(): string
    {
        if ($this->device_name) {
            return $this->device_name;
        }

        return $this->platform === self::PLATFORM_ANDROID ? 'Android phone' : 'iPhone';
    }
}
