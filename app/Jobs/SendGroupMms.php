<?php

namespace App\Jobs;

use App\Events\SmsMessageReceived;
use App\Models\SmsMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Cache;

class SendGroupMms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $messageId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new ThrottlesExceptions(3, 60),
        ];
    }

    public function handle(): void
    {
        $message = SmsMessage::find($this->messageId);

        if (! $message || $message->status === 'sent') {
            return;
        }

        // Rate limit: 1 Group MMS per 5 seconds per thread.
        // Prevents AT&T carrier-level throttling on 10DLC.
        $lockKey = 'sms-send-thread:' . $message->thread_id;
        $lock = Cache::lock($lockKey, 5);

        if (! $lock->get()) {
            // Another message is being sent to this thread — retry after 5s
            $this->release(5);

            return;
        }

        $apiKey = config('services.telnyx.api_key');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');
        $participants = $message->to_numbers ?? [];
        $text = $message->text;
        $mediaUrls = $message->media_urls ?? [];

        try {
            if (count($participants) > 1 || ! empty($mediaUrls)) {
                $result = $this->sendGroupMms($apiKey, $messagingProfileId, $message->from_number, $participants, $text, $mediaUrls);
            } else {
                $result = $this->sendSms($apiKey, $messagingProfileId, $message->from_number, $participants[0], $text);
            }

            $message->update([
                'provider_message_id' => $result['id'] ?? null,
                'status' => 'sent',
                'raw_payload' => $result,
            ]);
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Failed to send group message', [
                'message_id' => $this->messageId,
                'to' => $participants,
                'error' => $e->getMessage(),
            ]);

            $message->update(['status' => 'failed']);
        }

        // Broadcast update so conversation refreshes with the final status
        if ($message->thread_id) {
            try {
                SmsMessageReceived::dispatch($message->thread_id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SMS broadcast failed', [
                    'thread_id' => $message->thread_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendGroupMms(string $apiKey, ?string $messagingProfileId, string $from, array $to, string $text, array $mediaUrls = []): array
    {
        $payload = [
            'from' => $from,
            'to' => $to,
            'text' => $text,
            'subject' => ' ',
        ];

        if (! empty($mediaUrls)) {
            $payload['media_urls'] = $mediaUrls;
        }

        if ($messagingProfileId) {
            $payload['messaging_profile_id'] = $messagingProfileId;
        }

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages/group_mms', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Telnyx Group MMS API error: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendSms(string $apiKey, ?string $messagingProfileId, string $from, string $to, string $text): array
    {
        $payload = [
            'from' => $from,
            'to' => $to,
            'text' => $text,
        ];

        if ($messagingProfileId) {
            $payload['messaging_profile_id'] = $messagingProfileId;
        }

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Telnyx API error: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }
}
