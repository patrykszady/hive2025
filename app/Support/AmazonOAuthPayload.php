<?php

namespace App\Support;

class AmazonOAuthPayload
{
    /**
     * @return array{client_id: string, client_secret: string, refresh_token: string, grant_type: string}
     */
    public static function refreshToken(string $refreshToken): array
    {
        return [
            'client_id' => (string) config('services.amazon.client_id'),
            'client_secret' => (string) config('services.amazon.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];
    }
}
