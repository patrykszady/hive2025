<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SendTaskPushNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $notificationType = 'today'
    ) {}

    public function handle(): void
    {
        $vapidPublicKey = config('services.vapid.public_key');
        $vapidPrivateKey = config('services.vapid.private_key');

        if (! $vapidPublicKey || ! $vapidPrivateKey) {
            Log::warning('VAPID keys not configured, skipping push notifications');

            return;
        }

        $auth = [
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => $vapidPublicKey,
                'privateKey' => $vapidPrivateKey,
            ],
        ];

        $webPush = new WebPush($auth);

        $subscriptions = PushSubscription::with('user')->get();

        foreach ($subscriptions as $pushSubscription) {
            $user = $pushSubscription->user;

            if (! $user) {
                continue;
            }

            $payload = $this->buildPayloadForUser($user);

            if (! $payload) {
                continue;
            }

            $subscription = Subscription::create([
                'endpoint' => $pushSubscription->endpoint,
                'keys' => [
                    'p256dh' => $pushSubscription->p256dh,
                    'auth' => $pushSubscription->auth,
                ],
            ]);

            $webPush->queueNotification($subscription, json_encode($payload));
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess()) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                // Remove expired subscriptions
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint', $endpoint)->delete();
                }

                Log::debug('Push notification failed', [
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                ]);
            }
        }
    }

    protected function buildPayloadForUser(User $user): ?array
    {
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();

        $targetDate = $this->notificationType === 'tomorrow' ? $tomorrow : $today;
        $dateStr = $targetDate->format('Y-m-d');

        $tasks = Task::query()
            ->whereJsonContains('user_ids', (string) $user->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $targetDate)
            ->whereDate('end_date', '>=', $targetDate)
            ->with('project')
            ->orderBy('start_date')
            ->get();

        $tasksForDate = $tasks->filter(function ($task) use ($dateStr) {
            $startDate = $task->start_date?->format('Y-m-d');
            $endDate = $task->end_date?->format('Y-m-d');

            return $startDate && $endDate && $dateStr >= $startDate && $dateStr <= $endDate;
        });

        // For "update" type, check if tasks changed since last notification
        if ($this->notificationType === 'update') {
            $currentHash = $this->buildTasksHash($tasksForDate);
            $cacheKey = "push_tasks_hash:{$user->id}:{$dateStr}";
            $lastHash = Cache::get($cacheKey);

            if ($lastHash === $currentHash) {
                // No changes, skip notification
                return null;
            }

            // Store the new hash
            Cache::put($cacheKey, $currentHash, now()->endOfDay());

            // If this is first check of the day (no previous hash), skip update notification
            // The morning "today" notification will handle it
            if ($lastHash === null) {
                return null;
            }
        }

        if ($tasksForDate->isEmpty()) {
            return null;
        }

        $count = $tasksForDate->count();
        $taskWord = $count === 1 ? 'task' : 'tasks';

        if ($this->notificationType === 'tomorrow') {
            $title = "Tomorrow's Tasks";
            $body = "You have {$count} {$taskWord} scheduled for tomorrow.";
        } elseif ($this->notificationType === 'update') {
            $title = 'Schedule Updated';
            $body = "Your schedule for today has been updated.";
        } else {
            $title = "Today's Tasks";
            $body = "You have {$count} {$taskWord} scheduled for today.";
        }

        $firstTask = $tasksForDate->first();
        if ($count === 1 && $firstTask) {
            $body = $firstTask->title ?? 'Task';
            if ($firstTask->project) {
                $body .= "\n" . ($firstTask->project->short_address ?? $firstTask->project->name);
            }
        } elseif ($count > 1) {
            $summaryLines = $this->buildTaskSummaryLines($tasksForDate, $dateStr, 2);
            $body = $body . "\n" . implode("\n", $summaryLines);
        }

        return [
            'title' => $title,
            'body' => $body,
            'tag' => "task-reminder-{$this->notificationType}-{$dateStr}",
            'data' => [
                'url' => '/dashboard',
            ],
        ];
    }

    protected function buildTaskSummaryLines($tasks, string $dateKey, int $maxLines = 2): array
    {
        $lines = [];
        $slice = $tasks->take($maxLines);

        foreach ($slice as $task) {
            $title = $task->title ?? 'Task';
            $time = task_time_in_browser_tz($task, $dateKey, 'start_time');
            $address = $task->project?->short_address ?? $task->project?->name;
            $timeLabel = $time ? " @ {$time}" : '';

            if ($address) {
                $lines[] = "• {$title}{$timeLabel}";
                $lines[] = "  {$address}";
            } else {
                $lines[] = "• {$title}{$timeLabel}";
            }
        }

        $remaining = $tasks->count() - $slice->count();
        if ($remaining > 0) {
            $lines[] = "+{$remaining} more";
        }

        return $lines;
    }

    protected function buildTasksHash($tasks): string
    {
        $ids = $tasks->pluck('id')->sort()->values()->toArray();

        return md5(json_encode($ids));
    }
}
