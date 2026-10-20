<div class="max-w-4xl">
    <x-index-table
        heading="Unplaced Email"
        subheading="Emails with clients and vendors that the AI couldn't match to a project. Put each on its project, or dismiss it."
        :paginator="$this->emails"
    >
        <x-slot:before>
            <flux:modal wire:model.self="showEmailModal" class="max-w-2xl">
                @if ($showEmailModal && $this->selectedEmail)
                    @php $email = $this->selectedEmail; @endphp
                    <div class="space-y-6">
                        @include('livewire.emails.partials.email-detail', [
                            'email' => $email,
                            'latestPart' => $this->selectedEmailLatestPart,
                            'quotedPart' => $this->selectedEmailQuotedPart,
                        ])

                        <div class="space-y-3 border-t border-zinc-200 dark:border-zinc-700 pt-4">
                            <flux:heading size="sm">Which project is this about?</flux:heading>

                            @if ($this->candidates->isNotEmpty())
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($this->candidates as $candidate)
                                        <flux:button
                                            wire:key="candidate-{{ $candidate->id }}"
                                            size="sm"
                                            wire:click="assign({{ $candidate->id }})"
                                        >
                                            {{ $candidate->project_name ?: 'Untitled project' }}@if ($candidate->address) · {{ $candidate->address }}@endif
                                        </flux:button>
                                    @endforeach
                                </div>
                            @endif

                            <div class="flex items-end gap-2">
                                <flux:select
                                    wire:model="otherProjectId"
                                    variant="listbox"
                                    searchable
                                    placeholder="Another open project…"
                                    label="Another project"
                                    class="flex-1"
                                >
                                    @foreach ($this->openProjects as $project)
                                        <flux:select.option wire:key="open-project-{{ $project->id }}" value="{{ $project->id }}">
                                            {{ $project->project_name ?: 'Untitled project' }}@if ($project->address) · {{ $project->address }}@endif
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button variant="primary" wire:click="assignOther">Assign</flux:button>
                            </div>
                            <flux:error name="otherProjectId" />
                            <flux:error name="project_id" />

                            <div class="flex justify-end">
                                <flux:button variant="ghost" size="sm" icon="archive-box-x-mark" wire:click="dismiss">
                                    Not about a project
                                </flux:button>
                            </div>
                        </div>
                    </div>
                @endif
            </flux:modal>
        </x-slot:before>

        <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
            <flux:table.columns>
                <flux:table.column class="w-[60%] min-w-0">Email</flux:table.column>
                <flux:table.column class="w-[20%]">Sent</flux:table.column>
                <flux:table.column class="w-[20%]"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->emails as $email)
                    <flux:table.row :key="'unplaced-'.$email->id">
                        <flux:table.cell class="min-w-0">
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <flux:icon.envelope variant="micro" class="size-4 shrink-0 text-zinc-400" />
                                    <span class="font-medium text-zinc-800 dark:text-white truncate">
                                        {{ $email->isInbound() ? ($email->from_name ?: $email->from_email) : 'To '.implode(', ', (array) $email->to) }}
                                    </span>
                                    @if (! empty($email->attachments))
                                        <flux:badge size="sm" inset="top bottom" icon="paper-clip">{{ count((array) $email->attachments) }}</flux:badge>
                                    @endif
                                </div>
                                <div class="text-sm text-zinc-700 dark:text-zinc-200 truncate">{{ $email->subject ?: '(no subject)' }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2 whitespace-normal">{{ $email->summary ?: $email->snippet }}</div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                            {{ $email->sent_at->copy()->setTimezone('America/Chicago')->format('M j, g:i A') }}
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button size="sm" wire:click="open({{ $email->id }})">Sort</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="text-center text-sm text-zinc-500 dark:text-zinc-400 py-6">
                            Nothing to sort — every email is on a project.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-index-table>
</div>
