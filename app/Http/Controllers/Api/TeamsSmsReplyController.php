<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Services\TelnyxService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TeamsSmsReplyController extends Controller
{
    public function __construct(
        protected TelnyxService $telnyx
    ) {}

    /**
     * Receive a reply from Microsoft Teams (typically via Power Automate) and send it as SMS.
     *
     * Expected payload:
     * - to: string (E.164)
     * - text: string
     *
     * Auth:
     * - X-Hive-Teams-Token header must match services.microsoft_teams.inbound_token
     */
    public function handle(Request $request): Response
    {
        $expectedToken = (string) config('services.microsoft_teams.inbound_token');
        $providedToken = (string) $request->header('X-Hive-Teams-Token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response('', 401);
        }

        $validated = $request->validate([
            'to' => ['required', 'string'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $to = $validated['to'];
        $text = $validated['text'];

        try {
            $result = $this->telnyx->sendSms(to: $to, text: $text);

            SmsMessage::query()->create([
                'provider' => 'telnyx',
                'provider_message_id' => $result['id'] ?? null,
                'direction' => 'outbound',
                'from_number' => $result['from'] ?? (string) config('services.telnyx.from'),
                'to_numbers' => is_array($result['to'] ?? null) ? $result['to'] : [$result['to'] ?? $to],
                'text' => $text,
                'raw_payload' => [
                    'source' => 'microsoft_teams',
                    'request' => $request->all(),
                    'telnyx_result' => $result,
                ],
            ]);

            return response('', 200);
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Failed sending SMS from Teams', [
                'error' => $e->getMessage(),
                'to' => $to,
            ]);

            return response('', 500);
        }
    }
}
