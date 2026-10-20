<?php

namespace App\Http\Resources\Api\App;

use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the project's named collections for the Hive Contractors app's
 * Collections hub (the web studio's per-collection card) — a timelapse
 * sequence or the catch-all "Project Images" album. `frames` is already
 * eager-loaded by App\Services\Projects\TimelapseCollectionService::
 * collectionsFor(), ordered play-order, so the latest frame is simply the
 * last one.
 *
 * @mixin \App\Models\ProjectTimelapse
 */
class ProjectCollectionResource extends JsonResource
{
    public function __construct(ProjectTimelapse $collection, private readonly bool $canManageImages)
    {
        parent::__construct($collection);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \Illuminate\Support\Collection<int, ProjectTimelapseFrame> $frames */
        $frames = $this->resource->frames;
        $latest = $frames->last();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind,
            'is_timelapse' => $this->resource->isTimelapse(),
            'frame_count' => $frames->count(),
            'latest_frame' => $latest ? (new ProjectPhotoResource($latest))->toArray($request) : null,
            // The catch-all album can never be deleted — an unaimed upload
            // would have nowhere to go (ProjectTimelapse::generalFor()).
            'can_delete' => $this->canManageImages && $this->title !== 'Project Images',
        ];
    }
}
