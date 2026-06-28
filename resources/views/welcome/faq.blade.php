@section('title', 'FAQ — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav />

    {{-- Hero --}}
    <div class="py-24 bg-white dark:bg-zinc-950 sm:py-32">
        <div class="px-6 mx-auto max-w-4xl lg:px-8">
            <div class="text-center">
                <p class="text-base font-semibold text-indigo-600 dark:text-indigo-400">Help center</p>
                <h1 class="mt-2 text-4xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-5xl">Frequently asked questions</h1>
                <p class="mt-6 text-lg text-gray-600 dark:text-gray-400">Everything you need to know about Hive. Can&rsquo;t find your answer? <a href="mailto:{{ config('mail.from.address') }}" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Just ask us.</a></p>
            </div>

            @php
                $sections = [
                    [
                        'heading' => 'Getting started',
                        'items' => [
                            ['q' => 'Who is Hive Contractors for?', 'a' => 'Small contractors, subcontractors, and growing trades businesses who want their finances, communication, and scheduling in one place instead of spread across spreadsheets, texts, and shoeboxes of receipts.'],
                            ['q' => 'How much does Hive cost?', 'a' => 'Hive is free for subcontractors under $200K in annual revenue. Subs whose general contractor is signed up for Hive get free access up to $400K. Larger plans are available—contact us for pricing.'],
                            ['q' => 'Do I need to sign a contract?', 'a' => 'No contracts, no commitments. Sign up, use it on the job, and cancel any time if it is not for you.'],
                            ['q' => 'Is there a mobile app?', 'a' => 'Hive installs to your phone as a PWA (Progressive Web App) with push notifications, so you can capture receipts, message clients, track time, and check the schedule from any job site—no app store required.'],
                            ['q' => 'Can I try Hive before committing?', 'a' => 'Yes. Sign up for free and explore the platform. No credit card required to get started.'],
                        ],
                    ],
                    [
                        'heading' => 'Finances & accounting',
                        'items' => [
                            ['q' => 'How does receipt and transaction automation work?', 'a' => 'Forward receipts to your Hive email or upload a photo. Hive reads the vendor, totals, and line items, then matches each receipt to the right bank transaction and project—so your books stay reconciled without manual data entry.'],
                            ['q' => 'Does Hive work with QuickBooks and my bank?', 'a' => 'Yes. Hive keeps QuickBooks-ready books and connects to live bank feeds so transactions flow in automatically and categorize themselves against your expenses.'],
                            ['q' => 'Can Hive handle job costing?', 'a' => 'Absolutely. Every transaction, receipt, and expense can be assigned to a project, giving you real-time job cost reports without a separate spreadsheet.'],
                            ['q' => 'How does payroll work in Hive?', 'a' => 'Crews log hours from their phone against the right job. You review and approve timesheets, then run payroll from the same place—no manual hours to re-enter.'],
                        ],
                    ],
                    [
                        'heading' => 'Estimates & documents',
                        'items' => [
                            ['q' => 'Can I send estimates and get them signed?', 'a' => 'Yes. Build estimates with AI assistance, send a professional PDF, and collect a client e-signature right from the link. Approved estimates turn into an active job in one click.'],
                            ['q' => 'Does Hive handle lien waivers and insurance compliance?', 'a' => 'It does. Send lien waivers for secure public signing, and track vendor certificates of insurance and workers&rsquo; comp so you are always covered at audit time.'],
                            ['q' => 'Can I manage change orders in Hive?', 'a' => 'Yes. Create change orders tied to an existing project, send them to the client for approval, and keep a full version history of what was approved and when.'],
                            ['q' => 'Are signed documents stored securely?', 'a' => 'All signed estimates, change orders, and lien waivers are stored in Hive and accessible any time—no filing cabinet required.'],
                        ],
                    ],
                    [
                        'heading' => 'Communication',
                        'items' => [
                            ['q' => 'Can I text and call clients and crews from Hive?', 'a' => 'Yes. Hive gives you a shared inbox for SMS, MMS, and calls. Calls are recorded and transcribed, messages can be translated, and any text can become a scheduled task in a tap.'],
                            ['q' => 'Can multiple team members share the inbox?', 'a' => 'Yes. Your whole team sees the same conversation thread, so nothing falls through the cracks when someone is on site.'],
                            ['q' => 'Does Hive record calls?', 'a' => 'Call recording is available and transcribed automatically, so you always have a record of what was discussed with clients and vendors.'],
                        ],
                    ],
                    [
                        'heading' => 'Homeowner portal',
                        'items' => [
                            ['q' => 'Can homeowners see their project?', 'a' => 'Yes. Each client gets a real-time homeowner portal. They can see project status, schedule, documents, photos, and send messages—without calling you for an update.'],
                            ['q' => 'How does the homeowner get access?', 'a' => 'You send them a secure link by text or email. No app to install, no password to create—they tap the link and they are in.'],
                            ['q' => 'Can homeowners sign documents from the portal?', 'a' => 'Yes. Estimates and change orders can be sent to the homeowner for e-signature directly from their portal.'],
                            ['q' => 'Is the homeowner portal private?', 'a' => 'Completely. Each portal is accessible only by the homeowner you share it with, using a private link unique to their project.'],
                        ],
                    ],
                    [
                        'heading' => 'Leads, clients & vendors',
                        'items' => [
                            ['q' => 'Does Hive have a CRM for leads?', 'a' => 'Yes. Capture leads, track them through your pipeline, and convert the ones that close into clients and active projects—without re-typing anything.'],
                            ['q' => 'Can I track vendor insurance and compliance?', 'a' => 'Yes. Store vendor COIs, workers&rsquo; comp certificates, and license info in Hive. Get alerts before anything expires so you are always audit-ready.'],
                            ['q' => 'Can I collaborate with other contractors on a job?', 'a' => 'Yes. Add subcontractors and other jobsite contractors to a project so everyone is working from the same schedule, documents, and communication thread.'],
                        ],
                    ],
                    [
                        'heading' => 'Security & privacy',
                        'items' => [
                            ['q' => 'Is my data secure?', 'a' => 'Hive uses bank-grade connections for financial data, passwordless passkey sign-in, and token-based links for public documents like lien waivers. You control who on your team can see finances, clients, and settings.'],
                            ['q' => 'Who can see my financial data?', 'a' => 'Only you and the team members you explicitly grant access to. Hive has role-based permissions so you can keep sensitive financial data visible only to the right people.'],
                            ['q' => 'Can I export my data?', 'a' => 'Yes. Your financial data exports in QuickBooks-compatible format, and you can export reports, contacts, and documents at any time.'],
                        ],
                    ],
                ];
            @endphp

            <div class="mt-16 space-y-16">
                @foreach ($sections as $section)
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10 pb-4">{{ $section['heading'] }}</h2>
                        <flux:accordion class="mt-4">
                            @foreach ($section['items'] as $item)
                                <flux:accordion.item>
                                    <flux:accordion.heading>{{ $item['q'] }}</flux:accordion.heading>
                                    <flux:accordion.content>{!! $item['a'] !!}</flux:accordion.content>
                                </flux:accordion.item>
                            @endforeach
                        </flux:accordion>
                    </div>
                @endforeach
            </div>

            <div class="mt-20 rounded-2xl bg-indigo-600 px-8 py-12 text-center">
                <h2 class="text-2xl font-bold text-white">Still have questions?</h2>
                <p class="mt-4 text-indigo-200">Our team is made up of contractors too. We know what it is like and we are happy to help.</p>
                <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-center">
                    <flux:button href="mailto:{{ config('mail.from.address') }}" variant="ghost" class="!bg-white !text-indigo-600 hover:!bg-indigo-50 font-semibold">Email us</flux:button>
                    <flux:button href="{{ route('registration') }}" wire:navigate.hover class="!bg-indigo-500 !text-white hover:!bg-indigo-400 font-semibold">Get started free</flux:button>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.footer />
</x-guest-layout>
