<?php

namespace App\Services\Deliveries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads one inbound text and says whether a supplier is scheduling a
 * delivery or pickup, and when, where and for which order. It never picks
 * the project — DeliveryTextIntake matches the address in code.
 *
 * Returns null when the model could not be asked (no key, outage, junk
 * reply): the caller then records nothing, and a later run tries again.
 */
class DeliveryTextReader
{
    private const SYSTEM = <<<'TXT'
You read a text message sent to GS Construction, a residential remodeling
general contractor in the Chicago suburbs. Senders are suppliers, delivery
services, subcontractors and unknown numbers.

Decide whether the text tells GS that a supplier will DELIVER materials (or
that an order is ready for pickup) on a specific day: delivery schedules,
delivery windows, "your order will arrive", "truck is coming", "confirm
your delivery", reschedules of a delivery.

NOT a delivery: marketing, payment or invoice notices, shipping tracking
with no day, a sub saying when THEY will come to work, questions with no
scheduled day, two-factor codes, anything about a past delivery.

When it is a delivery:
- supplier: the company delivering, as written ("Studio 41").
- date: the delivery day as YYYY-MM-DD. The text was sent on the date given
  with it; resolve weekdays and month/day without a year to the next such
  day on or after that date.
- start_time, end_time: the delivery window in 24-hour HH:MM ("12-3pm" is
  12:00 and 15:00). Null when no time is given; a single time is start_time.
- address: the delivery address exactly as written, or null.
- order_number: the order, PO or reference number, or null.
- needs_confirmation: true when the text asks GS to confirm or the delivery
  will be cancelled.
- summary: one short line for a crew schedule, e.g. "curbside/tailgate only;
  driver calls 30 min before". Null when there is nothing beyond the window.
Otherwise use null (false for needs_confirmation) for all of these.

`confidence` is how sure you are of `is_delivery`, from 0 to 1.
TXT;

    /**
     * @return array{is_delivery: bool, confidence: float, supplier: ?string, date: ?string, start_time: ?string, end_time: ?string, address: ?string, order_number: ?string, needs_confirmation: bool, summary: ?string, reason: ?string, model: string}|null
     */
    public function read(string $text, Carbon $sentAt): ?array
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            return null;
        }

        $model = (string) config('deliveries.sms_intake.model', 'gpt-4.1');
        $user = 'Sent on '.$sentAt->copy()->setTimezone('America/Chicago')->format('l, F j, Y g:i A')." (Chicago).\n\nText:\n".Str::limit($text, 2000, '');

        $nullableString = ['type' => ['string', 'null']];
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_delivery', 'confidence', 'supplier', 'date', 'start_time', 'end_time', 'address', 'order_number', 'needs_confirmation', 'summary', 'reason'],
            'properties' => [
                'is_delivery' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'supplier' => $nullableString,
                'date' => $nullableString,
                'start_time' => $nullableString,
                'end_time' => $nullableString,
                'address' => $nullableString,
                'order_number' => $nullableString,
                'needs_confirmation' => ['type' => 'boolean'],
                'summary' => $nullableString,
                'reason' => ['type' => 'string'],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->retry(2, 2000, throw: false)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'delivery_text', 'strict' => true, 'schema' => $schema],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Delivery texts: reading threw', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Delivery texts: reading failed', ['status' => $response->status()]);

            return null;
        }

        $data = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);

        if (! is_array($data) || ! array_key_exists('is_delivery', $data)) {
            return null;
        }

        return [
            'is_delivery' => (bool) $data['is_delivery'],
            'confidence' => (float) ($data['confidence'] ?? 0),
            'supplier' => $this->clean($data['supplier'] ?? null, 80),
            'date' => $this->date($data['date'] ?? null),
            'start_time' => $this->time($data['start_time'] ?? null),
            'end_time' => $this->time($data['end_time'] ?? null),
            'address' => $this->clean($data['address'] ?? null, 160),
            'order_number' => $this->clean($data['order_number'] ?? null, 60),
            'needs_confirmation' => (bool) ($data['needs_confirmation'] ?? false),
            'summary' => $this->clean($data['summary'] ?? null, 200),
            'reason' => $this->clean($data['reason'] ?? null, 500),
            'model' => $model,
        ];
    }

    private function clean(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }
}
