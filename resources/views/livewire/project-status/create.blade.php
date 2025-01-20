{{-- PROJECT LIFESPAN / STATUS --}}
<flux:card class="space-y-6">
    <flux:accordion transition>
        <flux:accordion.item expanded>
            <flux:accordion.heading>
                <flux:heading size="lg" class="mb-0">Project Lifespan</flux:heading>
            </flux:accordion.heading>

            <flux:accordion.content>
                <ul role="list" class="space-y-6 mt-6">
                    {{-- 2nd to last gets CHECKMARK (Could be first (estimate) ) --}}
                    @foreach($statuses as $status)
                        <li class="relative flex gap-x-4">
                            <div class="absolute top-0 left-0 flex justify-center w-6 -bottom-6">
                                <div class="w-px bg-gray-200"></div>

                            </div>
                            <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                                @if($loop->last)
                                    <flux:icon.check-circle class="text-sky-500 dark:text-sky-300" />
                                @else
                                    <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                @endif
                            </div>
                            <p class="flex-auto py-0.5 text-sm leading-5 text-gray-500">
                                <span class="font-medium text-gray-900">
                                    {{$status->title}}
                                </span>
                                {{$status->start_date->format('m/d/y')}}
                                {{-- <br>
                                <span class="mb-0 ml-4 text-indigo-800"><i>2 months later</i></span> --}}
                            </p>
                            <time datetime="{{$status->start_date}}" class="flex-none py-0.5 text-xs leading-5 text-gray-500">{{$status->start_date->diffForHumans()}}</time>
                        </li>
                    @endforeach

                    {{-- <li class="relative flex gap-x-4">
                        <div class="absolute top-0 left-0 flex justify-center w-6 -bottom-5">
                            <div class="w-px bg-gray-200"></div>
                        </div>
                        <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                            <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                        </div>
                        <p class="flex-auto py-0.5 text-xs leading-5 text-gray-500"><span class="font-medium text-gray-900">Alex
                                Curren</span> viewed the invoice.</p>
                        <time datetime="2023-01-24T09:12" class="flex-none py-0.5 text-xs leading-5 text-gray-500">2d ago</time>
                    </li>

                    <li class="relative flex gap-x-4">
                        <div class="absolute top-0 left-0 flex justify-center w-6 h-6">
                            <div class="w-px bg-gray-200"></div>
                        </div>
                        <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                            <svg class="w-6 h-6 text-indigo-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z"
                                    clip-rule="evenodd" />
                            </svg>
                        </div>
                        <p class="flex-auto py-0.5 text-xs leading-5 text-gray-500"><span class="font-medium text-gray-900">
                            Alex Curren</span> paid the invoice.</p>
                        <time datetime="2023-01-24T09:20" class="flex-none py-0.5 text-xs leading-5 text-gray-500">1d ago</time>
                    </li> --}}

                    {{-- <flux:separator variant="subtle"/> --}}

                    <li class="relative flex gap-x-4">
                        <div class="absolute left-0 flex justify-center w-6 bottom-5 -top-5">
                            <div class="w-px bg-gray-200"></div>
                        </div>
                        <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                            <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                        </div>

                        {{-- <p class="flex-auto py-0.5 text-sm leading-5 text-gray-500">
                            <span class="font-medium text-gray-900">Update Project Status</span>
                        </p> --}}

                        <div class="flex max-w-lg -mt-1 rounded-md shadow-sm">
                            <flux:input.group>
                                <flux:input wire:model.live="project_status_date" type="date" max="2999-12-31" placeholder="2023-12-31"/>

                                <flux:select wire:model.live="project_status" id="new_project_id" variant="listbox" class="max-w-fit" placeholder="Choose Status...">
                                    @include('livewire.projects._status_options')
                                </flux:select>

                                <flux:button
                                    wire:click="update_project"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-50"
                                    icon="arrow-uturn-right"
                                    >
                                    Change
                                </flux:button>
                            </flux:input.group>
                        </div>
                    </li>
                    <x-forms.error errorName="project_status"/>
                </ul>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
</flux:card>
