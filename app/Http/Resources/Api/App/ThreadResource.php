<?php

namespace App\Http\Resources\Api\App;

use App\Livewire\Sms\Concerns\ResolvesClientDisplayName;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SmsGroupThread
 */
class ThreadResource extends JsonResource
{
    use ResolvesClientDisplayName;

    /**
     * Compute callable_contacts (ConversationPresenter::callableContacts(),
     * the conversation's quick-call row) — off by default. It isn't needed
     * by a list row, and threads a list row's underlying query didn't eager
     * load client.users/threadParticipants for (e.g. ThreadForwardController's
     * candidateThreads()) would otherwise lazy-load it once per thread.
     * ThreadController::show() is the only caller that needs it and opts in.
     */
    protected bool $withCallableContacts = false;

    public function withCallableContacts(): static
    {
        $this->withCallableContacts = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latest = $this->latestMessage;

        return [
            'id' => $this->id,
            'title' => $this->resolveTitle(),
            'participants' => $this->threadParticipants->map(fn ($participant) => [
                'phone' => $participant->phone_number,
                'name' => ConversationPresenter::resolvePhoneDisplay($participant->phone_number),
            ])->values()->all(),
            'client_id' => $this->client_id,
            'project_id' => $this->project_id,
            'subject_vendor_id' => $this->subject_vendor_id,
            // Only present when the controller computed it (see
            // ThreadController::unreadCountsByThread()) — a real count of
            // unread inbound messages, not a fabricated one.
            'unread_count' => (int) ($this->unread_count ?? 0),
            // Mirrors the composer's "Awaiting START reply" banner and the
            // thread menu's Resend/Manual Opt-in items.
            'pending_opt_in' => $this->threadParticipants->contains(fn ($participant) => $participant->opted_in_at === null),
            'pending_participants' => $this->threadParticipants
                ->filter(fn ($participant) => $participant->opted_in_at === null)
                ->map(fn ($participant) => [
                    'id' => $participant->id,
                    'phone' => $participant->phone_number,
                    'name' => ConversationPresenter::resolvePhoneDisplay($participant->phone_number),
                ])
                ->values()
                ->all(),
            'last_message' => $latest ? [
                'id' => $latest->id,
                'body' => $latest->display_text,
                'direction' => $latest->direction,
                'sent_at' => $latest->created_at?->toIso8601String(),
                // Mirrors thread-list.blade.php's $previewPrefix: null for
                // the viewer's own outbound message ("You"), the sending
                // teammate's name for a teammate's, the resolved sender's
                // name for an inbound one.
                'sender_name' => ConversationPresenter::previewSenderName($latest, $this->resource, $request->user()?->id),
            ] : null,
            'last_activity_at' => ($this->last_activity_at ?? $this->created_at)?->toIso8601String(),
            // Mirrors the thread list row's amber clock badge — only present
            // when the controller computed it via withCount(), same reasoning
            // as unread_count above.
            'scheduled_messages_count' => (int) ($this->scheduled_messages_count ?? 0),
            // Mirrors the thread list row's dimmed/shield-exclamation spam
            // styling — only present when the controller computed it (see
            // LoadsThreadForApp::attachThreadSpamFlags()).
            'is_spam' => (bool) ($this->is_spam ?? false),
            // The quick-call row under the web conversation's title
            // (conversation.blade.php's $callableContacts) — who this thread
            // can click-to-call, excluding the business number itself. Only
            // computed when withCallableContacts() was called (see above).
            'callable_contacts' => $this->withCallableContacts
                ? (new ConversationPresenter($this->resource, null))->callableContacts()
                : [],
        ];
    }

    /**
     * Mirrors the vendor-user branch of the web thread list's title logic
     * (resources/views/livewire/sms/thread-list.blade.php, @elseif
     * ($thread->client_id) and below). The Hive Contractors app is GS
     * Construction's own team only, so the client-browsing branch there
     * never applies to a request this resource serves.
     */
    private function resolveTitle(): string
    {
        /** @var \App\Models\SmsGroupThread $thread */
        $thread = $this->resource;

        if ($thread->isTeam()) {
            return $thread->teamTitleFor(request()->user());
        }

        if ($thread->client_id) {
            // collect(...->all()): Eloquent\Collection::map() only downgrades
            // to a plain Collection when it SEES a non-Model item in the
            // result: for a client with zero attached users, the mapped
            // collection is empty, that check never fires, and the result
            // stays Eloquent-typed. diff() against it then tries to call
            // getKey() on the plain phone-number strings on the other side
            // and fatals — forcing a plain Collection here avoids that.
            $clientUserPhones = collect(
                $this->threadClientUsersForDisplay($thread)
                    ->map(fn ($user) => $user->routeNotificationForTelnyx())
                    ->filter()
                    ->values()
                    ->all()
            );
            $participantPhones = $thread->threadParticipants->pluck('phone_number')->filter()->values();
            $extraPhones = $participantPhones->diff($clientUserPhones)->values();
            $missingClientUsers = $clientUserPhones->diff($participantPhones)->isNotEmpty();

            if ($missingClientUsers && $extraPhones->isEmpty()) {
                return $participantPhones->map(fn ($phone) => ConversationPresenter::resolvePhoneDisplay($phone))->implode(', ');
            }

            $clientLabel = $this->clientDisplayNameForThread($thread);

            if ($extraPhones->isNotEmpty()) {
                return $clientLabel . ', ' . $extraPhones->map(fn ($phone) => ConversationPresenter::resolvePhoneDisplay($phone))->implode(', ');
            }

            return $clientLabel;
        }

        if ($thread->name) {
            return $thread->name;
        }

        if ($thread->project) {
            return $thread->project->address;
        }

        return $thread->threadParticipants->pluck('phone_number')
            ->map(fn ($phone) => ConversationPresenter::resolvePhoneDisplay($phone))
            ->implode(', ');
    }
}
