{{--
    Message bubbles — the ONLY place conversation bubble markup lives.

    Rendered in two modes:
    - $interactive = true  → the live SmsConversation component (wire:key,
      wire:click lightbox/scheduled/message actions, load-more trigger).
    - $interactive = false → the offline cached fragment (sms/offline-conversation):
      wire:* affordances are dropped, lightbox buttons degrade to plain
      <img>/<video>, scheduled-message action buttons and the per-message
      menu are omitted. Local-only Alpine (showOriginal toggle) still works.

    Expected variables (no $this — the offline fragment renders outside any
    Livewire component):
      $visibleMessages, $scheduledMessages, $reactionsMap, $phoneNameMap,
      $tz, $now, $todayDate, $yesterdayDate,
      $isClientUser, $interactive, $threadHasMixedNumbers, $hasMoreMessages,
      $resolveMediaUrl (closure url → streaming url),
      $allowsTaskCreation (closure msg → bool; only called when interactive)
--}}
{{-- Scheduled messages always at bottom (first in DOM due to flex-col-reverse) --}}
@foreach ($scheduledMessages as $msg)
    <div @if ($interactive)wire:key="msg-{{ $msg->id }}"@endif class="flex justify-end">
        <div class="max-w-[85%] lg:max-w-[75%] order-last">
            <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1 text-right">
                {{ $msg->sentByUser?->nickname ?: ($msg->sentByUser?->first_name ?? 'GS Crew') }}
            </p>

            <div class="relative">
                <div class="mb-1.5 flex items-center justify-end gap-1">
                    <flux:badge color="amber" size="sm" icon="clock">
                        {{ $msg->scheduled_at ? 'Scheduled · ' . $msg->scheduled_at->timezone('America/Chicago')->format('M j, g:i A') : 'Draft' }}
                    </flux:badge>
                    @if ($interactive)
                    <flux:button size="xs" variant="ghost" square icon="pencil-square" wire:click="openEditScheduledMessage({{ $msg->id }})" tooltip="Edit" aria-label="Edit scheduled message"></flux:button>
                    <flux:button size="xs" variant="primary" square icon="paper-airplane" wire:click="sendScheduledNow({{ $msg->id }})" tooltip="Send now" aria-label="Send now"></flux:button>
                    <flux:button size="xs" variant="danger" square icon="x-mark" wire:click="$set('cancelScheduledId', {{ $msg->id }}); $set('showCancelModal', true)" tooltip="Cancel" aria-label="Cancel scheduled message"></flux:button>
                    @endif
                </div>

                <div class="rounded-2xl px-3.5 py-2 text-base lg:text-sm break-words bg-indigo-600/50 text-white/80 rounded-br-md">
                    @if ($msg->hasMedia())
                        <div class="space-y-2 {{ $msg->text ? 'mb-1.5' : '' }}">
                            @foreach ($msg->media_urls as $url)
                                @php $mediaUrl = $resolveMediaUrl($url); @endphp
                                @if (\App\Models\SmsMessage::isVideoUrl($url))
                                    @if ($interactive)
                                    <button type="button" class="block relative" wire:click="openVideoLightbox('{{ $url }}')">
                                        <video preload="metadata" class="max-w-full rounded-lg max-h-64 bg-black pointer-events-none" playsinline>
                                            <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                            Your browser does not support the video tag.
                                        </video>
                                        <span class="absolute inset-0 flex items-center justify-center">
                                            <span class="inline-flex items-center justify-center size-12 rounded-full bg-black/50 text-white">
                                                <svg class="size-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                    <path d="M8 5v14l11-7-11-7z" />
                                                </svg>
                                            </span>
                                        </span>
                                    </button>
                                    @else
                                    <video controls preload="metadata" class="max-w-full rounded-lg max-h-64 bg-black" playsinline>
                                        <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                        Your browser does not support the video tag.
                                    </video>
                                    @endif
                                @elseif (\App\Models\SmsMessage::isAudioUrl($url))
                                    <audio controls preload="metadata" class="max-w-full">
                                        <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                    </audio>
                                @else
                                    @if ($interactive)
                                    <button type="button" class="block" wire:click="openImageLightbox('{{ $url }}')">
                                        <img src="{{ $mediaUrl }}" alt="MMS attachment" class="max-w-full rounded-lg max-h-64 object-cover" loading="lazy" />
                                    </button>
                                    @else
                                    <img src="{{ $mediaUrl }}" alt="MMS attachment" class="max-w-full rounded-lg max-h-64 object-cover" loading="lazy" />
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    @endif
                    @if (($msg->translated_display_text ?? $msg->display_text))
                        {!! preg_replace(
                            '/(https?:\/\/[^\s<]+)/',
                            '<a href="$1" target="_blank" class="underline text-indigo-100 hover:text-white">$1</a>',
                            nl2br(e($msg->translated_display_text ?? $msg->display_text))
                        ) !!}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endforeach

