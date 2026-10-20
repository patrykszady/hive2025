<?php

namespace App\Http\Resources\Api\App;

use App\Services\EmailTemplates\AppEmailTemplateDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EmailTemplate
 */
class EmailTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'subject' => $this->subject,
            'body' => $this->body,
            'placeholders' => app(AppEmailTemplateDirectory::class)->placeholders($this->type),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
