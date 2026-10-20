<?php

namespace App\Rules;

use App\Support\AppReturnTo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Only the Hive Contractors app's own `hivecontractors://` scheme is
 * accepted for a `return_to` field — see App\Support\AppReturnTo. A plain
 * "https://…" or any other scheme fails validation rather than being
 * silently ignored, so a caller that gets this wrong finds out immediately
 * instead of shipping an open redirect.
 */
class AppReturnToUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! AppReturnTo::isValid($value)) {
            $fail('The :attribute must be a valid Hive Contractors app link.');
        }
    }
}
