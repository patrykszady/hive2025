<?php

namespace App\Http\Resources\Api\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A timelapse frame for the Hive Contractors app's photo gallery — the same
 * session-less signed URL pattern as ProjectResource::latestPhotos(), so
 * the gallery and the project detail's "latest photos" strip show
 * interoperable links.
 *
 * @mixin \App\Models\ProjectTimelapseFrame
 */
class ProjectPhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->signedUrl(thumb: false),
            'thumb_url' => $this->signedUrl(thumb: true),
            'taken_at' => $this->shot_at?->toIso8601String(),
            'taken_by' => $this->resource->taker_name,
        ];
    }

    private function signedUrl(bool $thumb): string
    {
        return URL::temporarySignedRoute(
            'projects.timelapse.frame.signed',
            now()->addHours(24),
            array_filter(['frame' => $this->id, 'thumb' => $thumb ? 1 : null]),
        );
    }
}
