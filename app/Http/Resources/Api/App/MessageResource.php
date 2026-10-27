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
            // When it went out for a scheduled text, else when it was written (SmsMessage::shown_at).
            'sent_at' => $this->shown_at?->toIso8601String(),
            // Waiting to go out (the composer's "Schedule send"), or saved as
            // "schedule only" until someone sends it by hand.
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sender_name' => $this->isOutbound() ? ConversationPresenter::outboundSenderName($this->resource) : null,
        ];
    }
}
