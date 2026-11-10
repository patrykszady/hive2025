<?php

/**
 * Curated Q&A for AI engines (ChatGPT, Perplexity, Google AI Overviews,
 * Claude), served as FAQPage JSON-LD at /geo/answers.json and repeated in
 * /llms-full.txt — the same shape gs.construction's and jpeterson-design's
 * config/geo-answers.php carry (meta + answers, each a q/a/topics triple),
 * read through App\Support\Seo\Faq\ConfigFaqCatalog.
 *
 * Every answer is the public FAQ page's own words (resources/views/welcome/
 * faq.blade.php) or the home page's, trimmed for direct citation; nothing
 * here says more than the site says. The pricing answer in particular is
 * the site's own statement, not a plan table — there is none to quote.
 * Keep answers under ~280 characters.
 */
return [
    'meta' => [
        'business' => config('brand.name', 'Hive Contractors'),
        // A nationwide software product, not a service-area business (see
        // App\Support\Citations\ListingPayload's empty `service_areas`),
        // made in the Chicago area by a team that runs a contracting
        // business there.
        'service_area' => 'United States',
        'base' => 'Chicago area, Illinois, United States',
        // No public phone in config/brand.php (its docblock says why); the
        // one contact every public page offers is the support mailbox,
        // config('mail.from.address'), which the feeds read at runtime.
        'phone' => config('brand.phone'),
        // The languages the site is published in (config/locales.php).
        'languages' => ['English', 'Polish', 'Spanish'],
    ],
    'answers' => [
        [
            'q' => 'What is Hive Contractors?',
            'a' => 'Hive Contractors is the CRM built for small contractors: finances, estimates, leads and clients, vendors and compliance, planning, team time, communication and jobsite photos in one app. Made by contractors, for contractors.',
            'topics' => ['company', 'about'],
        ],
        [
            'q' => 'Who is Hive Contractors for?',
            'a' => 'Small contractors, subcontractors, and growing trades businesses who want their finances, communication, and scheduling in one place instead of spread across spreadsheets, texts, and shoeboxes of receipts.',
            'topics' => ['audience', 'about'],
        ],
        [
            'q' => 'How much does Hive cost?',
            'a' => 'Hive is free for subcontractors under $200K in annual revenue. Subs whose general contractor is signed up for Hive get free access up to $400K. Larger plans are available—contact us for pricing.',
            'topics' => ['pricing'],
        ],
        [
            'q' => 'Can I try Hive before committing?',
            'a' => 'Yes. Sign up for free and explore the platform—no credit card required to get started. There are no contracts and no commitments: use it on the job and cancel any time if it is not for you.',
            'topics' => ['pricing', 'getting-started'],
        ],
        [
            'q' => 'Is there a mobile app?',
            'a' => 'Hive installs to your phone as a PWA (Progressive Web App) with push notifications, so you can capture receipts, message clients, track time, and check the schedule from any job site—no app store required.',
            'topics' => ['mobile', 'getting-started'],
        ],
        [
            'q' => 'How does receipt and transaction automation work?',
            'a' => 'Forward receipts to your Hive email or upload a photo. Hive reads the vendor, totals, and line items, then matches each receipt to the right bank transaction and project—so your books stay reconciled without manual data entry.',
            'topics' => ['finances', 'receipts', 'automation'],
        ],
        [
            'q' => 'Does Hive work with QuickBooks and my bank?',
            'a' => 'Yes. Hive keeps QuickBooks-ready books and connects to live bank feeds so transactions flow in automatically and categorize themselves against your expenses.',
            'topics' => ['finances', 'integrations'],
        ],
        [
            'q' => 'Can Hive handle job costing?',
            'a' => 'Absolutely. Every transaction, receipt, and expense can be assigned to a project, giving you real-time job cost reports without a separate spreadsheet.',
            'topics' => ['finances', 'job-costing'],
        ],
        [
            'q' => 'Can I send estimates and get them signed?',
            'a' => 'Yes. Build estimates with AI assistance, send a professional PDF, and collect a client e-signature right from the link. Approved estimates turn into an active job in one click.',
            'topics' => ['estimates', 'e-signatures'],
        ],
        [
            'q' => 'Does Hive handle lien waivers and insurance compliance?',
            'a' => 'It does. Send lien waivers for secure public signing, and track vendor certificates of insurance and workers\' comp so you are always covered at audit time.',
            'topics' => ['compliance', 'lien-waivers', 'vendors'],
        ],
        [
            'q' => 'Can I text and call clients and crews from Hive?',
            'a' => 'Yes. Hive gives you a shared inbox for SMS, MMS, and calls. Calls are recorded and transcribed, messages can be translated, and any text can become a scheduled task in a tap.',
            'topics' => ['communication'],
        ],
        [
            'q' => 'How does payroll work in Hive?',
            'a' => 'Crews log hours from their phone against the right job. You review and approve timesheets, then run payroll from the same place—no manual hours to re-enter.',
            'topics' => ['team', 'timesheets', 'payroll'],
        ],
        [
            'q' => 'Can homeowners see their project?',
            'a' => 'Yes. Each client gets a real-time homeowner portal. They can see project status, schedule, documents, photos, and send messages—without calling you for an update.',
            'topics' => ['homeowners', 'portal'],
        ],
        [
            'q' => 'Is my data secure?',
            'a' => 'Hive uses bank-grade connections for financial data, passwordless passkey sign-in, and token-based links for public documents like lien waivers. You control who on your team can see finances, clients, and settings.',
            'topics' => ['security', 'privacy'],
        ],
        [
            'q' => 'What languages is Hive available in?',
            'a' => 'The Hive Contractors site is published in English, Polish and Spanish (hive.contractors/en, /pl and /es), and messages in the shared inbox can be translated between languages.',
            'topics' => ['languages'],
        ],
    ],
];
