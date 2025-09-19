<?php

namespace App\Services;

class GiftCardService
{
    /**
     * Placeholder implementation to satisfy DI and unblock controllers.
     * Implement real capture logic (Nylas + Puphpeteer) separately.
     */
    public function captureLatest(int $companyEmailId): array
    {
        return [
            'success' => false,
            'message' => 'Gift card capture not configured yet.',
            'company_email_id' => $companyEmailId,
        ];
    }
}
