<?php

namespace App\Livewire\Sms;

use App\Models\SmsGroupThread;
use Livewire\Component;

class SmsSidebarBadge extends Component
{
    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:sms.notifications,SmsMessageReceived' => 'refreshBadge',
            'sms-thread-read' => 'refreshBadge',
            'sms-message-received' => 'refreshBadge',
        ];
    }

    public function refreshBadge(): void
    {
        \Illuminate\Support\Facades\Cache::forget($this->countCacheKey());
    }

    protected function countCacheKey(): string
    {
        $user = auth()->user();

        return 'sms:badge:'.($user?->id ?? 0).':'.($user?->is_browsing_as_client ? 'client' : ($user?->vendor?->id ?? 0));
    }

    public function render()
    {
        // The unread count renders on EVERY page (sidebar) and costs ~27ms of
        // aggregate SQL. Cache briefly; every event that can change the count
        // (incoming message via Echo, thread read) busts it via refreshBadge().
        $count = \Illuminate\Support\Facades\Cache::remember($this->countCacheKey(), 60, function () {
            $user = auth()->user();

            if (! $user) {
                return 0;
            }

            if ($user->is_browsing_as_client) {
                $clientIds = $user->clients()->pluck('clients.id')->toArray();

                return SmsGroupThread::unreadCountForUserInClients($user->id, $clientIds);
            }

            return SmsGroupThread::unreadCountForUser($user->id, $user->vendor?->id);
        });

        return view('livewire.sms.sms-sidebar-badge', [
            'count' => $count,
        ]);
    }
}
