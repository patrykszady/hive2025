<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Ported verbatim from dawnsellshomes.com's app/Models/OAuthToken.php — this
 * app is single-tenant too, so `provider` alone is unique. Only ever holds a
 * 'google_business_profile' row (ss-platform-kit's
 * SsSystems\Platform\Google\BusinessProfile\Client::PROVIDER, read and
 * written through the kit's Google\Adapters\EloquentTokenStore, which keeps
 * the OAuth client that issued the grant in metadata.oauth_client_id) —
 * Search Console runs on a server-held service account, never OAuth, and
 * Meta's grant lives in `platform_settings` (see
 * App\Services\MetaSocialService's docblock).
 */
class OAuthToken extends Model
{
    protected $table = 'oauth_tokens';

    protected $fillable = [
        'provider',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'scopes',
        'granted_by_email',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value; // fallback: stored in plain text during migration
        }
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function hasValidAccessToken(): bool
    {
        return $this->access_token
            && $this->access_token_expires_at
            && $this->access_token_expires_at->isFuture();
    }

    public static function forProvider(string $provider): ?self
    {
        return static::where('provider', $provider)->first();
    }

    public static function storeTokens(
        string $provider,
        string $refreshToken,
        ?string $accessToken = null,
        ?int $expiresIn = null,
        ?string $email = null,
        ?array $scopes = null,
    ): self {
        return static::updateOrCreate(
            ['provider' => $provider],
            array_filter([
                'refresh_token' => $refreshToken,
                'access_token' => $accessToken,
                'access_token_expires_at' => $expiresIn ? now()->addSeconds($expiresIn - 120) : null,
                'granted_by_email' => $email,
                'scopes' => $scopes,
            ], fn ($v) => $v !== null),
        );
    }
}
