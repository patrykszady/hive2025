<?php

namespace App\Exceptions\LienWaivers;

use App\Models\SwornStatement;
use RuntimeException;

/**
 * Thrown by App\Services\LienWaivers\DrawPackageService when a draw's GCSS
 * has already come back signed & notarized — its sworn figures are settled,
 * so it can no longer be opened for editing or re-saved. Both
 * App\Livewire\LienWaivers\Index and the app API controller catch this to
 * word their own toast/JSON message (they differ: "can no longer be
 * edited" when opening, "was signed while you were editing" when saving).
 */
class DrawAlreadySignedException extends RuntimeException
{
    public function __construct(public readonly SwornStatement $statement)
    {
        parent::__construct('Draw '.$statement->drawNumber().' has a signed sworn statement.');
    }

    public function drawNumber(): int
    {
        return $this->statement->drawNumber();
    }
}
