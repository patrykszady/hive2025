<?php

namespace App\Traits;

use Carbon\Carbon;

//For Gantt Charts (GanttIndex)
trait CalculatesDependencyPaths
{
    private $verticalOffset = 8;
    private $arrowSize = 1;

    public function calculateDependencyLines()
    {
        $lines = [];
        $blockingDependencies = $this->collectBlockingDependencies();

        foreach ($this->projectDependencies as $dependency) {
            $predecessor = $dependency->predecessor;
            $successor = $dependency->successor;

            $predecessorCoords = $this->findTaskCoordinates($predecessor->id);
            $successorCoords = $this->findTaskCoordinates($successor->id);

            if (!$predecessorCoords || !$successorCoords) {
                continue;
            }

            $pathContext = $this->createPathContext($dependency, $predecessorCoords, $successorCoords, $blockingDependencies);
            $lineData = $this->calculatePath($pathContext);

            $lines[] = $lineData;
        }

        return $lines;
    }

    // Also fix the truncated version if it exists - update collectBlockingDependencies to handle same start dates properly
    private function collectBlockingDependencies(): array
    {
        $blockingDependencies = [];

        foreach ($this->projectDependencies as $dependency) {
            if (!$dependency->isBlocking()) {
                continue;
            }

            $successorId = $dependency->successor->id;
            $predecessorCoords = $this->findTaskCoordinates($dependency->predecessor->id);
            $successorCoords = $this->findTaskCoordinates($dependency->successor->id);

            if (!$predecessorCoords || !$successorCoords) {
                continue;
            }

            $calculatedX = $this->calculateBlockingX($dependency, $predecessorCoords, $successorCoords);

            if (!isset($blockingDependencies[$successorId])) {
                $blockingDependencies[$successorId] = [];
            }

            $blockingDependencies[$successorId][] = [
                'predecessorId' => $dependency->predecessor->id,
                'verticalX' => $calculatedX
            ];
        }

        return $blockingDependencies;
    }

    private function calculateBlockingX($dependency, $predecessorCoords, $successorCoords): float
    {
        $predecessorStartDate = Carbon::parse($dependency->predecessor->start_date);
        $successorStartDate = Carbon::parse($dependency->successor->start_date);
        $sameStartDate = $predecessorStartDate->isSameDay($successorStartDate);

        if ($sameStartDate) {
            return $predecessorCoords['startX'] - 15;
        }

        $tasksOverlap = $predecessorCoords['x'] > $successorCoords['startX'];

        return $tasksOverlap
            ? $predecessorCoords['startX'] + 15
            : $predecessorCoords['centerX'];
    }

    private function createPathContext($dependency, $predecessorCoords, $successorCoords, $blockingDependencies): array
    {
        $predecessorStartDate = Carbon::parse($dependency->predecessor->start_date);
        $successorStartDate = Carbon::parse($dependency->successor->start_date);

        return [
            'dependency' => $dependency,
            'predecessorCoords' => $predecessorCoords,
            'successorCoords' => $successorCoords,
            'sameStartDate' => $predecessorStartDate->isSameDay($successorStartDate),
            'tasksOverlap' => $predecessorCoords['x'] > $successorCoords['startX'],
            'successorIsAbove' => $this->isSuccessorAbove($predecessorCoords, $successorCoords),
            'sameRow' => $this->isSameRow($predecessorCoords, $successorCoords),
            'isTruncated' => !$dependency->isBlocking() && isset($blockingDependencies[$dependency->successor->id]),
            'blockingDependencies' => $blockingDependencies[$dependency->successor->id] ?? [],
            'finalToX' => $successorCoords['startX'] - $this->arrowSize,
        ];
    }

    private function isSuccessorAbove($predecessorCoords, $successorCoords): bool
    {
        return $successorCoords['projectIndex'] < $predecessorCoords['projectIndex'] ||
               ($successorCoords['projectIndex'] === $predecessorCoords['projectIndex'] &&
                $successorCoords['rowIndex'] < $predecessorCoords['rowIndex']);
    }

    private function isSameRow($predecessorCoords, $successorCoords): bool
    {
        return $predecessorCoords['projectIndex'] === $successorCoords['projectIndex'] &&
               $predecessorCoords['rowIndex'] === $successorCoords['rowIndex'];
    }

