<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CallLog extends Model
{
    use HasFactory;
    // Status constants
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_TRANSFERRED = 'transferred';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_MISSED = 'missed';
    public const STATUS_VOICEMAIL = 'voicemail';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'call_id',
        'call_control_id',
        'call_session_id',
        'call_leg_id',
        'connection_id',
        'direction',
        'from_number',
        'to_number',
        'caller_name',
        'status',
        'forwarded_to',
        'duration_seconds',
        'disconnect_cause',
        'hangup_cause',
        'notes',
        'recording_url',
        'has_voicemail',
        'project_id',
        'user_id',
        'client_id',
        'contact_user_id',
        'metadata',
        'answered_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'has_voicemail' => 'boolean',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Find a call log by its Telnyx call_control_id.
     */
    public static function findByCallControlId(string $callControlId): ?self
    {
        return static::where('call_control_id', $callControlId)->first();
    }

    /**
     * Find a call log by its session ID (groups both legs of a transfer).
     */
    public static function findBySessionId(string $sessionId): ?self
    {
        return static::where('call_session_id', $sessionId)
            ->where('direction', 'incoming')
            ->first();
    }

    /**
     * Look up calling user by phone number.
     */
    public function lookUpCaller(): ?User
    {
        $digits = preg_replace('/\D/', '', $this->from_number);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return User::where('cell_phone', 'LIKE', "%{$digits}%")->first();
    }

    /**
     * Look up caller name via Telnyx CNAM (Number Lookup API).
     * Results are cached for 30 days to avoid repeat API calls.
     */
    public function lookUpCallerViaCnam(): ?string
    {
        $phone = $this->from_number;

        if (! $phone || $phone === 'unknown') {
            return null;
        }

        $cacheKey = 'cnam_' . md5($phone);

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($phone) {
            $apiKey = config('services.telnyx.api_key');

            if (! $apiKey) {
                return null;
            }

            try {
                $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

                if (! str_starts_with($cleanPhone, '+')) {
                    $cleanPhone = '+' . $cleanPhone;
                }

                $response = Http::timeout(5)
                    ->withToken($apiKey)
                    ->get("https://api.telnyx.com/v2/number_lookup/{$cleanPhone}", [
                        'type' => 'caller-name',
                    ]);

                if (! $response->successful()) {
                    Log::channel('telnyx')->warning('CNAM lookup failed', [
                        'phone' => $phone,
                        'status' => $response->status(),
                    ]);
                    return null;
                }

                $data = $response->json('data');
                $cnam = data_get($data, 'caller_name.caller_name');

                if ($cnam && data_get($data, 'caller_name.error_code') === null) {
                    // CNAM from carriers is typically ALL CAPS "LAST FIRST" — flip to "FIRST LAST"
                    // but keep ALL CAPS so the UI can distinguish lookups from known users
                    $parts = preg_split('/\s+/', trim($cnam));
                    if (count($parts) === 2 && preg_match('/^[A-Z]+\s[A-Z]+$/', trim($cnam))) {
                        $cnam = $parts[1] . ' ' . $parts[0];
                    }

                    return $cnam;
                }

                return null;
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('CNAM lookup exception', [
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }
}
