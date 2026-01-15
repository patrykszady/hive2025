<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftTeamsService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.microsoft_teams.sms_webhook_url');
    }

    public function postText(string $text): void
    {
        $webhookUrl = (string) config('services.microsoft_teams.sms_webhook_url');

        if ($webhookUrl === '') {
            return;
        }

        try {
            Http::timeout(10)->post($webhookUrl, [
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::channel('telnyx')->warning('Failed posting SMS mirror to Teams', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
