<div class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-xl lg:px-8 pb-5 mb-1 space-y-6' : ''}}">
    {{-- COMPANY EMAILS --}}
    <flux:card>
        <div class="flex justify-between">
            <flux:heading size="lg">Company Email Accounts</flux:heading>
            <flux:button
                x-on:click="window.openNylasPopup('{{route('company-email.login')}}')"
                size="sm"
                >
                Add Email Account
            </flux:button>
        </div>
        <flux:subheading>Email accounts you use to recieve digital receipts from vendors.</flux:subheading>
        <flux:separator variant="subtle" />

        {{-- DETAILS --}}
        {{-- 03-13-2025 should be a flux table without header row --}}
        <x-lists.details_list>
            {{-- @can('update', $project) --}}
                @foreach($email_accounts as $email)
                {{--:bubble_message="isset($email->api_json['errors']) ? 'Disconnected' : 'Connected'"
                    :bubble_color="isset($email->api_json['errors']) ? 'red' : 'green'" --}}
                    {{-- 11/23/2024 NEED FLUX BADGE HERE --}}
                    <x-lists.details_item title="{{$email->email}}" detail="{{$email->status}}" />
                @endforeach
                        {{-- @if($project->belongs_to_vendor_id == auth()->user()->vendor->id)
                    <x-lists.search_li
                        :basic=true
                        :line_title="'Invite Contractors'"
                        :line_data="'Choose Vendors'"
                        :button_wire="TRUE"
                        wire:click="$dispatchTo('projects.project-vendors', 'addVendors')"
                        >
                    </x-lists.search_li>

                    <livewire:projects.project-vendors :project="$project"/>
                @endif --}}
            {{-- @endcan --}}
        </x-lists.details_list>
    </flux:card>

    @if(request()->routeIs('company_emails.index'))
        <livewire:receipt-accounts.receipt-accounts-index />
    @endif
</div>

<script>
window.openNylasPopup = function(url) {
    const width = 600;
    const height = 700;
    const left = (screen.width / 2) - (width / 2);
    const top = (screen.height / 2) - (height / 2);
    
    window.nylasPopup = window.open(
        url,
        'nylasAuth',
        `width=${width},height=${height},top=${top},left=${left},toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes`
    );
};

window.addEventListener('message', function(event) {
    if (event.data.type === 'nylas-auth-success') {
        if (window.nylasPopup && !window.nylasPopup.closed) {
            window.nylasPopup.close();
        }
        window.location.reload();
    }
});
</script>
