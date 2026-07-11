{{-- Scanned check image for the entered bank + check number (HandlesChecks::matchingCheckImage).
     Click opens the image in the page-level lightbox modal
     (livewire.checks._check_image_lightbox — included OUTSIDE any @island so
     island morphs never abort the dialog's transition). --}}
@if($this->matchingCheckImage)
    <div class="pt-1" wire:transition.opacity.duration.300ms>
        <flux:modal.trigger name="check-image-lightbox">
            <img
                src="{{ route('expenses.original_receipt', ['checks', $this->matchingCheckImage->image_filename]) }}"
                alt="Check {{ $this->matchingCheckImage->check_number }}"
                class="w-full cursor-zoom-in rounded-lg border border-zinc-200 bg-white dark:border-zinc-700"
                loading="lazy"
                x-data
                @click="$dispatch('check-lightbox', { src: $el.src, alt: $el.alt })"
            />
        </flux:modal.trigger>

        @if($this->matchingCheckImage->resolved_payee_name)
            <flux:description class="mt-1"><i>Check payee: {{ $this->matchingCheckImage->resolved_payee_name }}</i></flux:description>
        @endif
    </div>
@endif
