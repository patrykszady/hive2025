<?php

namespace App\Traits;

trait HasNumericSearch
{
    /**
     * Process numeric search queries and return the appropriate search query and filters
     * 
     * @param string $searchQuery
     * @param array $filterConditions
     * @return array [actualQuery, augmentedFilters]
     */
    protected static function processNumericSearch(string $searchQuery, array $filterConditions = []): array
    {
        $actualQuery = $searchQuery;
        $augmentedFilters = $filterConditions;
        
        if (is_string($searchQuery)) {
            $candidate = trim($searchQuery);
            
            // Complete amounts ending with .00 or .0 - use exact filter for precision
            // Include both positive and negative amounts
            if ($candidate !== '' && preg_match('/^-?\d+\.(00|0)$/', $candidate)) {
                $amountValue = abs((float) $candidate);
                $negativeValue = -$amountValue;
                $augmentedFilters[] = "(amount = {$amountValue} OR amount = {$negativeValue})";
                $actualQuery = '';
            }
            // Negative amounts - use exact filter to preserve sign precision  
            elseif ($candidate !== '' && preg_match('/^-\d+(\.\d+)?$/', $candidate)) {
                $amountValue = (float) $candidate;
                $positiveValue = abs($amountValue);
                $augmentedFilters[] = "(amount = {$amountValue} OR amount = {$positiveValue})";
                $actualQuery = '';
            }
            // Patterns ending with decimal point (like "62.") - use range filter for prefix matching
            // Include both positive and negative ranges
            elseif ($candidate !== '' && preg_match('/^(\d+)\.$/', $candidate, $matches)) {
                $baseNumber = (int) $matches[1];
                $lowerBound = (float) $baseNumber;
                $upperBound = (float) ($baseNumber + 1);
                $augmentedFilters[] = "((amount >= {$lowerBound} AND amount < {$upperBound}) OR (amount > -{$upperBound} AND amount <= -{$lowerBound}))";
                $actualQuery = '';
            }
            // All other patterns (like "69", "62.1") - use text search for substring matching
        }
        
        return [$actualQuery, $augmentedFilters];
    }

    /**
     * Apply search options including filters, sorting, and matching strategy
     * 
     * @param array $options
     * @param string $baseFilter
     * @param array $augmentedFilters
     * @param string $actualQuery
     * @param string $sortBy
     * @param string $sortDirection
     * @return array
     */
    protected static function applySearchOptions(array $options, string $baseFilter, array $augmentedFilters, string $actualQuery, string $sortBy, string $sortDirection): array
    {
        // Add custom filters if any (including exact-amount if applied)
        if (!empty($augmentedFilters)) {
            $filterString = implode(' AND ', $augmentedFilters);
            $options['filter'] = "({$baseFilter}) AND ({$filterString})";
        } else {
            $options['filter'] = $baseFilter;
        }
        
        // Always apply sorting to maintain order
        $options['sort'] = [$sortBy . ':' . $sortDirection];
        
        // Use 'all' matching strategy for exact prefix matching
        if (!empty($actualQuery)) {
            $options['matchingStrategy'] = 'all';
        }
        
        return $options;
    }
}