    private function calculatePath(array $context): array
    {
        if ($context['sameRow']) {
            return $this->calculateSameRowPath($context);
        }

        // NEW: Check for reverse dependency (successor starts before predecessor)
        $predecessorStartDate = Carbon::parse($context['dependency']->predecessor->start_date);
        $successorStartDate = Carbon::parse($context['dependency']->successor->start_date);
        $isReverseDependency = $successorStartDate->isBefore($predecessorStartDate);

        if ($isReverseDependency && $context['tasksOverlap']) {
            return $this->createReverseDependencyPath($context);
        }

        if ($context['dependency']->isBlocking()) {
            return $this->calculateBlockingPath($context);
        }

        return $this->calculateNonBlockingPath($context);
    }

    private function calculateSameRowPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];

        $gap = $successorCoords['startX'] - $predecessorCoords['x'];

        if ($gap < 20) {
            return $this->createComplexSameRowPath($context);
        }

        return $this->createSimpleSameRowPath($context);
    }

    private function createComplexSameRowPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];

        $fromX = $predecessorCoords['centerX'];
        $fromY = $predecessorCoords['bottomY'];
        $toX = $successorCoords['startX'];
        $toY = $successorCoords['y'];

        $horizontalDistance = 30;
        $verticalOffset = 20;
        $midY = $fromY + $verticalOffset;

        $pathData = "M {$fromX},{$fromY} L {$fromX},{$midY} L " . ($toX - $horizontalDistance) . ",{$midY} L " . ($toX - $horizontalDistance) . ",{$toY} L " . ($toX - $this->arrowSize) . ",{$toY}";

        return $this->createLineData($context['dependency'], $pathData);
    }

    private function createSimpleSameRowPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];

        $fromX = $predecessorCoords['x'];
        $fromY = $predecessorCoords['y'];
        $toX = $successorCoords['startX'] - $this->arrowSize;
        $toY = $successorCoords['y'];

        $pathData = "M {$fromX},{$fromY} L {$toX},{$toY}";

        return $this->createLineData($context['dependency'], $pathData);
    }

    private function calculateBlockingPath(array $context): array
    {
        // Use the same logic as other blocking path methods to determine starting position
        if ($context['sameStartDate']) {
            return $this->createSameStartDateBlockingPath($context);
        }

        if ($context['tasksOverlap']) {
            return $this->createOverlappingBlockingPath($context);
        }

        return $this->createNonOverlappingBlockingPath($context);
    }

    private function createSameStartDateBlockingPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];
        $successorIsAbove = $context['successorIsAbove'];

        // Start from the proper edge position (middle of the edge)
        $startX = $predecessorCoords['startX'];
        $offsetX = $predecessorCoords['startX'] - 15;

        // FIX: Use the middle of the left edge
        $startY = $predecessorCoords['y']; // This is already the middle Y of the task

        // Create clean L-shape: horizontal from edge, then vertical to successor
        $pathData = "M {$startX},{$startY} L {$offsetX},{$startY} L {$offsetX},{$successorCoords['y']} L {$context['finalToX']},{$successorCoords['y']}";

        return $this->createLineData($context['dependency'], $pathData);
    }

    private function createOverlappingBlockingPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];
        $successorIsAbove = $context['successorIsAbove'];

        $fromX = $predecessorCoords['startX'] + 15;

        // FIX: Use smaller offset for overlapping blocking paths
        $smallOffset = 4; // Smaller offset for cleaner look
        $offsetY = $successorIsAbove
            ? $predecessorCoords['topY'] - $smallOffset
            : $predecessorCoords['bottomY'] + $smallOffset;

        // Start from the smaller offset position
        $pathData = "M {$fromX},{$offsetY} L {$fromX},{$successorCoords['y']} L {$context['finalToX']},{$successorCoords['y']}";

        return $this->createLineData($context['dependency'], $pathData);
    }

    private function createNonOverlappingBlockingPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];
        $successorIsAbove = $context['successorIsAbove'];

        // For non-overlapping blocking paths, start from left edge with offset
        $fromX = $predecessorCoords['startX'] + 15; // Start from left edge with small offset

        // FIX: Use the same small offset as overlapping paths for consistency
        $smallOffset = 4; // Same as overlapping blocking paths
        $offsetY = $successorIsAbove
            ? $predecessorCoords['topY'] - $smallOffset
            : $predecessorCoords['bottomY'] + $smallOffset;

        // Start from the left offset position
        $pathData = "M {$fromX},{$offsetY} L {$fromX},{$successorCoords['y']} L {$context['finalToX']},{$successorCoords['y']}";

        return $this->createLineData($context['dependency'], $pathData);
    }

    private function calculateNonBlockingPath(array $context): array
    {
        if ($context['sameStartDate']) {
            return $this->createSameStartDateNonBlockingPath($context);
        }

        if ($context['tasksOverlap']) {
            return $this->createOverlappingNonBlockingPath($context);
        }

        return $this->createNonOverlappingNonBlockingPath($context);
    }

    private function createSameStartDateNonBlockingPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];

        $fromX = $predecessorCoords['startX'];
        $fromY = $predecessorCoords['y'];
        $toY = $successorCoords['y'];

        // Check for truncation
        $isTruncated = $context['isTruncated'];
        $blockingVerticalX = null;

        if ($isTruncated && !empty($context['blockingDependencies'])) {
            // FIX: For same start dates, the blocking line is at startX - 15
            foreach ($context['blockingDependencies'] as $block) {
                if ($block['verticalX'] < $fromX && ($blockingVerticalX === null || $block['verticalX'] > $blockingVerticalX)) {
                    $blockingVerticalX = $block['verticalX'];
                }
            }

            if ($blockingVerticalX !== null) {
                // FIX: Truncate at the blocking vertical line position
                $pathData = "M {$fromX},{$fromY} L {$fromX},{$toY} L {$blockingVerticalX},{$toY}";
                return $this->createLineData($context['dependency'], $pathData, true);
            }
        }

        $pathData = "M {$fromX},{$fromY} L {$fromX},{$toY} L {$context['finalToX']},{$toY}";
        return $this->createLineData($context['dependency'], $pathData, $isTruncated);
    }

    private function createOverlappingNonBlockingPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];
        $successorIsAbove = $context['successorIsAbove'];

        $fromX = $predecessorCoords['startX'] + 15;

        // FIX: Use smaller offset for overlapping non-blocking paths
        $smallOffset = 3; // Much smaller offset for cleaner look
        $offsetY = $successorIsAbove
            ? $predecessorCoords['topY'] - $smallOffset
            : $predecessorCoords['bottomY'] + $smallOffset;

        // Check for truncation
        $isTruncated = $context['isTruncated'];
        $blockingVerticalX = null;

        if ($isTruncated && !empty($context['blockingDependencies'])) {
            $grayLineStartX = $predecessorCoords['centerX'];
            $grayLineEndX = $successorCoords['startX'];
            foreach ($context['blockingDependencies'] as $block) {
                if ($block['verticalX'] > $grayLineStartX && ($blockingVerticalX === null || $block['verticalX'] < $blockingVerticalX)) {
                    $blockingVerticalX = $block['verticalX'];
                }
            }

            if ($blockingVerticalX !== null && $blockingVerticalX > $grayLineStartX && $blockingVerticalX <= $grayLineEndX) {
                $intersectionY = $successorCoords['y'];
                // FIX: Start from smaller offset position
                $pathData = "M {$fromX},{$offsetY} L {$fromX},{$intersectionY} L {$blockingVerticalX},{$intersectionY}";
                return $this->createLineData($context['dependency'], $pathData, true);
            }
        }

        // FIX: Start from the smaller offset position
        $pathData = "M {$fromX},{$offsetY} L {$fromX},{$successorCoords['y']} L {$context['finalToX']},{$successorCoords['y']}";
        return $this->createLineData($context['dependency'], $pathData, $isTruncated);
    }

    private function createNonOverlappingNonBlockingPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];
        $successorIsAbove = $context['successorIsAbove'];

        $fromX = $predecessorCoords['centerX'];

        // FIX: Use smaller offset for consistency
        $smallOffset = 3; // Same as overlapping non-blocking paths
        $offsetY = $successorIsAbove
            ? $predecessorCoords['topY'] - $smallOffset
            : $predecessorCoords['bottomY'] + $smallOffset;

        // Check for truncation
        $isTruncated = $context['isTruncated'];
        $blockingVerticalX = null;

        if ($isTruncated && !empty($context['blockingDependencies'])) {
            $grayLineStartX = $fromX;
            $grayLineEndX = $successorCoords['startX'];
            foreach ($context['blockingDependencies'] as $block) {
                if ($block['verticalX'] > $grayLineStartX && ($blockingVerticalX === null || $block['verticalX'] < $blockingVerticalX)) {
                    $blockingVerticalX = $block['verticalX'];
                }
            }

            if ($blockingVerticalX !== null && $blockingVerticalX > $grayLineStartX && $blockingVerticalX <= $grayLineEndX) {
                $intersectionY = $successorCoords['y'];
                $pathData = "M {$fromX},{$offsetY} L {$fromX},{$intersectionY} L {$blockingVerticalX},{$intersectionY}";
                return $this->createLineData($context['dependency'], $pathData, true);
            }
        }

        // Start from the smaller offset position
        $pathData = "M {$fromX},{$offsetY} L {$fromX},{$successorCoords['y']} L {$context['finalToX']},{$successorCoords['y']}";
        return $this->createLineData($context['dependency'], $pathData, $isTruncated);
    }

    // Add a new method to handle reverse dependency (successor starts before predecessor)
    private function createReverseDependencyPath(array $context): array
    {
        $predecessorCoords = $context['predecessorCoords'];
        $successorCoords = $context['successorCoords'];

        // Start from offset below the predecessor task
        $fromX = $predecessorCoords['startX'] + 15;
        $fromY = $predecessorCoords['bottomY'] + $this->verticalOffset; // FIX: Add offset
        $horizontalOffset = 30; // Go further left
        $verticalOffset = 20;   // Go further down from the offset
        $leftX = $successorCoords['startX'] - $horizontalOffset;
        $downY = $fromY + $verticalOffset;
        $pathData = "M {$fromX},{$fromY} L {$fromX},{$downY} L {$leftX},{$downY} L {$leftX},{$successorCoords['y']} L {$context['finalToX']},{$successorCoords['y']}";
        return $this->createLineData($context['dependency'], $pathData);
    }

    private function createLineData($dependency, string $pathData, bool $isTruncated = false): array
    {
        $lineData = [
            'id' => $dependency->id,
            'pathData' => $pathData,
            'isBlocking' => $dependency->isBlocking(),
            'predecessorId' => $dependency->predecessor->id,
            'successorId' => $dependency->successor->id,
            'showArrow' => !$isTruncated,
        ];

        // Always provide complete path data for JavaScript highlighting
        if ($isTruncated) {
            $lineData['isTruncated'] = true;
            $lineData['truncatedPath'] = $pathData; // Current truncated path
            $lineData['completePath'] = $this->calculateCompletePath($dependency); // Full path to target
            $lineData['completeMarker'] = $dependency->isBlocking() ? 'url(#arrowhead-blocking)' : 'url(#arrowhead)'; // Add missing completeMarker
        } else {
            // For non-truncated paths, the complete path is the same as the current path
            $lineData['completePath'] = $pathData;
        }

        return $lineData;
    }

    // Add this new method to calculate the complete path
    private function calculateCompletePath($dependency): string
    {
        $predecessorCoords = $this->findTaskCoordinates($dependency->predecessor->id);
        $successorCoords = $this->findTaskCoordinates($dependency->successor->id);

        if (!$predecessorCoords || !$successorCoords) {
            return '';
        }

        // Create a context without truncation to get the full path
        $context = $this->createPathContext($dependency, $predecessorCoords, $successorCoords, []);
        $context['isTruncated'] = false; // Force no truncation
        $context['blockingDependencies'] = []; // Remove blocking dependencies

        // Calculate the complete path based on the dependency type
        if ($context['sameRow']) {
            $result = $this->calculateSameRowPath($context);
        } else {
            $predecessorStartDate = Carbon::parse($dependency->predecessor->start_date);
            $successorStartDate = Carbon::parse($dependency->successor->start_date);
            $isReverseDependency = $successorStartDate->isBefore($predecessorStartDate);

            if ($isReverseDependency && $context['tasksOverlap']) {
                $result = $this->createReverseDependencyPath($context);
            } else if ($dependency->isBlocking()) {
                $result = $this->calculateBlockingPath($context);
            } else {
                $result = $this->calculateNonBlockingPath($context);
            }
        }

        return $result['pathData'];
    }
}