@php
    // iMessage-style delivery badge: "Delivered"/"Sent" appears only under the
    // NEWEST outbound message; failed messages always show "Not Delivered".
    // (flex-col-reverse container: first rendered = newest, at the bottom.)
    $newestOutboundId = $visibleMessages->reverse()->first(fn ($m) => $m->isOutbound())?->id;
@endphp
@forelse ($visibleMessages->reverse() as $msg)
    @if ($interactive && $loop->last && $hasMoreMessages)
        <div data-load-more class="text-center py-2">
            <span wire:loading wire:target="loadMoreMessages" class="text-xs text-zinc-400">Loading...</span>
        </div>
    @endif
    @php
        $msgLocal = $msg->created_at->copy()->setTimezone($tz);
        $msgDate = $msgLocal->toDateString();
        if ($msgDate === $todayDate) {
            $timeLabel = $msgLocal->format('g:i A');
        } elseif ($msgDate === $yesterdayDate) {
            $timeLabel = 'Yesterday ' . $msgLocal->format('g:i A');
        } elseif ($msgLocal->year !== $now->year) {
            $timeLabel = $msgLocal->format('M j, Y, g:i A');
        } else {
            $timeLabel = $msgLocal->format('M j, g:i A');
        }
        $msgReactions = $reactionsMap[$msg->id] ?? [];
        $translatedTextForUi = (string) ($msg->translated_display_text ?? $msg->display_text ?? '');
        $originalTextForUi = (string) ($msg->original_display_text ?? $msg->display_text ?? '');
        $languageBadge = $msg->language_badge ?? null;
        $canToggleOriginal = (bool) ($msg->show_original_toggle ?? false);

        // On-demand translation of THIS message into the reader's own
        // language. The thread body is always English; a non-English reader
        // presses the badge and only that message is translated, server-side.
        $viewerTranslation = ($viewerTranslations ?? [])[$msg->id] ?? null;
        // Offered on every message for a non-English reader — including ones
        // written in English, which is most of the thread and exactly what the
        // old original-only toggle could never show them.
        $canTranslateForViewer = $interactive && ($viewerCanTranslate ?? false) && ($translatedTextForUi !== '');

        if ($viewerTranslation !== null) {
            $translatedTextForUi = $viewerTranslation;
        }
    @endphp
    <div @if ($interactive)wire:key="msg-{{ $msg->id }}"@endif data-msg-id="{{ $msg->id }}" class="flex items-center group"
        x-data="{ showOriginal: false, showActions: false, isTouch: window.matchMedia('(hover: none)').matches }"
        x-on:mouseenter="if (!isTouch) { $dispatch('sms-message-actions-focus', { id: {{ $msg->id }} }); showActions = true }"
        x-on:mouseleave="if (!isTouch) { showActions = false }"
        x-on:sms-message-actions-focus.window="if ($event.detail?.id !== {{ $msg->id }}) { showActions = false }"
        x-bind:class="selectionMode ? '' : '{{ $msg->isOutbound() ? 'justify-end' : 'justify-start' }}'">
        <div class="flex-shrink-0 pr-2" x-show="selectionMode" x-cloak>
            {{-- Lightweight checkbox (flux:checkbox is ~2KB of markup per
                 bubble); mirrors the Flux accent look. --}}
            <button type="button" aria-label="Select message"
                class="flex size-[1.125rem] items-center justify-center rounded-[.3rem] border shadow-xs transition-colors"
                x-bind:class="has({{ $msg->id }})
                    ? 'bg-indigo-600 border-indigo-600 text-white dark:bg-indigo-500 dark:border-indigo-500'
                    : 'border-zinc-300 bg-white text-transparent dark:border-white/10 dark:bg-white/10'"
                x-on:click.stop="toggle({{ $msg->id }})">
                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>
        <div
            class="max-w-[85%] lg:max-w-[75%]"
            x-bind:class="{
                'ml-auto': selectionMode && {{ $msg->isOutbound() ? 'true' : 'false' }},
                'order-last': !selectionMode && {{ $msg->isOutbound() ? 'true' : 'false' }},
                'cursor-pointer': selectionMode,
            }"
            x-on:click="if (selectionMode) { toggle({{ $msg->id }}) } else if (isTouch) { $dispatch('sms-message-actions-focus', { id: {{ $msg->id }} }); showActions = !showActions }"
        >
            @if ($msg->isInbound())
                <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1 flex items-center gap-1">
                    <span>{{ $phoneNameMap[$msg->from_number] ?? $msg->from_number }}</span>
                    @if ($msg->was_edited ?? false)
                        <span class="italic">(Edited)</span>
                    @endif
                    @if ($languageBadge && ($canToggleOriginal || $canTranslateForViewer))
                        @if ($canTranslateForViewer)
                            <button
                                type="button"
                                wire:click.stop="toggleMessageTranslation({{ $msg->id }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleMessageTranslation({{ $msg->id }})"
                                class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[9px] font-medium tracking-wide transition-colors {{ $viewerTranslation !== null
                                    ? 'bg-indigo-600 text-white border-indigo-600 dark:bg-indigo-500 dark:text-white dark:border-indigo-500'
                                    : 'bg-transparent text-zinc-600 dark:text-zinc-300 border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                                aria-label="{{ $viewerTranslation !== null ? 'Show this message in English' : 'Translate this message' }}"
                            >{{ $languageBadge }}</button>
                        @else
                            <button
                                type="button"
                                class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[9px] font-medium tracking-wide transition-colors"
                                x-bind:class="showOriginal
                                    ? 'bg-indigo-600 text-white border-indigo-600 dark:bg-indigo-500 dark:text-white dark:border-indigo-500'
                                    : 'bg-transparent text-zinc-600 dark:text-zinc-300 border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                                x-on:click.stop="showOriginal = !showOriginal"
                                x-text="@js($languageBadge)"
                                :aria-label="showOriginal ? 'Show translated message' : 'Show original message'"
                            ></button>
                        @endif
                    @endif
                </p>
            @elseif ($msg->isOutbound())
                <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1 text-right flex items-center justify-end gap-1">
                    @if ($languageBadge && ($canToggleOriginal || $canTranslateForViewer))
                        @if ($canTranslateForViewer)
                            <button
                                type="button"
                                wire:click.stop="toggleMessageTranslation({{ $msg->id }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleMessageTranslation({{ $msg->id }})"
                                class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[9px] font-medium tracking-wide transition-colors {{ $viewerTranslation !== null
                                    ? 'bg-indigo-600 text-white border-indigo-600 dark:bg-indigo-500 dark:text-white dark:border-indigo-500'
                                    : 'bg-transparent text-zinc-600 dark:text-zinc-300 border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                                aria-label="{{ $viewerTranslation !== null ? 'Show this message in English' : 'Translate this message' }}"
                            >{{ $languageBadge }}</button>
                        @else
                            <button
                                type="button"
                                class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[9px] font-medium tracking-wide transition-colors"
                                x-bind:class="showOriginal
                                    ? 'bg-indigo-600 text-white border-indigo-600 dark:bg-indigo-500 dark:text-white dark:border-indigo-500'
                                    : 'bg-transparent text-zinc-600 dark:text-zinc-300 border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                                x-on:click.stop="showOriginal = !showOriginal"
                                x-text="@js($languageBadge)"
                                :aria-label="showOriginal ? 'Show translated message' : 'Show original message'"
                            ></button>
                        @endif
                    @endif
                    @if ($msg->was_edited ?? false)
                        <span class="italic">(Edited)</span>
                    @endif
                    <span>{{ $msg->sentByUser?->nickname ?: ($msg->sentByUser?->first_name ?? 'GS Crew') }}</span>
                </p>
            @endif

            <div class="relative">
                <div class="rounded-2xl px-3.5 py-2 text-base lg:text-sm break-words {{ $msg->isOutbound()
                    ? 'bg-indigo-600 text-white rounded-br-md'
                    : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 rounded-bl-md' }}">
                    @if ($msg->hasMedia())
                        <div class="space-y-2 {{ $msg->text ? 'mb-1.5' : '' }}">
                            @foreach ($msg->media_urls as $url)
                                @php $mediaUrl = $resolveMediaUrl($url); @endphp
                                @if (\App\Models\SmsMessage::isVideoUrl($url))
                                    @if ($interactive)
                                    <button type="button" class="block relative" wire:click="openVideoLightbox('{{ $url }}')">
                                        <video preload="metadata" class="max-w-full rounded-lg max-h-64 bg-black pointer-events-none" playsinline>
                                            <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                            Your browser does not support the video tag.
                                        </video>
                                        <span class="absolute inset-0 flex items-center justify-center">
                                            <span class="inline-flex items-center justify-center size-12 rounded-full bg-black/50 text-white">
                                                <svg class="size-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                    <path d="M8 5v14l11-7-11-7z" />
                                                </svg>
                                            </span>
                                        </span>
                                    </button>
                                    @else
                                    <video controls preload="metadata" class="max-w-full rounded-lg max-h-64 bg-black" playsinline>
                                        <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                        Your browser does not support the video tag.
                                    </video>
                                    @endif
                                @elseif (\App\Models\SmsMessage::isAudioUrl($url))
                                    <audio controls preload="metadata" class="max-w-full">
                                        <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                    </audio>
                                @else
                                    @if ($interactive)
                                    <button
                                        type="button"
                                        class="block"
                                        wire:click="openImageLightbox('{{ $url }}')"
                                    >
                                        <img
                                            src="{{ $mediaUrl }}"
                                            alt="MMS attachment"
                                            class="max-w-full rounded-lg max-h-64 object-cover"
                                            loading="lazy"
                                            onerror="this.parentElement.innerHTML='<div class=\'flex items-center gap-1.5 py-2 text-sm opacity-75\'><svg xmlns=\'http://www.w3.org/2000/svg\' class=\'size-4\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909-4.97-4.969a.75.75 0 0 0-1.06 0L2.5 11.06Zm12.5-2.56a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z\' clip-rule=\'evenodd\'/></svg> Image unavailable</div>'"
                                        />
                                    </button>
                                    @else
                                    <img
                                        src="{{ $mediaUrl }}"
                                        alt="MMS attachment"
                                        class="max-w-full rounded-lg max-h-64 object-cover"
                                        loading="lazy"
                                        onerror="this.parentElement.innerHTML='<div class=\'flex items-center gap-1.5 py-2 text-sm opacity-75\'><svg xmlns=\'http://www.w3.org/2000/svg\' class=\'size-4\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909-4.97-4.969a.75.75 0 0 0-1.06 0L2.5 11.06Zm12.5-2.56a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z\' clip-rule=\'evenodd\'/></svg> Image unavailable</div>'"
                                    />
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    @endif
                    @if ($translatedTextForUi !== '')
                        @if ($canToggleOriginal)
                            <div x-show="!showOriginal">
                                {!! preg_replace(
                                    '/(https?:\/\/[^\s<]+)/',
                                    '<a href="$1" target="_blank" class="underline ' . ($msg->isOutbound() ? 'text-indigo-100 hover:text-white' : 'text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300') . '">$1</a>',
                                    nl2br(e($translatedTextForUi))
                                ) !!}
                            </div>
                            <div x-show="showOriginal">
                                {!! preg_replace(
                                    '/(https?:\/\/[^\s<]+)/',
                                    '<a href="$1" target="_blank" class="underline ' . ($msg->isOutbound() ? 'text-indigo-100 hover:text-white' : 'text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300') . '">$1</a>',
                                    nl2br(e($originalTextForUi))
                                ) !!}
                            </div>
                        @else
                            {!! preg_replace(
                                '/(https?:\/\/[^\s<]+)/',
                                '<a href="$1" target="_blank" class="underline ' . ($msg->isOutbound() ? 'text-indigo-100 hover:text-white' : 'text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300') . '">$1</a>',
                                nl2br(e($translatedTextForUi))
                            ) !!}
                        @endif
                    @endif
                </div>

                {{-- Tapback reactions --}}
                @if (! empty($msgReactions))
                    <div class="flex gap-0.5 {{ $msg->isOutbound() ? 'justify-end -mr-1' : '-ml-1' }} -mt-2 relative z-10">
                        @foreach ($msgReactions as $emoji => $senders)
                            <span
                                class="inline-flex items-center gap-0.5 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 px-1 py-0.5 text-xs shadow-sm"
                                title="{{ implode(', ', $senders) }}"
                            >
                                {{ $emoji }}@if (count($senders) > 1)<span class="text-[10px] text-zinc-500">{{ count($senders) }}</span>@endif
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5 {{ $msg->isOutbound() ? 'text-right' : '' }} px-1">
                {{ $timeLabel }}
                @if ($threadHasMixedNumbers)
                    @php
                        $numbers = config('services.telnyx.numbers', []);
                        $primaryNumber = config('services.telnyx.from');
                        $msgNumber = $msg->isOutbound()
                            ? $msg->from_number
                            : collect($msg->to_numbers)->first(fn ($n) => in_array($n, $numbers));
                    @endphp
                    @if ($msgNumber && $msgNumber !== $primaryNumber)
                        <span class="text-zinc-400/70 dark:text-zinc-500/70">&middot; {{ substr($msgNumber, -4) }}</span>
                    @endif
                @endif
                @if ($msg->isOutbound())
                    @if ($msg->status === 'failed')
                        @php
                            $deliveryError = collect($msg->raw_payload['delivery_errors'] ?? [])
                                ->map(fn ($e) => $e['title'] ?? $e['detail'] ?? null)
                                ->filter()->unique()->implode('; ');
                        @endphp
                        <span class="text-red-500 dark:text-red-400 font-medium cursor-default"
                            @if ($deliveryError) title="{{ $deliveryError }}" @endif>&middot; Not Delivered</span>
                    @elseif ($msg->id === $newestOutboundId)
                        @if ($msg->status === 'delivered')
                            <span>&middot; Delivered</span>
                        @elseif (in_array($msg->status, ['sent', 'queued', 'sending'], true))
                            <span>&middot; Sent</span>
                        @endif
                    @endif
                @endif
            </p>
        </div>
        @if ($interactive && ! $isClientUser && (($msg->translated_display_text ?? $msg->display_text) || $msg->hasMedia()))
            {{-- Slim trigger only — the menu itself is rendered ONCE per
                 conversation (shared panel in conversation.blade.php) and
                 anchored here on click. A full flux:dropdown per bubble cost
                 ~7KB × every message on every bubble render. --}}
            <button
                type="button"
                class="flex items-center self-center transition-opacity p-1 rounded-md text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 dark:hover:text-zinc-300 dark:hover:bg-zinc-700/50 {{ $msg->isOutbound() ? 'order-first mr-1' : 'ml-1' }}"
                x-bind:class="(isTouch || showActions)
                    ? 'opacity-100'
                    : 'opacity-0 group-hover:opacity-100'"
                x-show="!selectionMode"
                aria-label="Message actions"
                x-on:click.stop="showActions = true; $dispatch('sms-message-menu', {
                    id: {{ $msg->id }},
                    anchor: $el,
                    canTask: @js($allowsTaskCreation($msg)),
                    hasText: @js(filled($translatedTextForUi)),
                    images: @js(collect(is_array($msg->media_urls) ? $msg->media_urls : [])->filter(fn ($u) => is_string($u) && preg_match('/\.(jpe?g|png|heic|webp|gif)$/i', $u) === 1)->count()),
                    text: showOriginal ? @js($originalTextForUi) : @js($translatedTextForUi),
                })"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 7.25a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5Zm0 6.5a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5Zm0 6.5a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5Z"/></svg>
            </button>
        @endif
    </div>
@empty
    <div class="text-center py-12">
        <p class="text-sm text-zinc-400 dark:text-zinc-500">No messages yet. Send the first one!</p>
    </div>
@endforelse
