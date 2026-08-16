@section('title', __('Hive Contractors — The CRM built for small contractors'))
<x-guest-layout>
    <x-marketing.nav />

    {{-- ============================ HERO ============================ --}}
    <div class="relative overflow-hidden bg-white dark:bg-zinc-950 isolate">
        <div class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80" aria-hidden="true">
            <div class="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-indigo-300 to-indigo-600 opacity-20 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]"></div>
        </div>
        <div class="px-6 pt-10 pb-24 mx-auto max-w-7xl sm:pb-32 lg:flex lg:px-8 lg:py-32">
            <div class="max-w-2xl mx-auto lg:mx-0 lg:max-w-xl lg:shrink-0 lg:pt-8">
                <a href="{{ route('registration') }}" wire:navigate.hover>
                    <x-hive-logo class="h-28 w-28" />
                </a>
                <div class="mt-16 sm:mt-20">
                    <a href="{{ route('registration') }}" class="inline-flex space-x-6" wire:navigate.hover>
                        <span class="px-3 py-1 text-sm font-semibold leading-6 text-indigo-600 rounded-full bg-indigo-600/10 ring-1 ring-inset ring-indigo-600/10 dark:text-indigo-400">
                            {{ __('Start Today') }}
                        </span>
                        <span class="inline-flex items-center space-x-2 text-sm font-medium leading-6 text-gray-600 dark:text-gray-400">
                            <span>{{ __('Made by Contractors. For Contractors.') }}</span>
                            <svg class="w-5 h-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </a>
                </div>
                <h1 class="mt-10 text-4xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-6xl">
                    {{ __('The CRM built for small contractors') }}
                </h1>
                <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                    {!! __('Hive Contractors brings your entire business under one roof. Automate your <strong class="font-semibold text-gray-900 dark:text-white">finances</strong>, <strong class="font-semibold text-gray-900 dark:text-white">communication</strong>, and <strong class="font-semibold text-gray-900 dark:text-white">project planning</strong>—plus vendor collaboration, homeowner updates, timesheets, COIs, receipts, and audits—so you can focus on building.') !!}
                </p>
                <div class="flex flex-wrap items-center mt-10 gap-x-6 gap-y-4">
                    <a href="{{ route('registration') }}" class="rounded-md bg-indigo-600 px-3.5 py-2.5 text-base font-semibold leading-7 text-white shadow-xs hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" wire:navigate.hover>
                        {{ __('Create your Hive') }}
                    </a>
                    <a href="{{ route('login') }}" class="text-base font-semibold leading-7 text-gray-900 dark:text-white" wire:navigate.hover>{{ __('Log in') }} <span aria-hidden="true">→</span></a>
                </div>
                <dl class="grid grid-cols-3 mt-12 gap-x-6 gap-y-4 max-w-lg">
                    <div>
                        <dt class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('1 roof') }}</dt>
                        <dd class="text-sm text-gray-500 dark:text-gray-400">{{ __('Every job, all in one place') }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('Auto') }}</dt>
                        <dd class="text-sm text-gray-500 dark:text-gray-400">{!! __('Receipts &amp; books on autopilot') !!}</dd>
                    </div>
                    <div>
                        <dt class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('Real-time') }}</dt>
                        <dd class="text-sm text-gray-500 dark:text-gray-400">{!! __('Clients &amp; crews stay in sync') !!}</dd>
                    </div>
                </dl>
            </div>
            <div class="flex max-w-2xl mx-auto mt-16 sm:mt-24 lg:ml-10 lg:mr-0 lg:mt-0 lg:max-w-none lg:flex-none xl:ml-32">
                <div class="flex-none max-w-3xl sm:max-w-5xl lg:max-w-none">
                    {{-- ↓ Hero mockup: live dashboard --}}
                    <div class="p-2 -m-2 rounded-xl bg-gray-900/5 dark:bg-white/5 ring-1 ring-inset ring-gray-900/10 dark:ring-white/10 lg:-m-4 lg:rounded-2xl lg:p-4">
                        <div class="w-[56rem] rounded-md shadow-2xl ring-1 ring-gray-900/10 bg-white dark:bg-zinc-800 overflow-hidden text-left">
                            {{-- Sidebar + main --}}
                            <div class="flex" style="min-height:340px">
                                {{-- Sidebar --}}
                                <div class="w-44 shrink-0 border-r border-zinc-100 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 py-4 flex flex-col gap-1 px-3">
                                    @foreach([
                                        ['credit-card', __('Finances'), true],
                                        ['document-text', __('Estimates'), false],
                                        ['users', __('Clients'), false],
                                        ['calendar-date-range', __('Planning'), false],
                                        ['chat-bubble-left-right', __('Messages'), false],
                                    ] as [$icon,$label,$active])
                                    <div class="flex items-center gap-2 px-2 py-1.5 rounded-lg {{ $active ? 'bg-indigo-600 text-white' : 'text-zinc-500 dark:text-zinc-400' }} text-xs font-medium">
                                        <flux:icon name="{{ $icon }}" class="w-4 h-4" />
                                        <span>{{ $label }}</span>
                                    </div>
                                    @endforeach
                                </div>
                                {{-- Main content --}}
                                <div class="flex-1 flex flex-col">
                                    <div class="px-5 py-3 border-b border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Transactions') }}</span>
                                        <span class="text-xs bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400 rounded-full px-2 py-0.5 font-medium">{{ __('✓ 3 matched today') }}</span>
                                    </div>
                                    {{-- Stat row --}}
                                    <div class="grid grid-cols-3 gap-3 px-5 pt-3">
                                        @foreach([
                                            [__('Job costs'), '$14,280', 'text-zinc-900 dark:text-zinc-100'],
                                            [__('Unmatched'), __('2 receipts'), 'text-amber-600 dark:text-amber-400'],
                                            [__('This month'), '+$6,200', 'text-green-600 dark:text-green-400'],
                                        ] as [$lbl,$val,$cls])
                                        <div class="rounded-lg bg-zinc-50 dark:bg-zinc-700/40 border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                                            <div class="text-xs text-zinc-500">{{ $lbl }}</div>
                                            <div class="mt-0.5 text-sm font-bold {{ $cls }}">{{ $val }}</div>
                                        </div>
                                        @endforeach
                                    </div>
                                    {{-- Transaction rows --}}
                                    <div class="px-5 pt-3 space-y-1.5 pb-3">
                                        @foreach([
                                            ['Home Depot', __('Lumber — Unit A'), '$843.20', __('Kitchen Reno'), 'matched', 'Jun 27'],
                                            ['Ace Hardware', __('Plumbing supplies'), '$214.00', __('Bathroom #2'), 'matched', 'Jun 27'],
                                            ['Shell Gas', __('Fuel — two vehicles'), '$112.50', __('General'), 'matched', 'Jun 26'],
                                            ['Menards', __('Drywall &amp; fasteners'), '$389.00', __('Kitchen Reno'), 'unmatched', 'Jun 25'],
                                            ['Amazon', __('LED fixtures — 4× pack'), '$196.40', __('Bathroom #2'), 'unmatched', 'Jun 24'],
                                        ] as [$vendor,$desc,$amt,$proj,$status,$date])
                                        <div class="flex items-center gap-3 px-3 py-2 rounded-lg {{ $status === 'matched' ? 'bg-white dark:bg-zinc-800' : 'bg-amber-50 dark:bg-amber-900/10' }} border {{ $status === 'matched' ? 'border-zinc-100 dark:border-zinc-700' : 'border-amber-200 dark:border-amber-800/50' }}">
                                            <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 {{ $status === 'matched' ? 'bg-green-100 dark:bg-green-900/30' : 'bg-amber-100 dark:bg-amber-900/30' }}">
                                                <flux:icon name="{{ $status === 'matched' ? 'check' : 'receipt-percent' }}" class="w-3.5 h-3.5 {{ $status === 'matched' ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }}" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="text-xs font-semibold text-zinc-900 dark:text-zinc-100 truncate">{{ $vendor }}</div>
                                                <div class="text-xs text-zinc-400 truncate">{!! $desc !!}</div>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <div class="text-xs font-bold text-zinc-800 dark:text-zinc-200">{{ $amt }}</div>
                                                <div class="text-xs text-zinc-400">{{ $proj }}</div>
                                            </div>
                                            <div class="text-xs text-zinc-300 dark:text-zinc-600 shrink-0">{{ $date }}</div>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================ INTEGRATIONS STRIP ============================ --}}
    <div class="bg-gray-50 dark:bg-zinc-900 border-y border-gray-200 dark:border-zinc-800">
        <div class="px-6 py-12 mx-auto max-w-7xl lg:px-8">
            <p class="text-center text-sm font-semibold leading-7 text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                {{ __('Connects with the tools you already use') }}
            </p>
            <div class="grid items-center grid-cols-2 mt-8 gap-y-8 gap-x-8 sm:grid-cols-3 lg:grid-cols-5">
                @php
                    $integrations = [
                        ['QuickBooks', __('Sync your books')],
                        [__('Bank feeds'), __('Live transactions')],
                        [__('Email'), __('Receipts &amp; tracking')],
                        [__('Text &amp; calls'), __('SMS, MMS, recording')],
                        [__('Maps'), __('Job-site routing')],
                    ];
                @endphp
                @foreach ($integrations as $integration)
                    <div class="flex flex-col items-center justify-center text-center">
                        <span class="text-lg font-bold text-gray-900 dark:text-white">{!! $integration[0] !!}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{!! $integration[1] !!}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ============================ OUTCOMES: HOW IT HELPS ============================ --}}
    <div class="py-24 bg-white dark:bg-zinc-950 sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto lg:text-center">
                <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">{{ __('Why contractors switch to Hive') }}</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl text-balance">
                    {{ __('Built to solve the headaches small contractors actually have') }}
                </p>
                <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                    {{ __('You did not start your business to do data entry at midnight. Hive takes the office work off your plate so you can run more jobs with the same crew and collaborate with homeowners and other jobsite contractors.') }}
                </p>
            </div>
            <div class="grid max-w-2xl grid-cols-1 mx-auto mt-16 gap-6 sm:mt-20 lg:max-w-none lg:grid-cols-3">
                @php
                    $outcomes = [
                        ['icon' => 'moon', 'title' => __('Get your evenings back'), 'body' => __('Receipts, transactions, and books reconcile automatically—so the paperwork is done before you get home.')],
                        ['icon' => 'magnifying-glass', 'title' => __('Never lose a receipt or detail'), 'body' => __('Every receipt, text, call, and document is captured and tied to the right job. Nothing slips through the cracks.')],
                        ['icon' => 'banknotes', 'title' => __('Get paid faster'), 'body' => __('Send professional estimates and invoices, collect e-signatures, and turn approved bids into jobs in a click.')],
                        ['icon' => 'shield-check', 'title' => __('Stay compliant'), 'body' => __('Track certificates of insurance, workers&rsquo; comp, and lien waivers so you are always covered at audit time.')],
                        ['icon' => 'face-smile', 'title' => __('Keep clients happy'), 'body' => __('Homeowners get a live portal and schedule updates, so they stop calling to ask &ldquo;when are you coming?&rdquo;')],
                        ['icon' => 'arrow-trending-up', 'title' => __('Grow without the chaos'), 'body' => __('Run more projects, more crews, and more vendors from one place—without adding office staff.')],
                    ];
                @endphp
                @foreach ($outcomes as $outcome)
                    <div class="flex flex-col p-8 bg-gray-50 dark:bg-zinc-900 rounded-2xl ring-1 ring-gray-200 dark:ring-zinc-800">
                        <div class="flex items-center justify-center w-12 h-12 bg-indigo-600 rounded-xl">
                            <flux:icon name="{{ $outcome['icon'] }}" class="w-6 h-6 text-white" />
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-gray-900 dark:text-white">{{ $outcome['title'] }}</h3>
                        <p class="mt-2 text-base leading-7 text-gray-600 dark:text-gray-400">{!! $outcome['body'] !!}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ============================ COMPLETE FEATURE DIRECTORY ============================ --}}
    <div class="py-24 bg-gray-100 dark:bg-zinc-900 sm:py-32 scroll-mt-24" id="features">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-3xl mx-auto lg:text-center">
                <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">{{ __('Everything under one roof') }}</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl text-balance">
                    {{ __('One platform for the entire job—from first lead to final payment') }}
                </p>
                <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                    {{ __('Stop juggling spreadsheets, group texts, sticky notes, and a shoebox of receipts. Here is everything Hive does for small contractors and subcontractors, organized by the part of the business it runs.') }}
                </p>
            </div>
            <div class="grid max-w-2xl grid-cols-1 mx-auto mt-16 gap-6 sm:mt-20 lg:max-w-none lg:grid-cols-3">
                @php
                    $featureCategories = [
                        [
                            'icon' => 'credit-card',
                            'title' => __('Finances &amp; bookkeeping'),
                            'route' => 'welcome.finances',
                            'tagline' => __('Clean books without an accountant on staff.'),
                            'items' => [
                                __('Expense tracking by project &amp; category'),
                                __('Automatic receipt capture (email-in &amp; photo)'),
                                __('OCR + AI line-item itemization'),
                                __('Live bank sync &amp; transaction matching'),
                                __('Payments in and out'),
                                __('Print &amp; log checks and deposits'),
                                __('Owner distributions &amp; draws'),
                                __('Balance sheet &amp; profit-and-loss'),
                                __('Client &amp; employee reimbursements'),
                                __('QuickBooks-ready books'),
                            ],
                        ],
                        [
                            'icon' => 'document-text',
                            'title' => __('Estimates, invoices &amp; docs'),
                            'route' => 'welcome.estimates',
                            'tagline' => __('Win the bid and get paid faster.'),
                            'items' => [
                                __('AI-assisted estimate building'),
                                __('Professional estimate &amp; invoice PDFs'),
                                __('Client e-signature on estimates'),
                                __('One-click bid-to-job conversion'),
                                __('Change orders'),
                                __('Bids &amp; proposals'),
                                __('Lien waivers with secure public signing'),
                            ],
                        ],
                        [
                            'icon' => 'users',
                            'title' => __('Leads &amp; clients'),
                            'route' => 'welcome.clients',
                            'tagline' => __('Turn inquiries into jobs and keep homeowners happy.'),
                            'items' => [
                                __('Lead capture &amp; pipeline tracking'),
                                __('Lead-to-client conversion with de-duplication'),
                                __('Client directory &amp; full job history'),
                                __('Homeowner portal with real-time status'),
                                __('Shared &ldquo;what&rsquo;s next&rdquo; schedule links'),
                            ],
                        ],
                        [
                            'icon' => 'user-group',
                            'title' => __('Vendors &amp; compliance'),
                            'route' => 'welcome.vendors',
                            'tagline' => __('Stay covered and collaborate with your subs.'),
                            'items' => [
                                __('Vendor directory &amp; profiles'),
                                __('Vendor-to-vendor bids &amp; payments'),
                                __('Certificates of insurance (COI) tracking'),
                                __('Workers&rsquo; comp verification'),
                                __('Insurance agent management'),
                                __('Automated audits &amp; document storage'),
                            ],
                        ],
                        [
                            'icon' => 'calendar',
                            'title' => __('Projects &amp; planning'),
                            'route' => 'welcome.planning',
                            'tagline' => __('Plan the job and keep the crew on track.'),
                            'items' => [
                                __('Project workspaces (scope, docs, costs, address)'),
                                __('Drag-and-drop Gantt timeline with dependencies'),
                                __('Kanban board view'),
                                __('Tasks, milestones, meetings &amp; reminders'),
                                __('Calendar sync for meetings'),
                                __('Crew scheduling &amp; assignments'),
                            ],
                        ],
                        [
                            'icon' => 'clock',
                            'title' => __('Team &amp; time'),
                            'route' => 'welcome.team',
                            'tagline' => __('Track hours and run payroll without spreadsheets.'),
                            'items' => [
                                __('Mobile time tracking by job'),
                                __('Timesheet review &amp; approval'),
                                __('Payroll &amp; timesheet payments'),
                                __('Per-worker running balances'),
                                __('Roles &amp; permissions for your team'),
                            ],
                        ],
                        [
                            'icon' => 'chat-bubble-left-right',
                            'title' => __('Communication'),
                            'route' => 'welcome.communication',
                            'tagline' => __('Every conversation in one place, on the record.'),
                            'items' => [
                                __('Group SMS &amp; MMS threads per job'),
                                __('Recorded &amp; transcribed calls'),
                                __('AI call summaries with action items'),
                                __('Built-in message translation'),
                                __('Connected email with mailbox rules'),
                                __('Email open tracking'),
                                __('Reusable message &amp; email templates'),
                            ],
                        ],
                        [
                            'icon' => 'camera',
                            'title' => __('Photos &amp; timelapses'),
                            'route' => 'welcome.photos',
                            'tagline' => __('Every job documented, start to finish.'),
                            'items' => [
                                __('Jobsite photos filed straight to the project'),
                                __('Timelapses built from repeat shots automatically'),
                                __('Frames aligned and color-matched for smooth playback'),
                                __('Faces blurred automatically for privacy'),
                                __('Full-resolution originals archived untouched'),
                                __('Photos &amp; timelapses shared with homeowners'),
                            ],
                        ],
                        [
                            'icon' => 'sparkles',
                            'title' => __('Automation &amp; AI'),
                            'route' => 'welcome.automation',
                            'tagline' => __('The busywork runs itself.'),
                            'items' => [
                                __('AI receipt reading &amp; itemization'),
                                __('Automatic vendor &amp; transaction matching'),
                                __('Retailer receipt scraping (Home Depot, Menards, Amazon &amp; more)'),
                                __('Turn a text into a scheduled task'),
                                __('Call transcription &amp; summaries'),
                                __('Address autocomplete &amp; job-site maps'),
                            ],
                        ],
                        [
                            'icon' => 'device-phone-mobile',
                            'title' => __('Mobile &amp; platform'),
                            'tagline' => __('Run the business from your truck.'),
                            'items' => [
                                __('Installable mobile app (PWA)'),
                                __('Push &amp; in-app notifications'),
                                __('Passwordless passkey sign-in'),
                                __('Multi-company account switching'),
                                __('Bank-grade security'),
                                __('Integrations: QuickBooks, Plaid, Telnyx, Nylas, Maps'),
                            ],
                        ],
                    ];
                @endphp
                @foreach ($featureCategories as $category)
                    <div class="flex flex-col p-8 bg-white dark:bg-zinc-950 rounded-2xl ring-1 ring-gray-200 dark:ring-zinc-800 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg shrink-0">
                                <flux:icon name="{{ $category['icon'] }}" class="w-6 h-6 text-white" />
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{!! $category['title'] !!}</h3>
                        </div>
                        <p class="mt-3 text-sm font-medium text-indigo-600 dark:text-indigo-400">{!! $category['tagline'] !!}</p>
                        <ul role="list" class="mt-4 space-y-2.5">
                            @foreach ($category['items'] as $item)
                                <li class="flex gap-2.5 text-sm leading-6 text-gray-600 dark:text-gray-400">
                                    <flux:icon name="check" variant="micro" class="w-4 h-4 mt-1 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                    <span>{!! $item !!}</span>
                                </li>
                            @endforeach
                        </ul>
                        @isset($category['route'])
                            <a href="{{ route($category['route']) }}" class="inline-flex items-center gap-1 mt-6 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500" wire:navigate.hover>
                                {{ __('Learn more') }} <span aria-hidden="true">→</span>
                            </a>
                        @endisset
                    </div>
                @endforeach
            </div>
            <div class="mt-12 text-center">
                <a href="{{ route('registration') }}" class="inline-flex rounded-md bg-indigo-600 px-3.5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500" wire:navigate.hover>
                    {{ __('Create your Hive') }}
                </a>
            </div>
        </div>
    </div>

    {{-- ============================ FINANCES SPOTLIGHT ============================ --}}
    <div class="py-24 overflow-hidden bg-gray-100 dark:bg-zinc-900 sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="grid max-w-2xl grid-cols-1 mx-auto gap-x-8 gap-y-16 sm:gap-y-20 lg:mx-0 lg:max-w-none lg:grid-cols-2">
                <div class="lg:ml-auto lg:pl-4 lg:pt-4">
                    <div class="lg:max-w-lg">
                        <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">{{ __('Finances') }}</h2>
                        <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">{{ __('Automation that pays for itself') }}</p>
                        <p class="mt-6 text-lg font-normal leading-8 text-gray-600 dark:text-gray-300">
                            {!! __('Receipts, transactions, payments, and payroll consolidate automatically. Your books stay clean and your balance sheet and profit &amp; loss are always one click away.') !!}
                        </p>
                        <dl class="max-w-xl mt-10 space-y-8 text-base leading-7 text-gray-600 dark:text-gray-400 lg:max-w-none">
                            @php
                                $financeBullets = [
                                    [__('Receipts on autopilot.'), __('Email and uploaded receipts are parsed, itemized, and linked to the matching bank transaction.')],
                                    [__('Transactions matched &amp; categorized.'), __('Bank feeds flow in and reconcile against expenses so categories are never a guessing game.')],
                                    [__('Payments, checks &amp; reimbursements.'), __('Record payments, print checks, and settle client and employee reimbursements with a click.')],
                                    [__('QuickBooks-ready books.'), __('Distributions, balance sheets, and P&amp;L stay in sync so tax time and audits are painless.')],
                                ];
                            @endphp
                            @foreach ($financeBullets as $bullet)
                                <div class="relative pl-9">
                                    <dt class="inline font-semibold text-gray-900 dark:text-white">
                                        <flux:icon name="check-circle" class="absolute w-5 h-5 text-indigo-600 dark:text-indigo-400 left-1 top-1" />
                                        {!! $bullet[0] !!}
                                    </dt>
                                    <dd class="inline font-normal"> {!! $bullet[1] !!}</dd>
                                </div>
                            @endforeach
                        </dl>
                        <div class="mt-8">
                            <a href="{{ route('welcome.finances') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500" wire:navigate.hover>
                                {{ __('Explore finances') }} <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="flex items-start justify-end lg:order-first">
                    <div class="flex-none max-w-3xl sm:max-w-5xl lg:max-w-none">
                        {{-- ↓ Finance spotlight mockup: receipt auto-match --}}
                    <div class="p-2 -m-2 rounded-xl bg-gray-900/5 dark:bg-white/5 ring-1 ring-inset ring-gray-900/10 dark:ring-white/10 lg:-m-4 lg:rounded-2xl lg:p-4">
                        <div class="w-[44rem] rounded-md shadow-2xl ring-1 ring-gray-900/10 bg-white dark:bg-zinc-800 overflow-hidden text-left">
                            <div class="px-5 py-4">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Receipt inbox') }}</span>
                                    <span class="text-xs text-zinc-400">{{ __('Forwarded from email · just now') }}</span>
                                </div>
                                {{-- Receipt card --}}
                                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-4">
                                    <div class="bg-zinc-50 dark:bg-zinc-700/50 px-4 py-3 flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                                                <flux:icon name="receipt-percent" class="w-4 h-4 text-orange-600 dark:text-orange-400" />
                                            </div>
                                            <div>
                                                <div class="text-xs font-bold text-zinc-900 dark:text-zinc-100">Home Depot</div>
                                                <div class="text-xs text-zinc-400">Jun 27, 2025 · #H-44821</div>
                                            </div>
                                        </div>
                                        <div class="text-sm font-bold text-zinc-900 dark:text-zinc-100">$843.20</div>
                                    </div>
                                    <div class="px-4 py-3 space-y-1.5">
                                        @foreach([__('2×4 lumber (40 pcs)') => '$320.00', __('Plywood sheets (12)') => '$264.00', __('Construction screws') => '$89.20', __('Sandpaper + misc') => '$170.00'] as $item => $price)
                                        <div class="flex justify-between text-xs">
                                            <span class="text-zinc-500">{{ $item }}</span>
                                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $price }}</span>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                                {{-- AI match result --}}
                                <div class="rounded-xl border border-green-200 dark:border-green-800/50 bg-green-50 dark:bg-green-900/10 px-4 py-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <flux:icon name="check-circle" class="w-4 h-4 text-green-600 dark:text-green-400" />
                                        <span class="text-xs font-semibold text-green-700 dark:text-green-400">{{ __('Automatically matched') }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                        <div class="text-zinc-500">{{ __('Bank transaction') }}</div>
                                        <div class="text-zinc-700 dark:text-zinc-300 font-medium">HOMEDEPOT $843.20</div>
                                        <div class="text-zinc-500">{{ __('Project') }}</div>
                                        <div class="text-indigo-600 dark:text-indigo-400 font-medium">{{ __('Kitchen Renovation') }}</div>
                                        <div class="text-zinc-500">{{ __('Category') }}</div>
                                        <div class="text-zinc-700 dark:text-zinc-300 font-medium">{{ __('Materials') }}</div>
                                    </div>
                                </div>
                                {{-- job cost bar --}}
                                <div class="mt-4">
                                    <div class="flex justify-between text-xs text-zinc-500 mb-1">
                                        <span>{{ __('Kitchen Renovation job cost') }}</span>
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">$14,280 of $22,000</span>
                                    </div>
                                    <div class="w-full rounded-full bg-zinc-100 dark:bg-zinc-700 h-2">
                                        <div class="h-2 rounded-full bg-indigo-500" style="width:65%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================ COMMUNICATION SPOTLIGHT ============================ --}}
    <div class="py-24 bg-white dark:bg-zinc-950 sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="grid max-w-2xl grid-cols-1 mx-auto gap-x-16 gap-y-16 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">{{ __('Communication') }}</h2>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">{{ __('Every conversation, on the record') }}</p>
                    <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Text and call clients, vendors, and crews from one shared inbox. Calls are recorded and transcribed, messages can be translated, and a text can become a scheduled task in a tap.') }}
                    </p>
                    <dl class="max-w-xl mt-10 space-y-8 text-base leading-7 text-gray-600 dark:text-gray-400 lg:max-w-none">
                        @php
                            $commBullets = [
                                [__('Group texting (SMS &amp; MMS).'), __('One thread per job keeps clients, subs, and crew on the same page—photos included.')],
                                [__('Recorded &amp; transcribed calls.'), __('Never lose a detail; calls are captured, transcribed, and summarized automatically.')],
                                [__('Translations built in.'), __('Message crews in their preferred language and read replies in yours.')],
                                [__('Texts become tasks.'), __('Turn an incoming message into a scheduled Hive task with AI—reviewed before it is saved.')],
                            ];
                        @endphp
                        @foreach ($commBullets as $bullet)
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900 dark:text-white">
                                    <flux:icon name="check-circle" class="absolute w-5 h-5 text-indigo-600 dark:text-indigo-400 left-1 top-1" />
                                    {!! $bullet[0] !!}
                                </dt>
                                <dd class="inline font-normal"> {!! $bullet[1] !!}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-8">
                        <a href="{{ route('welcome.communication') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500" wire:navigate.hover>
                            {{ __('Explore communication') }} <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>
                <div class="rounded-2xl bg-zinc-900 dark:bg-zinc-800 overflow-hidden shadow-2xl">
                    {{-- Inbox header --}}
                    <div class="flex items-center gap-3 px-4 py-3 border-b border-zinc-700">
                        <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center shrink-0">
                            <flux:icon name="chat-bubble-left-right" class="w-4 h-4 text-white" />
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-white">{{ __('Kitchen Renovation') }}</div>
                            <div class="text-xs text-zinc-400">{!! __('Homeowner, Plumber &amp; crew') !!}</div>
                        </div>
                        <div class="ml-auto flex items-center gap-2">
                            <span class="text-xs bg-green-500/20 text-green-400 rounded-full px-2 py-0.5">{{ __('Live') }}</span>
                            <flux:icon name="phone" class="w-4 h-4 text-zinc-400" />
                        </div>
                    </div>
                    {{-- Messages --}}
                    <div class="px-4 py-4 space-y-3">
                        <div class="flex gap-2">
                            <div class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center text-xs font-bold text-white shrink-0">G</div>
                            <div class="max-w-[75%] rounded-2xl rounded-tl-sm bg-zinc-700 px-3 py-2">
                                <p class="text-xs font-semibold text-zinc-300">{!! __('Mike &middot; Plumbing') !!}</p>
                                <p class="mt-0.5 text-sm text-white">{{ __('Rough plumbing wrapped. Sending photos now 👍') }}</p>
                                <p class="mt-1 text-xs text-zinc-500">5:14 PM</p>
                            </div>
                        </div>
                        <div class="flex gap-2 justify-end">
                            <div class="max-w-[75%] rounded-2xl rounded-tr-sm bg-indigo-600 px-3 py-2">
                                <p class="text-xs font-semibold text-indigo-200">{{ __('You') }}</p>
                                <p class="mt-0.5 text-sm text-white">{{ __('Perfect. Inspection is Thursday — I created the task. ✅') }}</p>
                                <p class="mt-1 text-xs text-indigo-300">5:17 PM</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <div class="w-7 h-7 rounded-full bg-amber-600 flex items-center justify-center text-xs font-bold text-white shrink-0">C</div>
                            <div class="max-w-[75%] rounded-2xl rounded-tl-sm bg-zinc-700 px-3 py-2">
                                <p class="text-xs font-semibold text-zinc-300">{!! __('Sarah &middot; Homeowner') !!}</p>
                                <p class="mt-0.5 text-sm text-white">{!! __('When will the cabinets go in? We&rsquo;re excited!') !!}</p>
                                <p class="mt-1 text-xs text-zinc-500">5:22 PM</p>
                            </div>
                        </div>
                        <div class="flex gap-2 justify-end">
                            <div class="max-w-[75%] rounded-2xl rounded-tr-sm bg-indigo-600 px-3 py-2">
                                <p class="text-xs font-semibold text-indigo-200">{{ __('You') }}</p>
                                <p class="mt-0.5 text-sm text-white">{!! __('Next week Tue &mdash; just texted you the live schedule 📅') !!}</p>
                                <p class="mt-1 text-xs text-indigo-300">5:29 PM</p>
                            </div>
                        </div>
                        {{-- AI task suggestion --}}
                        <div class="rounded-xl border border-indigo-500/40 bg-indigo-950/60 px-3 py-2.5">
                            <div class="flex items-center gap-2 mb-1">
                                <flux:icon name="sparkles" class="w-3.5 h-3.5 text-indigo-400" />
                                <span class="text-xs font-semibold text-indigo-300">{{ __('AI suggested task') }}</span>
                            </div>
                            <p class="text-xs text-zinc-300">{!! __('&ldquo;Cabinet delivery&rdquo; &mdash; Tue 7/1 &middot; Kitchen Renovation') !!}</p>
                            <div class="flex gap-2 mt-2">
                                <span class="text-xs bg-indigo-600 text-white rounded px-2 py-0.5 cursor-pointer">{{ __('Add task') }}</span>
                                <span class="text-xs text-zinc-500 rounded px-2 py-0.5 cursor-pointer">{{ __('Dismiss') }}</span>
                            </div>
                        </div>
                    </div>
                    {{-- Composer --}}
                    <div class="px-4 py-3 border-t border-zinc-700 flex items-center gap-3">
                        <div class="flex-1 rounded-full bg-zinc-700 px-4 py-2 text-sm text-zinc-500">{!! __('Message Kitchen Renovation&hellip;') !!}</div>
                        <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center shrink-0">
                            <flux:icon name="paper-airplane" class="w-4 h-4 text-white" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================ PLANNING SPOTLIGHT ============================ --}}
    <div class="py-24 overflow-hidden bg-gray-100 dark:bg-zinc-900 sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto lg:text-center">
                <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">{{ __('Planning') }}</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl text-balance">
                    {{ __('Schedules your whole crew can actually follow') }}
                </p>
                <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                    {{ __('Plan every job on a drag-and-drop timeline, then share exactly what is coming up with clients and crews. When the plan moves, everyone moves with it.') }}
                </p>
            </div>
            <div class="max-w-2xl mx-auto mt-16 sm:mt-20 lg:max-w-none">
                <dl class="grid max-w-xl grid-cols-1 mx-auto gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-3">
                    @php
                        $planFeatures = [
                            ['icon' => 'calendar-date-range', 'title' => __('Gantt timelines'), 'body' => __('Drag tasks to reschedule, set dependencies, and see the critical path across every active job.')],
                            ['icon' => 'view-columns', 'title' => __('Kanban boards'), 'body' => __('Move work through stages on a board view your crews understand at a glance.')],
                            ['icon' => 'paper-airplane', 'title' => __('Shared schedules'), 'body' => __('Text clients a live "what is next" schedule link so they are never left wondering.')],
                        ];
                    @endphp
                    @foreach ($planFeatures as $feature)
                        <div class="relative pl-16">
                            <dt class="text-base font-semibold leading-7 text-gray-900 dark:text-white">
                                <div class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                    <flux:icon name="{{ $feature['icon'] }}" class="w-6 h-6 text-white" />
                                </div>
                                {!! $feature['title'] !!}
                            </dt>
                            <dd class="mt-2 text-base leading-7 text-gray-600 dark:text-gray-400">{!! $feature['body'] !!}</dd>
                        </div>
                    @endforeach
                </dl>

                {{-- Gantt mockup --}}
                <div class="mt-16 overflow-hidden rounded-2xl shadow-2xl ring-1 ring-zinc-200 dark:ring-zinc-700 bg-white dark:bg-zinc-800">
                    {{-- Toolbar --}}
                    <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <flux:icon name="calendar-date-range" class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                            <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{!! __('Kitchen Renovation &mdash; Timeline') !!}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-zinc-400">Jul 2025</span>
                            <span class="text-xs bg-indigo-600 text-white rounded px-2 py-0.5">{{ __('Share schedule') }}</span>
                        </div>
                    </div>
                    {{-- Gantt grid --}}
                    <div class="overflow-x-auto">
                        @php
                            $ganttTasks = [
                                [__('Demo'),              'check-circle', 'green',  10, 10, 'done'],
                                [__('Rough plumbing'),    'check-circle', 'green',  22, 12, 'done'],
                                [__('Electrical rough'),  'wrench-screwdriver', 'indigo', 38, 14, 'active'],
                                [__('Inspection'),        'clipboard-document-check', 'amber', 54, 6, 'upcoming'],
                                [__('Drywall'),           'square-3-stack-3d', 'zinc',  62, 16, 'upcoming'],
                                [__('Cabinets & finishes'),'home-modern', 'zinc',   80, 18, 'upcoming'],
                            ];
                            $colors = [
                                'green'  => 'bg-green-500',
                                'indigo' => 'bg-indigo-500',
                                'amber'  => 'bg-amber-400',
                                'zinc'   => 'bg-zinc-300 dark:bg-zinc-600',
                            ];
                        @endphp
                        <div class="min-w-[640px]">
                            {{-- Week headers --}}
                            <div class="flex border-b border-zinc-100 dark:border-zinc-700 px-5 py-1.5">
                                <div class="w-36 shrink-0"></div>
                                @foreach(['Jun 23','Jun 30','Jul 7','Jul 14','Jul 21','Jul 28'] as $wk)
                                    <div class="flex-1 text-center text-xs text-zinc-400">{{ $wk }}</div>
                                @endforeach
                            </div>
                            {{-- Task rows --}}
                            @foreach($ganttTasks as [$label,$icon,$color,$left,$width,$state])
                            <div class="flex items-center gap-2 px-5 py-2 border-b border-zinc-50 dark:border-zinc-700/50 last:border-0">
                                <div class="w-36 shrink-0 flex items-center gap-2">
                                    <flux:icon name="{{ $icon }}" class="w-4 h-4 {{ $state === 'done' ? 'text-green-500' : ($state === 'active' ? 'text-indigo-500' : 'text-zinc-300 dark:text-zinc-600') }}" />
                                    <span class="text-xs text-zinc-700 dark:text-zinc-300 truncate">{{ $label }}</span>
                                </div>
                                <div class="flex-1 relative h-6">
                                    <div class="absolute top-1 h-4 rounded-full {{ $colors[$color] }} opacity-90" style="left:{{ $left }}%; width:{{ $width }}%"></div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="mt-12 text-center">
                    <a href="{{ route('welcome.planning') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500" wire:navigate.hover>
                        {{ __('Explore planning') }} <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================ AI AUTOMATION ============================ --}}
    <div class="py-24 bg-white dark:bg-zinc-950 sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto lg:text-center">
                <h2 class="text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">{{ __('Automation') }}</h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl text-balance">
                    {{ __('The busywork runs itself') }}
                </p>
                <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">
                    {{ __("Hive's automations quietly handle the data entry that eats your evenings—so the office work is done before you get home.") }}
                </p>
            </div>
            <div class="grid max-w-2xl grid-cols-1 mx-auto mt-16 gap-8 sm:mt-20 lg:max-w-none lg:grid-cols-4">
                @php
                    $autoCards = [
                        ['icon' => 'receipt-percent', 'title' => __('Receipt capture'), 'body' => __('Forward a receipt email or snap a photo—Hive reads the vendor, totals, and line items for you.')],
                        ['icon' => 'arrows-right-left', 'title' => __('Vendor matching'), 'body' => __('Unknown bank charges are matched to the right vendor and project automatically.')],
                        ['icon' => 'chat-bubble-bottom-center-text', 'title' => __('Call summaries'), 'body' => __('Every recorded call is transcribed and summarized so follow-ups write themselves.')],
                        ['icon' => 'calendar-date-range', 'title' => __('Text-to-task'), 'body' => __('Schedule-worthy messages become draft tasks—dates, times, and assignees included.')],
                    ];
                @endphp
                @foreach ($autoCards as $card)
                    <div class="flex flex-col p-6 bg-gray-50 dark:bg-zinc-900 rounded-2xl ring-1 ring-gray-200 dark:ring-zinc-800">
                        <div class="flex items-center justify-center w-12 h-12 bg-indigo-600/10 dark:bg-indigo-500/20 rounded-lg">
                            <flux:icon name="{{ $card['icon'] }}" class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-gray-900 dark:text-white">{{ $card['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $card['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ============================ MADE BY CONTRACTORS ============================ --}}
    <div class="py-24 bg-gray-100 dark:bg-zinc-900 sm:py-32">
        <div class="px-6 mx-auto max-w-4xl lg:px-8 text-center">
            <flux:icon name="wrench-screwdriver" class="w-10 h-10 mx-auto text-indigo-600 dark:text-indigo-400" />
            <p class="mt-6 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white sm:text-3xl text-balance">
                {!! __('"We built Hive because we run a contracting business too. Every feature exists because we needed it on a real job site—not because it looked good in a demo. We don&rsquo;t do demos."') !!}
            </p>
            <p class="mt-6 text-base leading-7 text-gray-600 dark:text-gray-400 text-balance">
                {!! __('That&rsquo;s why we offer Hive <span class="font-semibold text-gray-900 dark:text-white">free for subcontractors under $200K in revenue</span>—and <span class="font-semibold text-gray-900 dark:text-white">free up to $400K</span> for subs whose general contractor is signed up for Hive.') !!}
            </p>
            <p class="mt-6 text-base font-semibold text-indigo-600 dark:text-indigo-400">{{ __('Made by Contractors. For Contractors.') }}</p>
        </div>
    </div>

    {{-- ============================ FAQ ============================ --}}
    <div class="py-24 bg-white dark:bg-zinc-950 sm:py-32 scroll-mt-24" id="faq">
        <div class="px-6 mx-auto max-w-4xl lg:px-8">
            <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl text-center">
                {{ __('Frequently asked questions') }}
            </h2>
            <div class="mt-12">
                @php
                    $faqs = [
                        ['q' => __('Who is Hive Contractors for?'), 'a' => __('Small contractors, subcontractors, and growing trades businesses who want their finances, communication, and scheduling in one place instead of spread across spreadsheets, texts, and shoeboxes of receipts.')],
                        ['q' => __('How does the receipt and transaction automation work?'), 'a' => __('Forward receipts to your Hive email or upload a photo. Hive reads the vendor, totals, and line items, then matches each receipt to the right bank transaction and project—so your books stay reconciled without manual data entry.')],
                        ['q' => __('Does Hive work with QuickBooks and my bank?'), 'a' => __('Yes. Hive keeps QuickBooks-ready books and connects to live bank feeds so transactions flow in automatically and categorize themselves against your expenses.')],
                        ['q' => __('Can I text and call clients and crews from Hive?'), 'a' => __('Absolutely. Hive gives you a shared inbox for SMS, MMS, and calls. Calls are recorded and transcribed, messages can be translated, and any text can become a scheduled task in a tap.')],
                        ['q' => __('Can homeowners see their project?'), 'a' => __('Yes. Each client gets a real-time portal and can receive a live schedule link by text, so they always know what is happening next without calling you.')],
                        ['q' => __('How do my crews track time and get paid?'), 'a' => __('Crews log hours against the right job from their phone. You review timesheets and run payroll from the same place—no separate spreadsheet required.')],
                        ['q' => __('Can I send estimates and get them signed?'), 'a' => __('Yes. Build estimates with AI assistance, send a professional PDF, and collect a client e-signature right from the link. Approved estimates turn into an active job in a click.')],
                        ['q' => __('Does Hive handle lien waivers and insurance compliance?'), 'a' => __('It does. Send lien waivers for secure public signing, and track vendor certificates of insurance and workers&rsquo; comp so you are always covered at audit time.')],
                        ['q' => __('Is there a mobile app?'), 'a' => __('Hive installs to your phone as an app (PWA) with push notifications, so you can capture receipts, message clients, track time, and check the schedule from the job site.')],
                        ['q' => __('What about leads and new business?'), 'a' => __('Capture leads, track them through your pipeline, and convert the ones that close into clients and projects—without re-typing their information.')],
                        ['q' => __('Is my data secure?'), 'a' => __('Hive uses bank-grade connections for financial data, passwordless passkey sign-in, and token-based links for public documents like lien waivers. You control who on your team can see finances, clients, and settings.')],
                    ];
                @endphp
                <flux:accordion>
                    @foreach ($faqs as $faq)
                        <flux:accordion.item>
                            <flux:accordion.heading>{{ $faq['q'] }}</flux:accordion.heading>
                            <flux:accordion.content>{{ $faq['a'] }}</flux:accordion.content>
                        </flux:accordion.item>
                    @endforeach
                </flux:accordion>
            </div>
        </div>
    </div>

    {{-- ============================ CTA ============================ --}}
    <x-marketing.cta
        heading="{{ __('Focus on your projects. Leave the busywork to us.') }}"
        subheading="{{ __('Managing projects is hard enough. Let Hive take care of the finances, the follow-ups, and the schedule.') }}"
    />

    <x-marketing.footer />
</x-guest-layout>
