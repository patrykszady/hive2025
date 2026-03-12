<?php

namespace App\Services;

use App\Models\BlockedCaller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpamFilterService
{
    /**
     * STIR/SHAKEN attestation levels:
     * A = Full attestation (carrier verified the caller)
     * B = Partial attestation (carrier knows the customer but didn't verify the number)
     * C = Gateway attestation (carrier received the call from a gateway, minimal trust)
     * null = No attestation data available
     */
    public const ATTESTATION_A = 'A';
    public const ATTESTATION_B = 'B';
    public const ATTESTATION_C = 'C';

    /**
     * Evaluate whether an incoming call should be blocked.
     *
     * @param  array{
     *     phone_number: string,
     *     vendor_id: ?int,
     *     is_known_caller: bool,
     *     stir_shaken_attestation: ?string,
     *     spam_filter_enabled: bool,
     *     spam_sensitivity: string,
     * }  $context
     * @return array{blocked: bool, reason: ?string}
     */
    public function evaluate(array $context): array
    {
        $phone = $context['phone_number'];
        $vendorId = $context['vendor_id'] ?? null;

        // 1. Check the blocked callers list first
        if ($this->isPhoneBlocked($phone, $vendorId)) {
            return ['blocked' => true, 'reason' => 'blocked_list'];
        }

        // Known callers (matched to a user in the system) are trusted
        if ($context['is_known_caller'] ?? false) {
            return ['blocked' => false, 'reason' => null];
        }

        $attestation = $context['stir_shaken_attestation'] ?? null;
        $sensitivity = $context['spam_sensitivity'] ?? 'medium';

        // 2. STIR/SHAKEN attestation check for unknown callers
        if ($this->shouldBlockByAttestation($attestation, $sensitivity)) {
            $this->autoBlock($phone, $vendorId, "Low STIR/SHAKEN attestation: {$attestation}");
            return ['blocked' => true, 'reason' => 'low_attestation'];
        }

        // 3. Spam database lookup (ipqualityscore) for unknown callers without full attestation
        if ($attestation !== self::ATTESTATION_A) {
            $spamResult = $this->checkSpamDatabase($phone);

            if ($spamResult && $this->shouldBlockBySpamScore($spamResult, $sensitivity)) {
                $score = $spamResult['fraud_score'] ?? 0;
                $this->autoBlock($phone, $vendorId, "Spam score: {$score}");
                return ['blocked' => true, 'reason' => 'spam_score'];
            }
        }

        return ['blocked' => false, 'reason' => null];
    }

    /**
     * Determine if the call should be blocked based on STIR/SHAKEN attestation level.
     */
    protected function shouldBlockByAttestation(?string $attestation, string $sensitivity): bool
    {
        // High sensitivity: block C and unknown attestation
        // Medium sensitivity: block only no attestation
        // Low sensitivity: never block by attestation alone
        return match ($sensitivity) {
            'high' => in_array($attestation, [self::ATTESTATION_C, null], true),
            'medium' => $attestation === null,
            'low' => false,
            default => false,
        };
    }

    /**
     * Determine if the call should be blocked based on spam score and risk signals.
     */
    protected function shouldBlockBySpamScore(array $spamResult, string $sensitivity): bool
    {
        $score = $spamResult['fraud_score'] ?? 0;
        $isVoip = $spamResult['voip'] ?? false;
        $isRisky = $spamResult['risky'] ?? false;
        $isSpammer = $spamResult['spammer'] ?? false;

        // Explicit spammer flag from IPQS — always block
        if ($isSpammer) {
            return true;
        }

        // VOIP numbers get stricter thresholds (common for robocalls/telemarketing)
        if ($isVoip) {
            $threshold = match ($sensitivity) {
                'high' => 50,
                'medium' => 65,
                'low' => 75,
                default => 65,
            };
        } else {
            $threshold = match ($sensitivity) {
                'high' => 75,
                'medium' => 85,
                'low' => 95,
                default => 85,
            };
        }

        return $score >= $threshold;
    }

    /**
     * Check a phone number against the ipqualityscore spam database.
     * Results are cached for 24 hours to conserve API quota.
     *
     * @return array{fraud_score: int, active: bool, valid: bool, risky: bool, spam: bool}|null
     */
    protected function checkSpamDatabase(string $phone): ?array
    {
        $apiKey = config('services.ipqualityscore.api_key');

        if (! $apiKey) {
            return null;
        }

        $cacheKey = 'ipqs_phone_' . md5($phone);

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($apiKey, $phone) {
            try {
                $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

                $response = Http::timeout(5)
                    ->get("https://ipqualityscore.com/api/json/phone/{$apiKey}/{$cleanPhone}");

                if (! $response->successful()) {
                    Log::channel('telnyx')->warning('ipqualityscore API error', [
                        'phone' => $phone,
                        'status' => $response->status(),
                    ]);
                    return null;
                }

                $data = $response->json();

                if (! ($data['success'] ?? false)) {
                    Log::channel('telnyx')->warning('ipqualityscore lookup failed', [
                        'phone' => $phone,
                        'message' => $data['message'] ?? 'unknown',
                    ]);
                    return null;
                }

                return [
                    'fraud_score' => (int) ($data['fraud_score'] ?? 0),
                    'active' => (bool) ($data['active'] ?? true),
                    'valid' => (bool) ($data['valid'] ?? true),
                    'risky' => (bool) ($data['risky'] ?? false),
                    'voip' => (bool) ($data['VOIP'] ?? false),
                    'spammer' => (bool) ($data['spammer'] ?? false),
                    'line_type' => $data['line_type'] ?? null,
                ];
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('ipqualityscore exception', [
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Check if a phone number is on the blocked callers list.
     */
    protected function isPhoneBlocked(string $phone, ?int $vendorId): bool
    {
        return BlockedCaller::isBlocked($phone, $vendorId);
    }

    /**
     * Auto-block a phone number and log the action.
     */
    protected function autoBlock(string $phone, ?int $vendorId, string $reason): void
    {
        BlockedCaller::firstOrCreate(
            ['phone_number' => $phone],
            [
                'vendor_id' => $vendorId,
                'reason' => $reason,
                'auto_blocked' => true,
            ]
        );

        Log::channel('telnyx')->info('Auto-blocked caller', [
            'phone' => $phone,
            'vendor_id' => $vendorId,
            'reason' => $reason,
        ]);
    }
}
