<?php

namespace App\Exceptions\Estimates;

use RuntimeException;

/**
 * Thrown from inside a streaming estimate draft when the estimator asked for
 * it to stop: it unwinds the model call (closing the stream) and is caught by
 * App\Services\EstimateAI\EstimateSectionsDrafter, which keeps what was
 * drafted so far.
 */
class DraftStopped extends RuntimeException {}
