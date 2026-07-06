<?php

namespace App\Livewire\Sms\Concerns;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\User;
use Illuminate\Support\Collection;

trait ResolvesClientDisplayName
{
    public function clientDisplayNameForThread(?SmsGroupThread $thread): string
    {
        if (! $thread) {
            return '';
        }

        $client = $this->threadClientForDisplay($thread);

        if (! $client) {
            return '';
        }

        if (trim((string) $client->business_name) !== '') {
            return (string) $client->name;
        }

        $users = $this->threadClientUsersForDisplay($thread);

        if ($users->isEmpty()) {
            return (string) $client->name;
        }

        if ($users->count() === 1) {
            return $this->preferredUserDisplayName($users->first(), true);
        }

        $nameGroups = $users
            ->groupBy(fn (User $user) => trim((string) ($user->last_name ?? '')))
            ->map(function ($lastNameGroup, $lastName) {
                if ($lastNameGroup->count() === 1) {
                    return $this->preferredUserDisplayName($lastNameGroup->first(), true);
                }

                $firstNames = $lastNameGroup
                    ->map(fn (User $user) => $this->preferredUserDisplayName($user, false))
                    ->filter()
                    ->values()
                    ->all();

                return trim($this->oxfordJoin($firstNames) . ' ' . $lastName);
            })
            ->filter()
            ->values()
            ->all();

        return $this->oxfordJoin($nameGroups);
    }

    public function threadClientUsersForDisplay(SmsGroupThread $thread): Collection
    {
        $client = $this->threadClientForDisplay($thread);
        if (! $client) {
            return collect();
        }

        return $client->relationLoaded('users')
            ? $client->users
            : $client->users()->get(['users.id', 'first_name', 'last_name', 'nickname', 'cell_phone']);
    }

    protected function threadClientForDisplay(SmsGroupThread $thread): ?Client
    {
        if ($thread->client) {
            return $thread->client;
        }

        if (! $thread->client_id) {
            return null;
        }

        static $clientCache = [];
        $key = (int) $thread->client_id;

        if (array_key_exists($key, $clientCache)) {
            return $clientCache[$key];
        }

        $clientCache[$key] = Client::withoutGlobalScopes()
            ->with('users:id,first_name,last_name,nickname,cell_phone')
            ->find($key);

        return $clientCache[$key];
    }

    protected function preferredUserDisplayName(User $user, bool $includeLastName = true): string
    {
        $first = trim((string) ($user->nickname ?: $user->first_name));

        if (! $includeLastName) {
            return $first;
        }

        return trim($first . ' ' . trim((string) $user->last_name));
    }

    protected function oxfordJoin(array $items): string
    {
        return collect($items)->filter()->values()->join(', ', ' & ');
    }
}
