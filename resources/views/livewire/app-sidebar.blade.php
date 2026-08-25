<div class="flex-1 min-h-0 overflow-y-auto flux-no-scrollbar" data-sidebar-scroll>
    <flux:sidebar.nav>
            {{-- Notifications (both client and non-client users) --}}
            <flux:sidebar.item wire:navigate.hover icon="bell" href="{{ route('notifications.index') }}" tooltip="Notifications" class="[&_[data-content]]:!overflow-visible">
                <span class="inline-flex items-center gap-2">
                    <span>Notifications</span>
                    <livewire:notifications.notification-sidebar-badge />
                </span>
            </flux:sidebar.item>

            @if($isClientUser)
                <flux:sidebar.item wire:navigate.hover icon="home" href="{{ $clientHome }}">Home</flux:sidebar.item>
                <flux:sidebar.item wire:navigate.hover icon="folder" href="/projects">Projects</flux:sidebar.item>
                <flux:sidebar.item wire:navigate.hover icon="chat-bubble-left-right" href="{{ route('sms.index') }}" tooltip="Messages" class="[&_[data-content]]:!overflow-visible">
                    <span class="inline-flex items-center gap-2">
                        <span>Messages</span>
                        <livewire:sms.sms-sidebar-badge />
                    </span>
                </flux:sidebar.item>
            @else
                {{-- BANK ERRORS --}}
                @if($canViewBanks && $hasBankErrors)
                    <flux:sidebar.item wire:navigate.hover icon="building-library" href="/banks" badge="Error" badge:color="red">Banks</flux:sidebar.item>
                @endif

                {{-- MENARDS SIGN-IN / CHALLENGE NEEDED --}}
                @if($canViewBanks && $menardsNeedsLogin)
                    <flux:sidebar.item wire:navigate.hover icon="computer-desktop" href="{{ route('menards.browser') }}" badge="Sign-in" badge:color="red">Menards</flux:sidebar.item>
                @endif

                @if($canViewBanks && ($hasBankErrors || $menardsNeedsLogin))
                    <flux:separator class="my-2" />
                @endif

                {{-- NAVIGATION --}}
                <flux:sidebar.item wire:navigate.hover icon="home" href="/hub">Home</flux:sidebar.item>

                @if($isAdmin && $primaryVendorId === 1)
                    <flux:sidebar.item wire:navigate.hover icon="chat-bubble-left-right" href="{{ route('sms.index') }}" tooltip="Messages" class="[&_[data-content]]:!overflow-visible">
                        <span class="inline-flex items-center gap-2">
                            <span>Messages</span>
                            <livewire:sms.sms-sidebar-badge />
                        </span>
                    </flux:sidebar.item>
                @endif

                @if($canViewLeads)
                    <flux:sidebar.item wire:navigate.hover icon="magnifying-glass-plus" href="/leads" tooltip="Leads" class="[&_[data-content]]:!overflow-visible">
                        <span class="inline-flex items-center gap-2">
                            <span>Leads</span>
                            <livewire:leads.leads-sidebar-badge />
                        </span>
                    </flux:sidebar.item>
                @endif

                <flux:sidebar.item wire:navigate.hover icon="folder" href="/projects">Projects</flux:sidebar.item>
                <flux:sidebar.item wire:navigate.hover icon="calendar" href="{{ route('planner.cards') }}">Planner</flux:sidebar.item>

                @if($canViewExpenses)
                    <flux:sidebar.group expandable heading="Finances" icon="credit-card" class="grid" :expanded="true">
                        <flux:sidebar.item wire:navigate.hover href="/expenses" icon="credit-card">Expenses</flux:sidebar.item>
                        @if($canViewPayments)
                            <flux:sidebar.item wire:navigate.hover href="/payments" icon="banknotes">Payments</flux:sidebar.item>
                        @endif
                        @if($canViewChecks)
                            <flux:sidebar.item wire:navigate.hover href="/checks" icon="pencil-square">Checks</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                <flux:sidebar.item wire:navigate.hover icon="user-group" href="/vendors">Vendors</flux:sidebar.item>

                @if($canViewClients)
                    <flux:sidebar.item wire:navigate.hover icon="users" href="/clients">Clients</flux:sidebar.item>
                @endif

                @if($canViewTimesheetsGroup)
                    <flux:sidebar.group expandable heading="Timesheets" class="grid" icon="clock" :expanded="true">
                        @if($canCreateHours)
                            <flux:sidebar.item wire:navigate.hover href="/hours/create" icon="clock">Hours</flux:sidebar.item>
                        @endif
                        @if($canViewTimesheets)
                            <flux:sidebar.item wire:navigate.hover href="/timesheets" icon="document-currency-dollar">Timesheets</flux:sidebar.item>
                        @endif
                        @if($canViewTimesheetPayments)
                            <flux:sidebar.item wire:navigate.hover href="/timesheets/payments" icon="currency-dollar">Payments</flux:sidebar.item>
                        @elseif($canViewOwnTimesheetPayment)
                            <flux:sidebar.item wire:navigate.hover href="/timesheets/payment/{{ $userId }}" icon="currency-dollar">Balance</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                @if($canViewBanks)
                    <flux:sidebar.group expandable heading="Accounting" class="grid" icon="document-currency-dollar" :expanded="$accountingExpanded">
                        @if($hasBankErrors)
                            <flux:sidebar.item wire:navigate.hover href="/banks" icon="building-library" badge="Error" badge:color="red">Banks</flux:sidebar.item>
                        @else
                            <flux:sidebar.item wire:navigate.hover href="/banks" icon="building-library">Banks</flux:sidebar.item>
                        @endif
                        <flux:sidebar.item wire:navigate.hover href="/distributions" icon="receipt-percent">Distributions</flux:sidebar.item>
                        <flux:sidebar.item wire:navigate.hover href="/sheets" icon="document-currency-dollar">Sheets</flux:sidebar.item>
                        @if($canViewLienWaivers)
                            <flux:sidebar.item wire:navigate.hover href="/lien-waivers" icon="document-check">Lien Waivers</flux:sidebar.item>
                        @endif
                        <flux:sidebar.item wire:navigate.hover href="/vendors/categories" icon="tag">Categories</flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if($hasSettingsAccess)
                    <flux:sidebar.group expandable heading="Settings" class="grid" icon="cog-6-tooth" :expanded="$settingsExpanded">
                        @if($canViewOptions)
                            <flux:sidebar.item wire:navigate.hover href="/options" icon="adjustments-horizontal">Options</flux:sidebar.item>
                        @endif
                        @if($canViewTemplates)
                            <flux:sidebar.item wire:navigate.hover href="/templates" icon="envelope-open">Templates</flux:sidebar.item>
                        @endif
                        @if($canViewCompanyEmails)
                            <flux:sidebar.item wire:navigate.hover href="/company_emails" icon="inbox-stack">Email Accounts</flux:sidebar.item>
                            <flux:sidebar.item wire:navigate.hover href="/vendor_match" icon="receipt-percent">Vendor Match</flux:sidebar.item>
                        @endif
                        @if($canViewVendorDocs)
                            <flux:sidebar.item wire:navigate.hover href="/vendor_docs" icon="eye-slash">Vendor Documents</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                @if($canAdminLogin)
                    <flux:sidebar.group expandable heading="Global Actions" class="grid" icon="eye-slash" :expanded="$globalActionsExpanded">
                        <flux:sidebar.item wire:navigate.hover href="/transactions/match_vendor" icon="arrows-right-left">Match Vendor</flux:sidebar.item>
                        @if(auth()->id() === 1)
                            <flux:sidebar.item wire:navigate.hover href="/agents" icon="identification">Agents</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif
            @endif
        </flux:sidebar.nav>
</div>
