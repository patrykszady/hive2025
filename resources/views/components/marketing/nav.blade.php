@props(['active' => null])

@php
    $contractorPages = [
        ['route' => 'welcome.finances', 'label' => __('marketing.nav.pages.finances'), 'icon' => 'credit-card', 'key' => 'finances'],
        ['route' => 'welcome.estimates', 'label' => __('marketing.nav.pages.estimates'), 'icon' => 'document-text', 'key' => 'estimates'],
        ['route' => 'welcome.clients', 'label' => __('marketing.nav.pages.clients'), 'icon' => 'users', 'key' => 'clients'],
        ['route' => 'welcome.vendors', 'label' => __('marketing.nav.pages.vendors'), 'icon' => 'user-group', 'key' => 'vendors'],
        ['route' => 'welcome.planning', 'label' => __('marketing.nav.pages.planning'), 'icon' => 'calendar', 'key' => 'planning'],
        ['route' => 'welcome.team', 'label' => __('marketing.nav.pages.team'), 'icon' => 'clock', 'key' => 'team'],
        ['route' => 'welcome.communication', 'label' => __('marketing.nav.pages.communication'), 'icon' => 'chat-bubble-left-right', 'key' => 'communication'],
        ['route' => 'welcome.photos', 'label' => __('marketing.nav.pages.photos'), 'icon' => 'camera', 'key' => 'photos'],
        ['route' => 'welcome.automation', 'label' => __('marketing.nav.pages.automation'), 'icon' => 'sparkles', 'key' => 'automation'],
    ];
    $contractorActive = collect($contractorPages)->contains('key', $active);
@endphp

<flux:header sticky container class="!bg-white/70 dark:!bg-zinc-900/90 !backdrop-blur border-b border-zinc-200/80 dark:border-zinc-700/80 !py-2 lg:!py-1 !items-center !top-0 !z-50">
    <div class="flex items-center gap-2 flex-1">
        <flux:brand
            href="{{ route('welcome') }}"
            name="Hive Contractors"
            wire:navigate.hover
        >
            <x-slot name="logo" class="!bg-transparent !p-0 !overflow-visible !rounded-none !h-auto !min-w-0">
                <x-hive-logo class="size-6" />
            </x-slot>
        </flux:brand>
    </div>

    <div class="flex-1 justify-center max-lg:hidden flex">
        <flux:navbar class="-mb-px">
            <flux:dropdown position="bottom" align="center">
                <flux:navbar.item icon:trailing="chevron-down" :current="$contractorActive">
                    {{ __('marketing.nav.contractors') }}
                </flux:navbar.item>
                <flux:navmenu>
                    @foreach ($contractorPages as $page)
                        <flux:navmenu.item
                            href="{{ route($page['route']) }}"
                            icon="{{ $page['icon'] }}"
                            :current="$active === $page['key']"
                            wire:navigate.hover
                        >{{ $page['label'] }}</flux:navmenu.item>
                    @endforeach
                </flux:navmenu>
            </flux:dropdown>
            <flux:navbar.item
                href="{{ route('welcome.homeowners') }}"
                :current="$active === 'homeowners'"
                wire:navigate.hover
            >{{ __('marketing.nav.homeowners') }}</flux:navbar.item>
            <flux:navbar.item
                href="{{ route('welcome.faq') }}"
                :current="$active === 'faq'"
                wire:navigate.hover
            >{{ __('marketing.nav.faq') }}</flux:navbar.item>
        </flux:navbar>
    </div>

    <div class="flex items-center gap-2 flex-1 justify-end">
        <x-marketing.language-switcher />

        <flux:button
            href="{{ route('login') }}"
            class="max-lg:hidden !px-3 !py-2 text-sm font-semibold border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600"
            wire:navigate.hover
        >
            {{ __('marketing.nav.sign_in') }}
        </flux:button>
        <flux:button href="{{ route('registration') }}" class="!bg-indigo-600 hover:!bg-indigo-500 !text-white font-semibold !px-3 !py-2 text-sm" wire:navigate.hover>{{ __('marketing.nav.get_started') }}</flux:button>

        <flux:dropdown class="lg:hidden" position="bottom" align="end">
            <flux:button variant="ghost" icon="bars-2" aria-label="Menu" class="!px-2 !py-2" />
            <flux:navmenu>
                <flux:navmenu.item href="{{ route('welcome.homeowners') }}" icon="home-modern" :current="$active === 'homeowners'" wire:navigate.hover>
                    {{ __('marketing.nav.homeowners') }}
                </flux:navmenu.item>
                <flux:navmenu.separator />
                @foreach ($contractorPages as $page)
                    <flux:navmenu.item href="{{ route($page['route']) }}" icon="{{ $page['icon'] }}" :current="$active === $page['key']" wire:navigate.hover>
                        {{ $page['label'] }}
                    </flux:navmenu.item>
                @endforeach
                <flux:navmenu.item href="{{ route('welcome.faq') }}" icon="question-mark-circle" wire:navigate.hover>{{ __('marketing.nav.faq') }}</flux:navmenu.item>
                <flux:navmenu.separator />
                <flux:navmenu.item href="{{ route('login') }}" icon="arrow-right-end-on-rectangle" wire:navigate.hover>{{ __('marketing.nav.sign_in') }}</flux:navmenu.item>
                <flux:navmenu.item href="{{ route('registration') }}" icon="sparkles" wire:navigate.hover>{{ __('marketing.nav.get_started') }}</flux:navmenu.item>
            </flux:navmenu>
        </flux:dropdown>
    </div>
</flux:header>
