<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpcomingTaskNotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['items' => []]);
        }

        $today = browser_today();
        $tomorrow = $today->copy()->addDay();
        $todayStr = $today->format('Y-m-d');
        $tomorrowStr = $tomorrow->format('Y-m-d');
        $dates = [$todayStr, $tomorrowStr];

        $tasks = Task::query()
            ->whereJsonContains('user_ids', (string) $user->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $tomorrowStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->with('project')
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        $items = [];

        foreach ($tasks as $task) {
            $startDate = $task->start_date?->format('Y-m-d');
            $endDate = $task->end_date?->format('Y-m-d');

            if (! $startDate || ! $endDate) {
                continue;
            }

            foreach ($dates as $date) {
                if ($date < $startDate || $date > $endDate) {
                    continue;
                }

                $scheduledAt = task_datetime_in_browser_tz($task, $date)->toIso8601String();
                $timeLabel = task_time_in_browser_tz($task, $date, 'start_time');
                $project = $task->project;

                $items[] = [
                    'key' => "task-{$task->id}-{$date}",
                    'task_id' => $task->id,
                    'title' => $task->title ?? 'Task',
                    'date' => $date,
                    'time' => $timeLabel,
                    'scheduled_at' => $scheduledAt,
                    'project' => $project?->name,
                    'url' => $project ? route('projects.show', $project) : null,
                ];
            }
        }

        return response()->json([
            'items' => $items,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
