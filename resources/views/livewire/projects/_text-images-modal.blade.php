{{-- Inner form of the text-images modal. Own file because Blaze
     miscompiles an @if guard wrapping a form inside a component slot
     (unexpected endif) — the include boundary keeps units intact. --}}
        <form wire:submit="textImages" class="flex flex-col min-h-0 min-w-0 flex-1 w-full" x-data="{ q: '' }">
            <div class="px-6 pt-6 pb-4 space-y-4 border-b border-zinc-200 dark:border-zinc-700">
                <div>
                    @php $textPhotoCount = count($textFrameIds) + count($textMessageUrls); @endphp
                    <flux:heading size="lg">Text {{ $textPhotoCount }} {{ \Illuminate\Support\Str::plural('photo', $textPhotoCount) }}</flux:heading>
                    <flux:text class="mt-1">Sends them as a text message with an optional note.</flux:text>
                </div>

                <flux:field>
                    <flux:label>Search conversations</flux:label>
                    <flux:input x-model="q" placeholder="Search by name, client, address..." />
                </flux:field>
            </div>

            <div class="flex-1 min-h-0 min-w-0 overflow-auto px-6 py-4 space-y-4">
                <flux:field>
                    <flux:label>Conversation</flux:label>
                    @if ($this->textableThreads->isEmpty())
                        <div class="px-3 py-6 text-sm text-zinc-500 dark:text-zinc-400 text-center border border-zinc-200 dark:border-zinc-700 rounded-lg">
                            No conversations found.
                        </div>
                    @else
                        <flux:radio.group wire:model="textTargetThreadId" variant="cards" class="flex-col gap-1" :indicator="true">
                            @foreach ($this->textableThreads as $candidate)
                                @php
                                    $label = $this->textThreadLabel($candidate);
                                    $desc = $candidate->last_activity_at ? 'Last activity '.$candidate->last_activity_at->diffForHumans() : '';
                                    $haystack = mb_strtolower($label.' '.$desc);
                                @endphp
                                <div
                                    wire:key="text-thread-{{ $candidate->id }}"
                                    x-show="typeof q === 'undefined' || q === '' || @js($haystack).includes(q.toLowerCase())"
                                >
                                    <flux:radio :value="$candidate->id" :label="$label" :description="$desc ?: null">{{ $label }}</flux:radio>
                                </div>
                            @endforeach
                        </flux:radio.group>
                    @endif
                    <flux:error name="textTargetThreadId" />
                    <flux:error name="textFrameIds" />
                </flux:field>

                <flux:field>
                    <flux:label>Note (optional)</flux:label>
                    <flux:input wire:model="textNote" placeholder="Photos from {{ $project->short_address ?? $project->address }}" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2 px-6 py-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/50">
                <flux:button type="button" variant="ghost" x-on:click="$flux.modal('text-images').close()">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="chat-bubble-left-right">Send</flux:button>
            </div>
        </form>
