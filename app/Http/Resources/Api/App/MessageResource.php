<?php

namespace App\Http\Resources\Api\App;

use App\Support\Sms\ConversationPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SmsMessage
 */
class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'body' => $this->display_text,
            // Absolute, session-less URLs — see ConversationPresenter::publicMediaUrl().
            'media_urls' => collect($this->media_urls ?? [])
                ->map(fn (string $url) => ConversationPresenter::publicMediaUrl($url))
                ->values()
                ->all(),
            'status' => $this->status,
            'sent_at' => $this->created_at?->toIso8601String(),
            'sender_name' => $this->isOutbound() ? $this->outboundSenderName() : null,
        ];
    }

    /**
     * The sender shown above an outbound bubble: the acting user's name, or
     * "GS Crew" for an automated send (sent_by_user_id null — consent/welcome
     * prompts, scheduled blasts sent by the system).
     */
    private function outboundSenderName(): string
    {
        $sender = $this->sentByUser;

        if (! $sender) {
            return 'GS Crew';
        }

        return trim(($sender->nickname ?: $sender->first_name) . ' ' . $sender->last_name);
    }
}
