<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A person on a client. Adding or removing one changes the client's name
 * and people in the cached client dropdowns, which no Client event sees:
 * Mary Simon, added to client 338 on 2026-10-16, was missing from the new
 * text thread picker until the cache ran out.
 */
class ClientUser extends Pivot
{
    protected $table = 'client_user';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn (ClientUser $pivot) => Client::bustDropdownCacheForClient((int) $pivot->client_id));
        static::deleted(fn (ClientUser $pivot) => Client::bustDropdownCacheForClient((int) $pivot->client_id));
    }
}
