<?php

namespace App\Http\Resources\Api\App;

use App\Jobs\SummarizeEmailMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stakeholder email's full detail, for the app's email screen — see
 * EmailController::show() for the visibility rule (the viewer must be able
 * to see the email's project, or any project of its client when it has no
 * project yet, and the email must belong to their vendor).
 *
 * `body_latest` is SummarizeEmailMessage::latestPart() on `body_text` (the
 * same quoted-history cut the AI summary reads), `body_full` is the raw
 * text. Attachments carry a day-long signed URL each
 * (EmailMessage::attachmentUrl()) rather than a path, so the app can show
 * them with no session/cookie.
 *
 * @mixin \App\Models\EmailMessage
 */
class EmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $email = $this->resource;
        $project = $email->project_id ? $email->project : null;

        return [
            'id' => $email->id,
            'direction' => $email->direction,
            'subject' => $email->subject,
            'from' => [
                'email' => $email->from_email,
                'name' => $email->from_name,
            ],
            'to' => (array) ($email->to ?? []),
            'cc' => (array) ($email->cc ?? []),
            'sent_at' => $email->sent_at?->toIso8601String(),
            'summary' => $email->summary,
            'action_items' => (array) ($email->action_items ?? []),
            'topics' => (array) ($email->topics ?? []),
            'next_steps' => (array) ($email->next_steps ?? []),
            'sentiment' => $email->sentiment,
            'intent' => $email->intent,
            'body_latest' => SummarizeEmailMessage::latestPart((string) ($email->body_text ?? '')),
            'body_full' => (string) ($email->body_text ?? ''),
            'attachments' => $this->attachmentsArray(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->project_name] : null,
        ];
    }

    /**
     * @return list<array{index: int, name: string, mime: string, size: int, url: string, thumb_url: ?string}>
     */
    private function attachmentsArray(): array
    {
        $email = $this->resource;

        return collect((array) ($email->attachments ?? []))
            ->values()
            ->map(fn (array $file, int $index): array => [
                'index' => $index,
                'name' => (string) ($file['name'] ?? 'attachment'),
                'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
                'size' => (int) ($file['size'] ?? 0),
                'url' => $email->attachmentUrl($index),
                'thumb_url' => str_starts_with((string) ($file['mime'] ?? ''), 'image/') ? $email->attachmentUrl($index, true) : null,
            ])
            ->all();
    }
}
