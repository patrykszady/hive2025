<div class="max-w-lg">
    <x-island-card :heading="$view_text['card_title']" class="space-y-4">
        </div>

        <flux:separator variant="subtle" />

        <form wire:submit="{{$view_text['form_submit']}}" class="space-y-6">
            <flux:select 
                wire:model.live.debounce.250ms="user_id"
                label="Select User"
                variant="listbox" 
                placeholder="Select User to Login As..."
                variant="combobox"
            >
                <x-slot name="search">
                    <flux:select.search placeholder="Search users..." />
                </x-slot>
                
                @foreach ($users as $user)
                    <flux:select.option value="{{$user->id}}">
                        {{ $user->full_name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end" x-show="$wire.user_id">
                <flux:button 
                    type="submit"
                    variant="primary"
                >
                    {{$view_text['button_text']}}
                </flux:button>
            </div>
        </form>
    </x-island-card>
</div>
