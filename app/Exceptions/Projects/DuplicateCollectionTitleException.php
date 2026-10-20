<?php

namespace App\Exceptions\Projects;

use RuntimeException;

/**
 * Thrown by App\Services\Projects\TimelapseCollectionService::createCollection()
 * when the project already has a collection with that title. Both
 * App\Livewire\Projects\TimelapseStudio and the Hive Contractors app's
 * ProjectCollectionController catch this to word their own validation error
 * — the Livewire component's field is `newTitle`, the app's request field is
 * `title`, so the message is reused but the field name is not.
 */
class DuplicateCollectionTitleException extends RuntimeException
{
    public function __construct(public readonly string $title)
    {
        parent::__construct('This project already has a collection with that name.');
    }
}
