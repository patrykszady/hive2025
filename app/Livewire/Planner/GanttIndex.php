<?php

namespace App\Livewire\Planner;

use App\Models\Vendor;
use App\Models\User;
use App\Models\Task;
use App\Models\Project;
use App\Models\TaskDependency;
use Flux;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class GanttIndex extends Component
{
    public $vendors = [];
    public $employees = [];

    #[Url]
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        $this->vendors = Vendor::topExpenseVendors()->get();
        $this->employees = auth()->user()->vendor->users()->employed()->get();
    }

    #[Computed]
    public function days()
    {
        $startDate = $this->week ? Carbon::parse($this->week)->startOfWeek() : Carbon::now()->startOfWeek()->subWeeks(2);
        $endDate = $startDate->copy()->addDays(20);

        return collect(CarbonPeriod::create($startDate, '1 day', $endDate));
    }

    #[Computed]
    public function projects()
    {
        $startDate = $this->days->first();
        $endDate = $this->days->last();

        return Project::with(['tasks' => function ($query) use ($startDate, $endDate) {
            $query->where('start_date', '<=', $endDate->format('Y-m-d'))
                ->where('end_date', '>=', $startDate->format('Y-m-d'))
                ->with(['predecessorDependencies', 'successorDependencies'])
                ->orderBy('start_date');
        }])
        ->where(function ($query) use ($startDate, $endDate) {
            $query->status(['Active'])
                  ->orWhereHas('tasks', function ($taskQuery) use ($startDate, $endDate) {
                      $taskQuery->where('start_date', '<=', $endDate->format('Y-m-d'))
                               ->where('end_date', '>=', $startDate->format('Y-m-d'));
                  });
        })
        ->get();
    }

    #[Computed]
    public function projectsData()
    {
        return $this->projects->map(function ($project) {
            $projectTasks = $project->tasks;

            $unscheduledTasks = Task::where('project_id', $project->id)
                ->where(function ($query) {
                    $query->whereNull('start_date')
                        ->orWhereNull('end_date');
                })
                ->get();

            $allRenderedTasks = $projectTasks->map(function ($task) {
                return $this->calculateTaskData($task);
            })->filter()->values();

            $groupedTasks = $allRenderedTasks->groupBy(function ($taskData) {
                $task = $taskData['task'];
                return $task->parent_task_id ?? $task->id;
            });

            $taskRows = [];
            foreach ($groupedTasks as $familyId => $familyTasks) {
                $sortedFamily = $familyTasks->sortBy(function ($taskData) {
                    return $taskData['task']->created_at;
                })->values()->toArray();

                $taskRows[] = $sortedFamily;
            }

            $rowCount = count($taskRows);
            $taskBarHeight = 60;
            $taskBarMarginY = 4;

            $projectTimelineHeight = ($rowCount * ($taskBarHeight + ($taskBarMarginY * 2))) + $taskBarMarginY;
            $projectTimelineHeight = max($projectTimelineHeight, 80);

            return [
                'project' => $project,
                'renderedTasks' => $allRenderedTasks,
                'taskRows' => $taskRows,
                'unscheduledTasks' => $unscheduledTasks,
                'projectTimelineHeight' => $projectTimelineHeight
            ];
        });
    }

    #[Computed]
    public function projectDependencies()
    {
        $projectIds = $this->projects->pluck('id')->toArray();

        return TaskDependency::whereHas('predecessor', function ($query) use ($projectIds) {
            $query->whereIn('project_id', $projectIds);
        })
        ->whereHas('successor', function ($query) use ($projectIds) {
            $query->whereIn('project_id', $projectIds);
        })
        ->with(['predecessor', 'successor'])
        ->get();
    }

    #[Computed]
    public function dependencyLines()
    {
        $lines = [];
        $blockingDependencies = []; // Track blocking dependencies by successor task

        // First pass: collect all blocking dependencies with their X positions
        foreach ($this->projectDependencies as $dependency) {
            if ($dependency->isBlocking()) {
                $successorId = $dependency->successor->id;
                $predecessorCoords = $this->findTaskCoordinates($dependency->predecessor->id);
                $successorCoords = $this->findTaskCoordinates($dependency->successor->id);

                if ($predecessorCoords && $successorCoords) {
                    // Check if this is a same-start-date dependency
                    $predecessorStartDate = Carbon::parse($dependency->predecessor->start_date);
                    $successorStartDate = Carbon::parse($dependency->successor->start_date);
                    $sameStartDate = $predecessorStartDate->isSameDay($successorStartDate);

                    // Check for overlapping tasks (same logic as main dependency calculation)
                    $predecessorLeft = $predecessorCoords['startX'];
                    $predecessorRight = $predecessorCoords['x'];
                    $successorLeft = $successorCoords['startX'];
                    $successorRight = $successorCoords['x'];
                    $tasksOverlap = $predecessorRight > $successorLeft;

                    // Use the EXACT same logic as the main dependency calculation
                    if ($sameStartDate) {
                        // Same start date: blocking line goes left first, so use startX - 15
                        $calculatedX = $predecessorCoords['startX'] - 15;
                    } else {
                        // Different start dates: check for overlap
                        if ($tasksOverlap) {
                            // Overlapping tasks: use startX + 15
                            $calculatedX = $predecessorCoords['startX'] + 15;
                        } else {
                            // Non-overlapping tasks: use center X for bottom-center start
                            $calculatedX = $predecessorCoords['centerX'];
                        }
                    }

                    if (!isset($blockingDependencies[$successorId])) {
                        $blockingDependencies[$successorId] = [];
                    }
                    $blockingDependencies[$successorId][] = [
                        'predecessorId' => $dependency->predecessor->id,
                        'verticalX' => $calculatedX
                    ];
                }
            }
        }

        foreach ($this->projectDependencies as $dependency) {
            $predecessor = $dependency->predecessor;
            $successor = $dependency->successor;

            $predecessorCoords = $this->findTaskCoordinates($predecessor->id);
            $successorCoords = $this->findTaskCoordinates($successor->id);

            if ($predecessorCoords && $successorCoords) {
                $arrowSize = 1;

                // Check if tasks are on same row AND same project
                $sameRow = ($predecessorCoords['projectIndex'] === $successorCoords['projectIndex'] &&
                       $predecessorCoords['rowIndex'] === $successorCoords['rowIndex']);

                // Check if this is a truncated non-blocking dependency
                $isTruncated = !$dependency->isBlocking() && isset($blockingDependencies[$successor->id]);

                if ($sameRow) {
                    // Same row logic
                    $predecessorRight = $predecessorCoords['x']; // Right edge of predecessor
                    $successorLeft = $successorCoords['startX']; // Left edge of successor
                    $gap = $successorLeft - $predecessorRight;

                    if ($gap < 20) { // Tasks are too close or overlapping
                        $fromX = $predecessorCoords['centerX']; // Center of predecessor
                        $fromY = $predecessorCoords['bottomY']; // Bottom of predecessor
                        $toX = $successorCoords['startX']; // Left edge of successor
                        $toY = $successorCoords['y']; // Middle of successor

                        $horizontalDistance = 30; // More space for overlapping tasks
                        $verticalOffset = 20; // Go down a bit more
                        $midY = $fromY + $verticalOffset;

                        $pathData = "M {$fromX},{$fromY} L {$fromX},{$midY} L " . ($toX - $horizontalDistance) . ",{$midY} L " . ($toX - $horizontalDistance) . ",{$toY} L " . ($toX - $arrowSize) . ",{$toY}";
                    } else {
                        $fromX = $predecessorCoords['x']; // Right edge
                        $fromY = $predecessorCoords['y']; // Middle of predecessor
                        $toX = $successorCoords['startX']; // Left edge
                        $toY = $successorCoords['y']; // Middle of successor

                        $pathData = "M {$fromX},{$fromY} L " . ($toX - $arrowSize) . ",{$toY}";
                    }
                } else {
                    // Different rows - check for horizontal overlap
                    $predecessorLeft = $predecessorCoords['startX'];
                    $predecessorRight = $predecessorCoords['x'];
                    $successorLeft = $successorCoords['startX'];
                    $successorRight = $successorCoords['x'];

                    $tasksOverlap = $predecessorRight > $successorLeft;

                    // Check if tasks start on the same actual date
                    $predecessorStartDate = Carbon::parse($predecessor->start_date);
                    $successorStartDate = Carbon::parse($successor->start_date);
                    $sameStartDate = $predecessorStartDate->isSameDay($successorStartDate);

                    // Add vertical offset so lines don't overlap with task cards
                    $verticalOffset = 8;

                    // Determine final X position based on blocking dependencies
                    $finalToX = $successorCoords['startX'] - $arrowSize;

                    // Initialize path-related variables
                    $pathData = '';
                    $truncatedPath = null;
                    $completePath = null;

                    // For blocking dependencies, check if there's another blocking dependency intersection
                    if ($dependency->isBlocking()) {
                        // Check if there's ANOTHER blocking dependency that would intersect
                        $hasIntersection = false;
                        $intersectionX = null;

                        foreach ($this->projectDependencies as $otherDep) {
                            // Check for intersection with OTHER blocking dependencies to same successor
                            if ($otherDep->isBlocking() &&
                                $otherDep->successor->id === $successor->id &&
                                $otherDep->id !== $dependency->id) { // Don't compare with self

                                $otherPredCoords = $this->findTaskCoordinates($otherDep->predecessor->id);
                                if ($otherPredCoords) {
                                    $thisLineStart = $predecessorCoords['centerX'];
                                    $thisLineEnd = $successorCoords['startX'];
                                    $otherBlockingLineX = $otherPredCoords['centerX'];

                                    // If the other blocking line's vertical descent intersects this line's path
                                    if ($otherBlockingLineX > $thisLineStart && $otherBlockingLineX <= $thisLineEnd) {
                                        $hasIntersection = true;
                                        $intersectionX = $otherBlockingLineX;
                                        break;
                                    }
                                }
                            }
                        }

                        if ($hasIntersection) {
                            // This blocking line should be truncated at the intersection
                            $intersectionY = $successorCoords['y'];

                            // Create truncated horizontal line that stops at intersection
                            if ($sameStartDate) {
                                // Same start date: use the same L-shape logic but truncated
                                $fromX = $predecessorCoords['startX'] - 15;
                                $fromY = $predecessorCoords['bottomY'] - 10; // Use bottom area consistently
                                // Fix: Start from bottom center, not left edge
                                $pathData = "M {$predecessorCoords['centerX']},{$predecessorCoords['bottomY']} L {$predecessorCoords['centerX']},{$fromY} L {$fromX},{$fromY} L {$fromX},{$intersectionY} L {$intersectionX},{$intersectionY}";
                            } else {
                                if ($tasksOverlap) {
                                    $fromX = $predecessorCoords['startX'] + 15;
                                    $fromY = $predecessorCoords['bottomY'] + $verticalOffset;
                                    $clearanceX = $predecessorCoords['startX'] + 15;
                                    $pathData = "M {$fromX},{$fromY} L {$clearanceX},{$fromY} L {$clearanceX},{$intersectionY} L {$intersectionX},{$intersectionY}";
                                } else {
                                    // This is correct - starts from bottom center
                                    $fromX = $predecessorCoords['centerX']; // Bottom center X
                                    $fromY = $predecessorCoords['bottomY'] + $verticalOffset; // Bottom Y + offset
                                    $pathData = "M {$fromX},{$fromY} L {$fromX},{$intersectionY} L {$intersectionX},{$intersectionY}";
                                }
                            }

                            // This line should be truncated (no arrow) since it stops at intersection
                            $isTruncated = true;
                        } else {
                            // Normal blocking line path (full L-shape when no intersection)
                            if ($sameStartDate) {
                                // Same start date: go left first, then down, then right
                                // Start from bottom CENTER of task, not left edge
                                $fromX = $predecessorCoords['startX'] - 15; // 15px left of the task for clearance
                                $fromY = $predecessorCoords['bottomY'] - 10; // Start near bottom of task (10px from bottom)
                                $toY = $successorCoords['y']; // Middle of successor

                                // Start from bottom CENTER, go left, then down, then right
                                $pathData = "M {$predecessorCoords['centerX']},{$predecessorCoords['bottomY']} L {$predecessorCoords['centerX']},{$fromY} L {$fromX},{$fromY} L {$fromX},{$toY} L {$finalToX},{$toY}";
                            } else {
                                // Different start dates: use existing L-shape logic
                                $toY = $successorCoords['y'];

                                if ($tasksOverlap) {
                                    $fromX = $predecessorCoords['startX'] + 15;
                                    $fromY = $predecessorCoords['bottomY'] + $verticalOffset;
                                    $clearanceX = $predecessorCoords['startX'] + 15;
                                    $pathData = "M {$fromX},{$fromY} L {$clearanceX},{$fromY} L {$clearanceX},{$toY} L {$finalToX},{$toY}";
                                } else {
                                    $fromX = $predecessorCoords['centerX'];
                                    $fromY = $predecessorCoords['bottomY'] + $verticalOffset;
                                    $pathData = "M {$fromX},{$fromY} L {$fromX},{$toY} L {$finalToX},{$toY}";
                                }
                            }
                        }
                    } else {
                        // Non-blocking dependency logic
                        $grayLineStartX = $predecessorCoords['centerX'];
                        $grayLineEndX = $successorCoords['startX'];

                        if ($isTruncated) {
                            // Find the leftmost blocking dependency's vertical descent point that is to the right of our start
                            $blockingVerticalX = null;
                            foreach ($blockingDependencies[$successor->id] as $block) {
                                if ($block['verticalX'] > $grayLineStartX && ($blockingVerticalX === null || $block['verticalX'] < $blockingVerticalX)) {
                                    $blockingVerticalX = $block['verticalX'];
                                }
                            }

                            if ($blockingVerticalX !== null && $blockingVerticalX > $grayLineStartX && $blockingVerticalX <= $grayLineEndX) {
                                $isTruncated = true;
                            } else {
                                $isTruncated = false;
                                $finalToX = $successorCoords['startX'] - $arrowSize;
                            }
                        } else {
                            $finalToX = $successorCoords['startX'] - $arrowSize;
                        }

                        if ($sameStartDate) {
                            // Same start date: horizontal line from left edge to left edge
                            $fromX = $predecessorCoords['startX'];
                            $fromY = $predecessorCoords['y']; // Middle of predecessor
                            $toY = $successorCoords['y']; // Middle of successor

                            if ($isTruncated && isset($blockingVerticalX) && $blockingVerticalX > $fromX && $blockingVerticalX <= $grayLineEndX) {
                                // Truncated path - stop at intersection
                                $truncatedPath = "M {$fromX},{$fromY} L {$fromX},{$toY} L {$blockingVerticalX},{$toY}";
                                $completePath = "M {$fromX},{$fromY} L {$fromX},{$toY} L " . ($successorCoords['startX'] - $arrowSize) . ",{$toY}";

                                // Use truncated path as the default pathData
                                $pathData = $truncatedPath;
                            } else {
                                // Full path
                                $pathData = "M {$fromX},{$fromY} L {$fromX},{$toY} L {$finalToX},{$toY}";
                            }
                        } else {
                            // Different start dates: use existing logic
                            if ($tasksOverlap) {
                                $fromX = $predecessorCoords['startX'] + 15;
                                $fromY = $predecessorCoords['bottomY'] + $verticalOffset;
                                $clearanceX = $predecessorCoords['startX'] + 15;

                                if ($isTruncated && isset($blockingVerticalX) && $blockingVerticalX > $grayLineStartX && $blockingVerticalX <= $grayLineEndX) {
                                    // Truncated path - stop at intersection
                                    $intersectionY = $successorCoords['y'];
                                    $truncatedPath = "M {$fromX},{$fromY} L {$clearanceX},{$fromY} L {$clearanceX},{$intersectionY} L {$blockingVerticalX},{$intersectionY}";
                                    $completePath = "M {$fromX},{$fromY} L {$clearanceX},{$fromY} L {$clearanceX},{$intersectionY} L " . ($successorCoords['startX'] - $arrowSize) . ",{$intersectionY}";

                                    // Use truncated path as the default pathData
                                    $pathData = $truncatedPath;
                                } else {
                                    // Full path - ONLY when NOT truncated
                                    $toY = $successorCoords['y'];
                                    $pathData = "M {$fromX},{$fromY} L {$clearanceX},{$fromY} L {$clearanceX},{$toY} L {$finalToX},{$toY}";
                                }
                            } else {
                                $fromX = $predecessorCoords['centerX'];  // Bottom center X
                                $fromY = $predecessorCoords['bottomY'] + $verticalOffset;  // Bottom Y + offset

                                if ($isTruncated && isset($blockingVerticalX) && $blockingVerticalX > $grayLineStartX && $blockingVerticalX <= $grayLineEndX) {
                                    // DEBUG: Find where the blocking dependency actually descends
                                    $actualBlockingDescentX = null;

                                    // Find the blocking dependency and get its actual descent point
                                    foreach ($this->projectDependencies as $blockingDep) {
                                        if ($blockingDep->isBlocking() &&
                                            $blockingDep->successor->id === $successor->id) {

                                            $blockingPredCoords = $this->findTaskCoordinates($blockingDep->predecessor->id);
                                            if ($blockingPredCoords) {
                                                // Check if blocking dependency uses same-start-date logic
                                                $blockingPredStartDate = Carbon::parse($blockingDep->predecessor->start_date);
                                                $blockingSuccStartDate = Carbon::parse($blockingDep->successor->start_date);
                                                $blockingSameStartDate = $blockingPredStartDate->isSameDay($blockingSuccStartDate);

                                                // Check for overlapping tasks (SAME LOGIC AS FIRST PASS)
                                                $blockingPredLeft = $blockingPredCoords['startX'];
                                                $blockingPredRight = $blockingPredCoords['x'];
                                                $blockingSuccLeft = $successorCoords['startX'];
                                                $blockingSuccRight = $successorCoords['x'];
                                                $blockingTasksOverlap = $blockingPredRight > $blockingSuccLeft;

                                                // Use the EXACT same logic as the first pass collection
                                                if ($blockingSameStartDate) {
                                                    // Same start date: blocking line goes left first, so use startX - 15
                                                    $actualBlockingDescentX = $blockingPredCoords['startX'] - 15;
                                                } else {
                                                    // Different start dates: check for overlap
                                                    if ($blockingTasksOverlap) {
                                                        // Overlapping tasks: use startX + 15
                                                        $actualBlockingDescentX = $blockingPredCoords['startX'] + 15;
                                                    } else {
                                                        // Non-overlapping tasks: use center X for bottom-center start
                                                        $actualBlockingDescentX = $blockingPredCoords['centerX'];
                                                    }
                                                }

                                                break;
                                            }
                                        }
                                    }

                                    $intersectionX = $actualBlockingDescentX ?? $blockingVerticalX;

                                    // Truncated path - stop at actual intersection
                                    $intersectionY = $successorCoords['y'];
                                    $truncatedPath = "M {$fromX},{$fromY} L {$fromX},{$intersectionY} L {$intersectionX},{$intersectionY}";
                                    $completePath = "M {$fromX},{$fromY} L {$fromX},{$intersectionY} L " . ($successorCoords['startX'] - $arrowSize) . ",{$intersectionY}";
                                    $pathData = $truncatedPath;
                                } else {
                                    // Full path - ONLY when NOT truncated
                                    $toY = $successorCoords['y'];
                                    $pathData = "M {$fromX},{$fromY} L {$fromX},{$toY} L {$finalToX},{$toY}";
                                }
                            }
                        }
                    }
                }

                // Add the line
                $lineData = [
                    'id' => $dependency->id,
                    'pathData' => $pathData, // This is already the truncated path when needed
                    'isBlocking' => $dependency->isBlocking(),
                    'predecessorId' => $predecessor->id,
                    'successorId' => $successor->id,
                    'showArrow' => !$isTruncated, // Only show arrow if not truncated
                ];

                // Add truncation data if applicable
                if ($isTruncated && isset($truncatedPath) && isset($completePath)) {
                    $lineData['isTruncated'] = true;
                    $lineData['truncatedPath'] = $pathData; // Use the SAME path as pathData (since pathData IS the truncated path)
                    $lineData['completePath'] = $completePath; // But completePath is the full path
                    $lineData['completeMarker'] = $dependency->isBlocking() ? 'url(#arrowhead-blocking)' : 'url(#arrowhead)';
                }

                $lines[] = $lineData;
            }
        }

        return $lines;
    }

    private function findTaskCoordinates($taskId)
    {
        foreach ($this->projectsData as $projectIndex => $projectData) {
            foreach ($projectData['taskRows'] as $rowIndex => $taskRow) {
                foreach ($taskRow as $taskData) {
                    if ($taskData['task']->id === $taskId) {
                        $projectHeaderHeight = 76;
                        $taskBarHeight = 60;
                        $taskBarMarginY = 4;

                        $y = 49; // Date header height
                        for ($i = 0; $i < $projectIndex; $i++) {
                            $y += $projectHeaderHeight + $this->projectsData[$i]['projectTimelineHeight'];
                        }
                        $y += $projectHeaderHeight + ($rowIndex * ($taskBarHeight + $taskBarMarginY * 2)) + $taskBarMarginY;

                        // Use the raw positions without the CSS adjustments
                        $leftPosition = $taskData['leftPosition']; // Remove the +2 offset
                        $barWidth = $taskData['barWidth']; // Remove the -4 adjustment

                        return [
                            'x' => $leftPosition + $barWidth, // Right edge (actual end of task)
                            'centerX' => $leftPosition + ($barWidth / 2), // Center X (actual center)
                            'y' => $y + ($taskBarHeight / 2), // Middle of task for same row
                            'bottomY' => $y + $taskBarHeight, // Bottom edge for different rows
                            'startX' => $leftPosition, // Left edge (actual start of task)
                            'width' => $barWidth,
                            'rowIndex' => $rowIndex,
                            'projectIndex' => $projectIndex,
                        ];
                    }
                }
            }
        }

        return null;
    }

    public function editTask($taskId)
    {
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    public function updateTaskDates($taskId, $startIndex, $endIndex)
    {
        $task = Task::find($taskId);
        if (!$task) return;

        if ($startIndex < 0) {
            $startDate = $this->days->first()->copy()->addDays($startIndex);
        } else {
            $startDate = $this->days[$startIndex];
        }

        if ($endIndex >= count($this->days)) {
            $endDate = $this->days->last()->copy()->addDays($endIndex - (count($this->days) - 1));
        } else {
            $endDate = $this->days[$endIndex];
        }

        if ($task->wouldOverlapWithSiblings($startDate, $endDate)) {
            Flux::toast(
                duration: 4000,
                position: 'top right',
                variant: 'danger',
                heading: 'Cannot Update Task',
                text: 'This would overlap with a sibling task.',
            );
            return;
        }

        $task->update([
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d')
        ]);

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Task Updated',
            text: '',
        );
    }

    private function calculateTaskData($task)
    {
        if (!$task->start_date || !$task->end_date) {
            return null;
        }

        $taskStartDate = Carbon::parse($task->start_date);
        $taskEndDate = Carbon::parse($task->end_date);
        $renderStartDate = $taskStartDate->isBefore($this->days->first()) ? $this->days->first() : $taskStartDate;
        $renderEndDate = $taskEndDate->isAfter($this->days->last()) ? $this->days->last() : $taskEndDate;
        $renderStartDayIndex = $this->days->search(fn($d) => $d->isSameDay($renderStartDate));
        $renderEndDayIndex = $this->days->search(fn($d) => $d->isSameDay($renderEndDate));

        if ($renderStartDayIndex === false || $renderEndDayIndex === false || $renderStartDayIndex > $renderEndDayIndex) {
            return null;
        }

        return [
            'task' => $task,
            'taskStartDate' => $taskStartDate,
            'taskEndDate' => $taskEndDate,
            'renderStartDayIndex' => $renderStartDayIndex,
            'renderEndDayIndex' => $renderEndDayIndex,
            'leftPosition' => $renderStartDayIndex * 100,
            'taskDurationInDays' => $renderEndDayIndex - $renderStartDayIndex + 1,
            'barWidth' => ($renderEndDayIndex - $renderStartDayIndex + 1) * 100,
        ];
    }

    public function getTaskWeekendExclusions($task, $taskStartDate, $taskEndDate, $barWidth)
    {
        $taskPeriod = CarbonPeriod::create($taskStartDate, $taskEndDate);
        $taskDays = iterator_to_array($taskPeriod);

        $visibleTaskDays = [];
        foreach($taskDays as $taskDay) {
            if ($taskDay->between($this->days->first(), $this->days->last())) {
                $isTaskDayWeekend = $taskDay->isWeekend();
                $isTaskDaySaturday = $taskDay->isSaturday();
                $isTaskDaySunday = $taskDay->isSunday();

                $isExcludedWeekend = false;
                if ($isTaskDayWeekend) {
                    if ($isTaskDaySaturday && !($task->options->saturday ?? false)) {
                        $isExcludedWeekend = true;
                    }
                    if ($isTaskDaySunday && !($task->options->sunday ?? false)) {
                        $isExcludedWeekend = true;
                    }
                }

                $visibleTaskDays[] = [
                    'isExcludedWeekend' => $isExcludedWeekend,
                    'segmentWidth' => ($barWidth - 4) / count($taskDays)
                ];
            }
        }

        return $visibleTaskDays;
    }

    public function render()
    {
        $taskDependencies = [];
        foreach ($this->projectDependencies as $dependency) {
            $taskDependencies[$dependency->successor_task_id][] = [
                'predecessorId' => $dependency->predecessor_task_id,
                'type' => $dependency->type,
                'isBlocking' => $dependency->isBlocking(),
            ];
        }

        return view('livewire.planner.gantt', [
            'projectsData' => $this->projectsData,
            'days' => $this->days,
            'employees' => $this->employees,
            'vendors' => $this->vendors,
            'dependencyLines' => $this->dependencyLines,
            'taskDependencies' => $taskDependencies,
        ])->layout('components.layouts.app', [
            'fullscreenClasses' => 'p-0! lg:p-0! relative overflow-auto',
        ]);
    }
}
