<x-modals.modal>
    @if(isset($project))
        <form wire:submit="{{$view_text['form_submit']}}"> 
            <x-cards.heading>
                <x-slot name="left">
                    <h1><b>Distributions</b> | {!! $project->name !!}</h1>
                </x-slot>

                <x-slot name="right">
                    <x-cards.button 
                        href="{{route('projects.show', $project->id)}}"
                        hrefTarget='_blank'
                        >
                        View Project
                    </x-cards.button>
                </x-slot>
            </x-cards.heading>

            <x-cards.body>
                <x-cards.heading>
                    <x-slot name="left">
                        <h1>Project Balance: {{money($project->finances['balance'])}}</h1>
                        <h1>Project Profit: <b>{{money($project->finances['profit'])}}</b></h1>
                    </x-slot>       
                </x-cards.heading>
                <br>
                <x-cards.body>
                    @foreach ($distributions as $index => $distribution)
                        {{-- ROWS --}}
                        <div class="space-y-2 mt-2">
                            <x-forms.row 
                                wire:model.live.debounce.500ms="distributions.{{ $index }}.percent" 
                                errorName="distributions.{{ $index }}.percent"
                                name="distributions.{{ $index }}.percent"
                                text="{{$distribution->name}}" 
                                type="number" 
                                hint="%" 
                                textSize="xl"
                                placeholder="25" 
                                inputmode="numeric" 
                                step="5"
                                {{-- radioHint="{{$loop->first ? '' : 'Remove'}}" --}}
                                >
                            </x-forms.row>
                            <x-forms.row 
                                wire:model.live.debounce.500ms="distributions.{{ $index }}.percent_amount" 
                                errorName="distributions.{{ $index }}.percent_amount"
                                name="distributions.{{ $index }}.percent_amount"
                                text=""
                                hint="$"                 
                                disabled
                                >
                            </x-forms.row>
                        </div>
                    @endforeach
                    <br>
                </x-cards.body>
            </x-cards.body>

            <x-cards.footer>
                <button 
                    wire:click="$dispatch('resetModal')"
                    type="button"
                    x-on:click="open = false"
                    class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    > 
                    Cancel
                </button>

                <a
                    type="button"
                    class="text-center focus:outline-none rounded-md border-2 border-indigo-600 py-2 px-4 text-lg font-medium text-gray-900 shadow-sm">
                    <b>{{$this->percent_sum}}</b>%
                </a>    

                <button 
                    type="submit"
                    {{-- x-on:click="open = false" --}}
                    {{-- x-bind:disabled="expense.project_id" --}}
                    class="ml-3 inline-flex justify-center disabled:opacity-50 py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                    {{$view_text['button_text']}}
                </button>

                @if($errors->has('percent_distributions_sum')) 
                    <x-slot name="bottom">
                        <x-forms.error errorName="percent_distributions_sum" />              
                    </x-slot>
                @endif     
            </x-cards.footer> 
        </form>
    @endif
</x-modals.modal>
