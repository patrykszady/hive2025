{{-- Page-level lightbox for check images. Include ONCE per page, outside any
     @island (island morphs would tear the dialog out mid-transition and throw
     "AbortError: Transition was skipped"). The image src arrives via the
     `check-lightbox` event dispatched by livewire.checks._check_image_preview. --}}
<div x-data="{ src: null, alt: '' }" @check-lightbox.window="src = $event.detail.src; alt = $event.detail.alt ?? ''">
    <flux:modal name="check-image-lightbox" class="w-full max-w-5xl">
        <template x-if="src">
            <img :src="src" :alt="alt" class="w-full rounded-lg bg-white" />
        </template>
    </flux:modal>
</div>
