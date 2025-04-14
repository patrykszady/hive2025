<?php

namespace App\Traits;

trait MergesValidationRules
{
    /**
     * Prefix an array of rules with a given prefix.
     */
    protected function prefixRules(array $rules, string $prefix = 'form.'): array
    {
        $prefixed = [];
        foreach ($rules as $key => $rule) {
            $prefixed["{$prefix}{$key}"] = $rule;
        }
        return $prefixed;
    }

    /**
     * Merge nested form rules with additional rules.
     *
     * It expects that $formRules are given as an array without prefix.
     * The HandlesChecks trait should provide a mergedRules() method.
     */
    protected function componentMergedRules(array $formRules, array $additionalRules = []): array
    {
        return array_merge(
            // First, prefix the nested form rules and merge with check-related rules from the trait:
            $this->mergedRules($this->prefixRules($formRules)),
            // Then, merge any additional rules specific to the parent component.
            $additionalRules
        );
    }
}
