{{-- Split-screen guest layout, same as the login page: the task on the white
     left half, info on the indigo right half (hidden below lg). The guest
     layout's body gradient draws the two halves. --}}
<div class="flex min-h-screen">

    {{-- LEFT: the scheduler --}}
    <div class="flex-1 flex justify-center items-center">
        <div class="w-full max-w-xl space-y-5 p-4">
            @if ($this->submitted)
                <div class="space-y-2 text-center py-6">
                    <flux:icon.check-circle class="mx-auto size-10 text-green-500" />
                    <flux:heading size="lg">Thank you!</flux:heading>
                    <flux:text>
                        We received your preferred times and will confirm your consultation by email shortly.
                    </flux:text>
                    <flux:text class="text-zinc-500">
                        Need to change something? Just reply to our email and we&rsquo;ll sort it out.
                    </flux:text>
                </div>
            @else
                <div>
                    <flux:heading size="xl">Preferred Consultation Times</flux:heading>
                    <flux:text class="mt-1">
                        Please select at least {{ \App\Livewire\Leads\PickTimes::MIN_TIMES }} times across
                        {{ \App\Livewire\Leads\PickTimes::MIN_DAYS }} different days you're available to meet,
                        and {{ $this->vendor?->name ?? 'we' }} will confirm one by email.
                    </flux:text>
                </div>

                {{-- Same layout as the website widget: calendar left, the selected
                     day's windows right. --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-[auto_1fr]">
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        {{-- Weekends are unselectable here and refused server-side.
                             Flux strikes unavailable days through (data-unavailable:
                             line-through); weekends aren't cancellations, so dim
                             them like out-of-range days instead. --}}
                        <flux:calendar
                            wire:model.live="date"
                            size="sm"
                            min="{{ \App\Livewire\Leads\PickTimes::firstBookableDate($this->lead) }}"
                            :unavailable="\App\Livewire\Leads\PickTimes::unavailableDates($this->lead)"
                            class="[&_[data-unavailable]]:no-underline [&_[data-unavailable]]:opacity-40"
                        />
                    </div>

                    <div class="space-y-2">
                        <div class="flex items-center gap-2 pt-1">
                            <flux:icon.calendar variant="mini" class="text-indigo-500" />
                            <flux:heading size="sm" class="mb-0">
                                {{ $date ? \Carbon\Carbon::parse($date)->format('l, F j') : 'Pick a date' }}
                            </flux:heading>
                        </div>

                        @foreach (\App\Livewire\Leads\PickTimes::WINDOWS as $w)
                            @php($active = in_array(['date' => $date, 'time' => $w], $times, true))
                            @php($booked = in_array($w, $this->busyWindows, true))
                            {{-- Booked = something already on Patryk's or Greg's
                                 calendar overlaps this window: disabled and
                                 struck through (like the calendar's blocked
                                 days), but no "booked" label. toggleWindow
                                 refuses these server-side too. --}}
                            <flux:button
                                wire:click="toggleWindow('{{ $w }}')"
                                :variant="$active ? 'primary' : 'outline'"
                                class="w-full {{ $booked ? 'line-through' : '' }}"
                                size="sm"
                                :disabled="$booked"
                            >
                                {{ $w }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>

                @if ($times !== [])
                    <flux:field>
                        <flux:label>Your times</flux:label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($times as $index => $slot)
                                <button type="button" wire:click="removeSlot({{ $index }})" class="cursor-pointer" title="Remove">
                                    <flux:badge color="indigo">
                                        {{ \Carbon\Carbon::parse($slot['date'])->format('D, M j') }} · {{ $slot['time'] }}
                                        <flux:icon.x-mark variant="micro" class="ml-1 size-3" />
                                    </flux:badge>
                                </button>
                            @endforeach
                        </div>
                    </flux:field>
                @endif
                {{-- How they'd like to meet: at the house, or a Teams video
                     call. The team sees the pick and books that kind of
                     consult. --}}
                <flux:field>
                    <flux:label>How would you like to meet?</flux:label>
                    <div class="flex gap-2">
                        <button type="button" wire:click="$set('meeting', 'in_person')" class="cursor-pointer">
                            <flux:badge :color="$meeting === 'in_person' ? 'indigo' : 'zinc'" icon="map-pin">
                                At my home
                            </flux:badge>
                        </button>
                        <button type="button" wire:click="$set('meeting', 'virtual')" class="cursor-pointer">
                            <flux:badge :color="$meeting === 'virtual' ? 'indigo' : 'zinc'" icon="video-camera">
                                Video call
                            </flux:badge>
                        </button>
                    </div>
                </flux:field>

                <flux:error name="date" />
                <flux:error name="times" />

                {{-- Why the button is off, right where people look for it. --}}
                @if($this->submitHint)
                    <flux:callout variant="warning" icon="exclamation-triangle" :heading="$this->submitHint" />
                @endif

                <flux:button variant="primary" class="w-full" wire:click="submit" :disabled="! $this->canSubmit">
                    Send availability
                </flux:button>

                <flux:text class="text-xs text-zinc-500">
                    Our crew puts in long days on site, so early morning and daytime
                    consultations suit us best &mdash; thank you for understanding. If only a
                    weekday late afternoon or Saturday morning works for you, please email us.
                </flux:text>
            @endif

            {{-- Small, unobtrusive — visible whether they're still
                 picking or already saw the thank-you. --}}
            <div class="pt-2 text-center">
                @if ($feedbackSent)
                    <flux:badge icon="check-circle" color="green" size="sm">Feedback sent — thank you!</flux:badge>
                @else
                    <flux:modal.trigger name="lead_feedback_modal">
                        <flux:button size="sm" variant="ghost" icon="chat-bubble-left-ellipsis">
                            Feedback
                        </flux:button>
                    </flux:modal.trigger>
                @endif
            </div>

            <flux:modal name="lead_feedback_modal" class="space-y-4 max-w-md">
                <div class="space-y-1">
                    <flux:heading size="lg">How was scheduling with us?</flux:heading>
                    <flux:subheading>A quick note helps us make this easier for the next person.</flux:subheading>
                </div>

                <flux:field>
                    <flux:radio.group wire:model="feedbackRating" label="Rating" variant="segmented">
                        <flux:radio value="1" label="1" />
                        <flux:radio value="2" label="2" />
                        <flux:radio value="3" label="3" />
                        <flux:radio value="4" label="4" />
                        <flux:radio value="5" label="5" />
                    </flux:radio.group>
                    <flux:error name="feedbackRating" />
                </flux:field>

                <flux:field>
                    <flux:label>Anything you'd like to add? (optional)</flux:label>
                    <flux:textarea
                        wire:model="feedbackMessage"
                        rows="3"
                        maxlength="2000"
                        placeholder="What was clunky, confusing, or great?"
                    />
                    <flux:error name="feedbackMessage" />
                </flux:field>

                <flux:error name="feedback" />

                <div class="flex gap-2 mb-0!">
                    <flux:button wire:click="closeFeedbackModal" variant="ghost" class="flex-1">
                        Cancel
                    </flux:button>
                    <flux:button
                        variant="primary"
                        class="flex-1"
                        x-on:click="$wire.sendFeedback(window.innerWidth + 'x' + window.innerHeight)"
                        wire:loading.attr="disabled"
                        wire:target="sendFeedback"
                    >
                        Send
                    </flux:button>
                </div>
            </flux:modal>
        </div>
    </div>

    {{-- RIGHT: what happens next (desktop only, same panel treatment as login) --}}
    <div class="flex-1 p-4 max-lg:hidden">
        <div class="text-white relative rounded-lg h-full w-full bg-indigo-900 flex flex-col items-start justify-end p-16">
            <div class="mb-6">
                <div class="flex gap-4 mb-6">
                    <flux:icon.calendar-days variant="outline" class="size-16 text-indigo-300" />
                    <flux:icon.home variant="outline" class="size-16 text-indigo-300" />
                    <flux:icon.wrench-screwdriver variant="outline" class="size-16 text-indigo-300" />
                </div>
                <div class="text-lg text-indigo-100 leading-relaxed">
                    Thanks for considering <strong class="text-white">{{ $this->vendor?->name ?? 'us' }}</strong> for your project.
                </div>
                <div class="text-lg text-indigo-100 leading-relaxed mt-4">
                    Share a few times that work for you and we'll <strong class="text-white">confirm one by email</strong> —
                    no phone tag required.
                </div>
                <div class="text-lg text-indigo-100 leading-relaxed mt-4">
                    At the consultation we'll walk the space, talk through what you have in mind, and follow up with a clear estimate.
                </div>
            </div>
        </div>
    </div>
</div>
