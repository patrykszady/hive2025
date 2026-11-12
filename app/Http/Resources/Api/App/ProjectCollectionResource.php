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
 * `reference_frame` is the frame the app's camera lays over the live view
 * for the next shot of a timelapse (see referenceFrame()); an album has
 * none — nothing in it has to line up.
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
        $reference = $this->referenceFrame($frames);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind,
            'is_timelapse' => $this->resource->isTimelapse(),
            'frame_count' => $frames->count(),
            'latest_frame' => $latest ? (new ProjectPhotoResource($latest))->toArray($request) : null,
            'reference_frame' => $reference ? (new ProjectPhotoResource($reference))->withCaptureHints()->toArray($request) : null,
            // The catch-all album can never be deleted — an unaimed upload
            // would have nowhere to go (ProjectTimelapse::generalFor()).
            'can_delete' => $this->canManageImages && $this->title !== 'Project Images',
        ];
    }

    /**
     * The frame the next shot should line up against: the newest one whose
     * displayed copy sits on the sequence's canvas — registered onto the
     * anchor, or the anchor itself. A frame the aligner refused (the stance
     * moved) or has not reached yet still shows as shot, and matching the
     * next photo to THAT would carry its error into every frame after it.
     * With nothing registered at all, the newest frame is the best there is
     * — the same one the web camera ghosts.
     *
     * @param  \Illuminate\Support\Collection<int, ProjectTimelapseFrame>  $frames
     */
    private function referenceFrame($frames): ?ProjectTimelapseFrame
    {
        if (! $this->resource->isTimelapse() || $frames->isEmpty()) {
            return null;
        }

        $anchorId = $this->resource->anchor_frame_id;

        return $frames->last(fn (ProjectTimelapseFrame $frame) => $frame->aligned_path || $frame->id === $anchorId)
            ?? $frames->last();
    }
}
