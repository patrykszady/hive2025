<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every 403 the app sends. Laravel never reports AuthorizationException
 * or abort(403), and nginx keeps no access log for this site, so a refused
 * Livewire action (e.g. saving Add Member on /clients/*) left no trace.
 */
class LogForbiddenResponses
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_FORBIDDEN) {
            $exception = $response->exception ?? null;

            Log::warning('403 Forbidden: '.$request->method().' /'.ltrim($request->path(), '/'), array_filter([
                'user_id' => $request->user()?->id,
                'user_email' => $request->user()?->email,
                'route' => $request->route()?->getName(),
                'reason' => $exception?->getMessage(),
                'exception' => $exception ? $exception::class : null,
                'livewire' => $this->livewireCalls($request),
                'ip' => $request->ip(),
            ], fn ($value) => $value !== null && $value !== []));
        }

        return $response;
    }

    /**
     * The components and methods a Livewire update asked for, so a 403 on
     * the shared update endpoint names the action that was refused.
     *
     * @return list<array{component: string, calls: list<string>}>
     */
    protected function livewireCalls(Request $request): array
    {
        if (! $request->hasHeader('X-Livewire')) {
            return [];
        }

        return collect($request->input('components', []))
            ->filter(fn ($component) => is_array($component))
            ->map(function (array $component): array {
                $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);

                return [
                    'component' => (string) ($snapshot['memo']['name'] ?? 'unknown'),
                    'calls' => collect($component['calls'] ?? [])
                        ->pluck('method')
                        ->filter(fn ($method) => is_string($method))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
