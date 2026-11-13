<?php

namespace App\Exceptions\Estimates;

use RuntimeException;

/**
 * A ticked row of the AI generator names a section that is not (or is no
 * longer) one of the estimate's — another estimate's id, or a section removed
 * since the list was shown. Nothing is drafted; the list is refreshed.
 */
class SectionNotOnEstimate extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A ticked section is no longer on this estimate. The list has been refreshed: check it and generate again.');
    }
}
