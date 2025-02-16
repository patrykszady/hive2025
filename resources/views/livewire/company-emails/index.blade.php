<div class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-xl lg:px-8 pb-5 mb-1 space-y-6' : ''}}">
    {{-- COMPANY EMAILS --}}
    <flux:card>
        <div class="flex justify-between">
            <flux:heading size="lg">Company Email Accounts</flux:heading>
            <flux:button
                {{-- wire:click="$dispatchTo('projects.project-create', 'editProject', { project: {{$project->id}}})" --}}
                size="sm"
                >
                Add Email Account
            </flux:button>
        </div>
        <flux:subheading>Email accounts you use to recieve digital receipts from merchants.</flux:subheading>

        <flux:separator variant="subtle" />
        {{-- <a href="{{route('nylas_login')}}" type="button"
            class="inline-flex justify-center px-4 py-2 text-sm text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
            Add Email Account
        </a>
        <a href="{{route('ms_graph_login')}}" type="button"
            class="inline-flex justify-center px-4 py-2 text-sm text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
            Add Microsoft Email
        </a>
        <a href="{{route('google_cloud_login')}}" type="button"
            class="inline-flex justify-center px-4 py-2 text-sm text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
            Add Google Email
        </a> --}}

        {{-- DETAILS --}}
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
