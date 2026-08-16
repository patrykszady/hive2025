<?php

/*
|--------------------------------------------------------------------------
| Public marketing content
|--------------------------------------------------------------------------
|
| Single source of truth for the public feature sub-pages. Each "area" maps
| to a top-level feature page (welcome.<area>) and owns a list of cards.
| Every card renders a dedicated page at /welcome/<area>/<card> via the
| shared welcome.feature view, and is cross-linked by the feature-links
| component. Keys are URL slugs.
|
*/

return [

    'areas' => [

        'finances' => [
            'label' => 'Finances',
            'eyebrow' => 'Finances & bookkeeping',
            'grid_heading' => 'Everything in the money toolkit',
            'cards' => [

                'expenses' => [
                    'icon' => 'credit-card',
                    'title' => 'Expenses',
                    'body' => 'Track every cost by project and category with receipts attached.',
                    'hero' => 'Track every job cost—down to the receipt',
                    'lead' => 'Log costs against the right project and category in seconds, attach the receipt, and watch your true job cost build itself as you spend.',
                    'rows' => [
                        [
                            'heading' => 'Every cost in its place',
                            'text' => 'Capture an expense the moment it happens and tag it to the job and category it belongs to. No shoebox of receipts, no month-end scramble to remember what a charge was for.',
                            'points' => ['Assign costs to a project and category', 'Attach a photo or PDF receipt to each', 'Split a single charge across multiple jobs', 'Search and filter by job, vendor, or date'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Recent expenses · 123 Maple St', 'rows' => [
                                ['icon' => 'credit-card', 'label' => 'Home Depot · Lumber', 'sub' => '$842.10 · Materials'],
                                ['icon' => 'credit-card', 'label' => 'Ferguson · Fixtures', 'sub' => '$1,260.00 · Plumbing'],
                                ['icon' => 'credit-card', 'label' => 'Fuel · Truck 2', 'sub' => '$88.40 · Vehicle'],
                            ]],
                        ],
                        [
                            'heading' => 'Costs that feed your numbers',
                            'text' => 'Every expense flows straight into job costing and your reports, so margin is always current. You never have to re-enter the same number twice.',
                            'points' => ['Feeds job costing automatically', 'Rolls into profit & loss in real time', 'Matches against bank transactions', 'Keeps a clean, audit-ready record'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When every cost is tagged the moment you spend, your profit on each job is right there—no spreadsheet reconciling at midnight.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'folder', 'title' => 'By project', 'body' => 'Tie each cost to the job it belongs to.'],
                        ['icon' => 'tag', 'title' => 'By category', 'body' => 'Consistent categories keep reports clean.'],
                        ['icon' => 'paper-clip', 'title' => 'Receipts attached', 'body' => 'A photo or PDF on every expense.'],
                        ['icon' => 'arrows-pointing-out', 'title' => 'Split costs', 'body' => 'Divide one charge across several jobs.'],
                        ['icon' => 'calculator', 'title' => 'Feeds job costing', 'body' => 'Margin updates as you spend.'],
                        ['icon' => 'magnifying-glass', 'title' => 'Searchable', 'body' => 'Find any cost in seconds.'],
                    ],
                    'cta' => ['heading' => 'Know your real cost on every job.', 'sub' => 'Tag each expense once and let your margins keep themselves up to date.'],
                ],

                'auto-receipts' => [
                    'icon' => 'document-magnifying-glass',
                    'title' => 'Auto-receipts',
                    'body' => 'Email-in or photographed receipts are read, itemized, and filed automatically.',
                    'hero' => 'Receipts that file themselves',
                    'lead' => 'Forward an email receipt or snap a photo and Hive reads the vendor, total, and line items, then files it against the right job—no typing required.',
                    'rows' => [
                        [
                            'heading' => 'Snap it or forward it',
                            'text' => 'Text a photo, forward a supplier email, or let store accounts feed receipts in. Our AI pulls out the vendor, date, total, and every line item for you.',
                            'points' => ['Photograph paper receipts from the field', 'Forward email receipts to your Hive inbox', 'Itemized down to each product line', 'Vendor and totals read automatically'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Parsed · Home Depot', 'rows' => [
                                ['icon' => 'document-magnifying-glass', 'label' => '2x4 stud · qty 40', 'sub' => '$3.18 ea · $127.20'],
                                ['icon' => 'document-magnifying-glass', 'label' => 'Deck screws 5lb', 'sub' => '$42.97'],
                                ['icon' => 'document-magnifying-glass', 'label' => 'Total read', 'sub' => '$170.17'],
                            ]],
                        ],
                        [
                            'heading' => 'Filed before you forget',
                            'text' => 'Each receipt lands as an expense on the right project, ready to match to your bank feed. The pile on your dashboard disappears for good.',
                            'points' => ['Becomes an expense on the right job', 'Ready to match to bank transactions', 'No manual data entry', 'Every line item kept for warranty and disputes'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The receipts you used to lose are now searchable, itemized, and tied to the job—without you touching a keyboard.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'camera', 'title' => 'Photo capture', 'body' => 'Snap a paper receipt from the field.'],
                        ['icon' => 'envelope', 'title' => 'Email-in', 'body' => 'Forward supplier emails straight in.'],
                        ['icon' => 'list-bullet', 'title' => 'Itemized', 'body' => 'Every product line pulled out.'],
                        ['icon' => 'sparkles', 'title' => 'AI reading', 'body' => 'Vendor and totals detected for you.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Match-ready', 'body' => 'Lines up with your bank feed.'],
                        ['icon' => 'folder', 'title' => 'Auto-filed', 'body' => 'Lands on the correct project.'],
                    ],
                    'cta' => ['heading' => 'Stop typing in receipts.', 'sub' => 'Forward or snap a photo and let Hive itemize and file it for you.'],
                ],

                'payments' => [
                    'icon' => 'banknotes',
                    'title' => 'Payments',
                    'body' => 'Record what you pay and what you are owed across vendors and clients.',
                    'hero' => 'Money in and money out, in one place',
                    'lead' => 'Track every payment you make and every dollar you are owed, tied to the right job and contact—so you always know where you stand.',
                    'rows' => [
                        [
                            'heading' => 'A clear ledger for every job',
                            'text' => 'Record client payments and vendor payouts as they happen. Each one connects to a project, a contact, and your books, so nothing falls through the cracks.',
                            'points' => ['Track payments in and payments out', 'Tie every payment to a job and contact', 'See outstanding balances at a glance', 'Records that match your bank feed'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Project ledger · Maple St', 'rows' => [
                                ['label' => 'Client paid', 'value' => '$31,200'],
                                ['label' => 'Paid to vendors', 'value' => '$18,940'],
                                ['label' => 'Outstanding', 'value' => '$16,800'],
                            ]],
                        ],
                        [
                            'heading' => 'Never lose track of who owes what',
                            'text' => 'See what is still due from clients and what you owe subs and suppliers, all rolled up by job. Follow up with confidence instead of guessing.',
                            'points' => ['Know what clients still owe you', 'Know what you owe vendors', 'Roll balances up by project', 'Stay ahead of cash flow'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When every payment is logged against a job, your cash position is never a mystery at the end of the month.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'arrow-down-circle', 'title' => 'Payments in', 'body' => 'Record what clients pay you.'],
                        ['icon' => 'arrow-up-circle', 'title' => 'Payments out', 'body' => 'Log vendor and sub payouts.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Every payment tied to a project.'],
                        ['icon' => 'scale', 'title' => 'Balances', 'body' => 'See outstanding amounts clearly.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Bank-matched', 'body' => 'Lines up with your transactions.'],
                        ['icon' => 'chart-bar', 'title' => 'Cash clarity', 'body' => 'Always know where you stand.'],
                    ],
                    'cta' => ['heading' => 'Always know who owes what.', 'sub' => 'Track every payment in and out against the right job and contact.'],
                ],

                'vendor-payments' => [
                    'icon' => 'wallet',
                    'title' => 'Vendor payments',
                    'body' => 'Pay subs and suppliers and keep every payment tied to the right job.',
                    'hero' => 'Pay your subs—and keep the books straight',
                    'lead' => 'Record and track payments to subs and suppliers with every dollar tied to the right job, so labor and material cost always land where they belong.',
                    'rows' => [
                        [
                            'heading' => 'Every payout on the right job',
                            'text' => 'When you pay a sub or supplier, the cost attaches to the project automatically. No more wondering which job a check was really for.',
                            'points' => ['Pay subs and suppliers from one place', 'Costs land on the correct project', 'Track running balances per vendor', 'Keep a clean record for 1099s'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Vendor · Rivera Plumbing', 'rows' => [
                                ['label' => 'Invoiced', 'value' => '$6,400'],
                                ['label' => 'Paid', 'value' => '$4,000'],
                                ['label' => 'Balance', 'value' => '$2,400'],
                            ]],
                        ],
                        [
                            'heading' => 'Tied to insurance and compliance',
                            'text' => 'Hive connects each vendor to their certificates of insurance and workers&rsquo; comp, so you can keep paying the subs who keep you covered.',
                            'points' => ['Linked to vendor COIs and coverage', 'See balances before you pay again', 'Flag subs with expired paperwork', 'Feeds job costing and your books'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Paying subs through Hive means labor cost, balances, and compliance all stay in sync—no separate spreadsheet to keep.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'user-group', 'title' => 'Subs & suppliers', 'body' => 'Pay everyone from one place.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Costs attach to the right project.'],
                        ['icon' => 'scale', 'title' => 'Running balances', 'body' => 'Know what each vendor is owed.'],
                        ['icon' => 'shield-check', 'title' => 'Compliance-linked', 'body' => 'Tied to COIs and coverage.'],
                        ['icon' => 'document-text', 'title' => '1099-ready', 'body' => 'Clean records at year end.'],
                        ['icon' => 'calculator', 'title' => 'Feeds costing', 'body' => 'Labor lands in job costing.'],
                    ],
                    'cta' => ['heading' => 'Pay your subs without losing the thread.', 'sub' => 'Every payout tied to the job, the balance, and their insurance.'],
                ],

                'checks' => [
                    'icon' => 'pencil-square',
                    'title' => 'Checks',
                    'body' => 'Print and log checks with the right job and category already filled in.',
                    'hero' => 'Write checks without the busywork',
                    'lead' => 'Print and record checks with the job, category, and vendor already filled in—then watch them match themselves to your bank feed.',
                    'rows' => [
                        [
                            'heading' => 'Print and log in one step',
                            'text' => 'Cut a check and Hive records it as an expense on the right job at the same time. The paper and the books stay perfectly in sync.',
                            'points' => ['Print checks on your stock', 'Logged as an expense automatically', 'Job and category pre-filled', 'Sequential numbering kept tidy'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Check #1042', 'rows' => [
                                ['label' => 'Pay to', 'value' => 'Rivera Plumbing'],
                                ['label' => 'Job', 'value' => 'Maple St'],
                                ['label' => 'Amount', 'value' => '$2,400.00'],
                            ]],
                        ],
                        [
                            'heading' => 'Reconciles itself',
                            'text' => 'When the check clears, the bank transaction matches the record you already made. Reconciliation stops being a chore.',
                            'points' => ['Matches the cleared bank transaction', 'No double entry at month end', 'Spot outstanding checks easily', 'A clean trail for every payment'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The check you printed is already in your books on the right job—so reconciling is just confirming, not re-typing.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'printer', 'title' => 'Print-ready', 'body' => 'Cut checks on your stock.'],
                        ['icon' => 'folder', 'title' => 'Job pre-filled', 'body' => 'The right project, automatically.'],
                        ['icon' => 'tag', 'title' => 'Category set', 'body' => 'Consistent for clean books.'],
                        ['icon' => 'hashtag', 'title' => 'Numbered', 'body' => 'Sequential and tidy.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Auto-match', 'body' => 'Reconciles to your bank feed.'],
                        ['icon' => 'document-check', 'title' => 'Clean trail', 'body' => 'A record for every check.'],
                    ],
                    'cta' => ['heading' => 'Make checks one step, not three.', 'sub' => 'Print, record, and reconcile from a single action.'],
                ],

                'banks' => [
                    'icon' => 'building-library',
                    'title' => 'Banks',
                    'body' => 'Connect accounts for live transaction feeds and reconciliation.',
                    'hero' => 'Your bank feed, working for you',
                    'lead' => 'Connect your accounts for a live feed of transactions that match themselves to expenses, checks, and vendors—so reconciliation takes minutes.',
                    'rows' => [
                        [
                            'heading' => 'Live transactions, automatically',
                            'text' => 'Link your business accounts and cards once. New transactions flow in on their own, ready to match against the costs you already recorded.',
                            'points' => ['Secure connection to your accounts', 'Transactions update automatically', 'Cards and checking in one view', 'Nothing to import by hand'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Recent feed · Operating', 'rows' => [
                                ['icon' => 'building-library', 'label' => 'Home Depot', 'sub' => '-$842.10 · matched'],
                                ['icon' => 'building-library', 'label' => 'Check #1042', 'sub' => '-$2,400.00 · matched'],
                                ['icon' => 'building-library', 'label' => 'Client deposit', 'sub' => '+$10,000 · review'],
                            ]],
                        ],
                        [
                            'heading' => 'Reconcile in minutes',
                            'text' => 'Hive lines up each transaction with the right expense, check, or vendor payment. You confirm the matches and your books are done.',
                            'points' => ['Auto-matched to your records', 'Catch anything missing fast', 'Keep balances accurate', 'No spreadsheet reconciling'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A live bank feed that matches itself turns hours of month-end reconciling into a quick review.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'link', 'title' => 'Connected', 'body' => 'Link accounts and cards once.'],
                        ['icon' => 'bolt', 'title' => 'Live feed', 'body' => 'Transactions update on their own.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Auto-match', 'body' => 'Lines up with your records.'],
                        ['icon' => 'shield-check', 'title' => 'Secure', 'body' => 'Bank-grade connection.'],
                        ['icon' => 'check-circle', 'title' => 'Easy reconcile', 'body' => 'Confirm and you are done.'],
                        ['icon' => 'scale', 'title' => 'Accurate', 'body' => 'Balances you can trust.'],
                    ],
                    'cta' => ['heading' => 'Let your bank feed do the matching.', 'sub' => 'Connect once and reconcile in minutes, not hours.'],
                ],

                'transaction-matching' => [
                    'icon' => 'arrows-right-left',
                    'title' => 'Transaction matching',
                    'body' => 'Bank transactions match themselves to the right vendor, expense, and check.',
                    'hero' => 'Transactions that match themselves',
                    'lead' => 'Hive lines up each bank transaction with the right vendor, expense, and check automatically—so your books stay clean without the manual sorting.',
                    'rows' => [
                        [
                            'heading' => 'Smart matching out of the box',
                            'text' => 'Our matching learns your vendors and patterns, then connects each incoming transaction to the cost you already recorded—or suggests the closest fit.',
                            'points' => ['Matches to vendor, expense, or check', 'Learns your recurring patterns', 'Suggests the best fit to confirm', 'Flags anything unexpected'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Matched today', 'rows' => [
                                ['icon' => 'arrows-right-left', 'label' => 'Ferguson → Expense', 'sub' => '$1,260 · Plumbing'],
                                ['icon' => 'arrows-right-left', 'label' => 'Check #1042 → Rivera', 'sub' => '$2,400 · Maple St'],
                                ['icon' => 'arrows-right-left', 'label' => 'Fuel → Vehicle', 'sub' => '$88.40'],
                            ]],
                        ],
                        [
                            'heading' => 'Catch what does not belong',
                            'text' => 'Unmatched or duplicate charges surface right away, so mistakes and double-billing get caught before they hit your numbers.',
                            'points' => ['Surface unmatched transactions', 'Catch duplicates automatically', 'Keep job costs accurate', 'Trust your reports'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When matching is automatic, the only transactions you look at are the ones that actually need you.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'sparkles', 'title' => 'Smart matches', 'body' => 'Learns your vendors and patterns.'],
                        ['icon' => 'check-circle', 'title' => 'One-tap confirm', 'body' => 'Approve suggested matches fast.'],
                        ['icon' => 'document-duplicate', 'title' => 'Dedup', 'body' => 'Catches double charges.'],
                        ['icon' => 'flag', 'title' => 'Exceptions', 'body' => 'Only flags what needs you.'],
                        ['icon' => 'folder', 'title' => 'Job-accurate', 'body' => 'Keeps costs on the right job.'],
                        ['icon' => 'chart-bar', 'title' => 'Trustworthy', 'body' => 'Reports you can rely on.'],
                    ],
                    'cta' => ['heading' => 'Stop sorting transactions by hand.', 'sub' => 'Let matching connect the dots and only show you the exceptions.'],
                ],

                'reimbursements' => [
                    'icon' => 'arrow-uturn-left',
                    'title' => 'Reimbursements',
                    'body' => 'Track what the company owes crew and owners and pay it back cleanly.',
                    'hero' => 'Pay people back—without the sticky notes',
                    'lead' => 'Track every out-of-pocket expense your crew and owners cover, then reimburse them cleanly with a record that ties back to the job.',
                    'rows' => [
                        [
                            'heading' => 'Out-of-pocket, captured',
                            'text' => 'When someone buys materials on their own card, log it as a reimbursable expense against the job. Nothing gets forgotten or paid twice.',
                            'points' => ['Log out-of-pocket costs to a job', 'Attach the receipt as proof', 'Track who is owed and how much', 'Avoid paying the same cost twice'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Owed to · Greg M.', 'rows' => [
                                ['label' => 'Lumber (personal card)', 'value' => '$214.80'],
                                ['label' => 'Hardware', 'value' => '$63.20'],
                                ['label' => 'To reimburse', 'value' => '$278.00'],
                            ]],
                        ],
                        [
                            'heading' => 'Settle up cleanly',
                            'text' => 'Reimburse the running balance in one payment and Hive records it against the right job and category, keeping cost and books accurate.',
                            'points' => ['Pay back the running balance', 'Recorded against the job', 'Keeps job cost accurate', 'Clear history for everyone'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Crew that gets paid back quickly and correctly is crew that keeps buying what the job needs.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'arrow-uturn-left', 'title' => 'Reimbursable', 'body' => 'Flag out-of-pocket costs.'],
                        ['icon' => 'paper-clip', 'title' => 'With receipt', 'body' => 'Proof attached to each.'],
                        ['icon' => 'scale', 'title' => 'Running owed', 'body' => 'Know who is owed what.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Cost lands on the project.'],
                        ['icon' => 'banknotes', 'title' => 'Pay it back', 'body' => 'Settle in one payment.'],
                        ['icon' => 'clock', 'title' => 'History', 'body' => 'A clear trail for all.'],
                    ],
                    'cta' => ['heading' => 'Reimburse your crew the easy way.', 'sub' => 'Capture out-of-pocket costs and settle up with a clean record.'],
                ],

                'distributions' => [
                    'icon' => 'receipt-percent',
                    'title' => 'Distributions',
                    'body' => 'Keep owner draws and distributions organized and reportable.',
                    'hero' => 'Owner draws, organized and reportable',
                    'lead' => 'Record owner draws and distributions in a way that stays clean for your accountant and clear for you at tax time.',
                    'rows' => [
                        [
                            'heading' => 'Track every draw',
                            'text' => 'Log distributions as they happen, separate from job costs and expenses, so your business numbers and your personal take are never tangled.',
                            'points' => ['Record owner draws cleanly', 'Keep them out of job costs', 'Split across multiple owners', 'Tied to the right accounts'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Distributions · YTD', 'rows' => [
                                ['label' => 'Owner A', 'value' => '$42,000'],
                                ['label' => 'Owner B', 'value' => '$38,500'],
                                ['label' => 'Total', 'value' => '$80,500'],
                            ]],
                        ],
                        [
                            'heading' => 'Ready for your accountant',
                            'text' => 'Everything is categorized and reportable, so handing off at tax time is a download instead of a reconstruction.',
                            'points' => ['Reportable by owner and period', 'Clean categories all year', 'Easy hand-off to your CPA', 'No tax-season scramble'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Keeping draws clean all year means tax time is a quick export, not a painful cleanup.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'receipt-percent', 'title' => 'Owner draws', 'body' => 'Logged as they happen.'],
                        ['icon' => 'users', 'title' => 'Multi-owner', 'body' => 'Split across partners.'],
                        ['icon' => 'tag', 'title' => 'Categorized', 'body' => 'Clean books all year.'],
                        ['icon' => 'folder-minus', 'title' => 'Separate', 'body' => 'Kept out of job costs.'],
                        ['icon' => 'document-currency-dollar', 'title' => 'Reportable', 'body' => 'By owner and period.'],
                        ['icon' => 'arrow-down-tray', 'title' => 'Export', 'body' => 'Hand off to your CPA.'],
                    ],
                    'cta' => ['heading' => 'Keep your draws clean all year.', 'sub' => 'Organized, reportable distributions that make tax time easy.'],
                ],

                'line-items' => [
                    'icon' => 'list-bullet',
                    'title' => 'Line items & allowances',
                    'body' => 'Itemize costs and reconcile against client allowances down to the line.',
                    'hero' => 'Itemize everything—and protect your allowances',
                    'lead' => 'Break costs down to the line and reconcile them against each client allowance, so overages are caught before they cost you money.',
                    'rows' => [
                        [
                            'heading' => 'Detail down to the line',
                            'text' => 'Capture costs as itemized lines, not lump sums. You and your client both see exactly where the money goes on every selection and category.',
                            'points' => ['Itemize costs line by line', 'Group lines by category or room', 'Tie lines to the right job', 'Crystal-clear for clients'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Tile allowance', 'rows' => [
                                ['label' => 'Allowance', 'value' => '$2,500'],
                                ['label' => 'Itemized cost', 'value' => '$2,840'],
                                ['label' => 'Overage', 'value' => '+$340'],
                            ]],
                        ],
                        [
                            'heading' => 'Allowances that hold up',
                            'text' => 'Hive reconciles your line items against the client&rsquo;s allowance and flags overages, so the conversation happens before the invoice, not after.',
                            'points' => ['Reconcile lines to allowances', 'Flag overages automatically', 'Bill overages with confidence', 'No money left on the table'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Itemized lines reconciled to allowances mean you get paid for the upgrades clients choose—every time.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'list-bullet', 'title' => 'Itemized', 'body' => 'Costs broken to the line.'],
                        ['icon' => 'rectangle-group', 'title' => 'Grouped', 'body' => 'By category or room.'],
                        ['icon' => 'scale', 'title' => 'Reconciled', 'body' => 'Lines vs allowances.'],
                        ['icon' => 'exclamation-triangle', 'title' => 'Overage alerts', 'body' => 'Caught before billing.'],
                        ['icon' => 'banknotes', 'title' => 'Bill upgrades', 'body' => 'Get paid for changes.'],
                        ['icon' => 'eye', 'title' => 'Transparent', 'body' => 'Clear for clients.'],
                    ],
                    'cta' => ['heading' => 'Never eat an allowance overage again.', 'sub' => 'Itemize to the line and reconcile against every allowance.'],
                ],

                'estimates-invoices' => [
                    'icon' => 'document-text',
                    'title' => 'Estimates & invoices',
                    'body' => 'Send branded estimates and invoices and turn approvals into jobs.',
                    'hero' => 'From estimate to invoice to paid',
                    'lead' => 'Send branded estimates, turn approvals into live jobs, and invoice for the work you finished—all without leaving Hive.',
                    'rows' => [
                        [
                            'heading' => 'Branded and professional',
                            'text' => 'Send clean, itemized estimates that make you look like the pro you are. Clients approve online and the job is ready to start.',
                            'points' => ['Branded estimates and invoices', 'Itemized and easy to read', 'Online approval and e-sign', 'Approvals become live jobs'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Estimate #1042', 'rows' => [
                                ['label' => 'Cabinetry', 'value' => '$8,400'],
                                ['label' => 'Countertops', 'value' => '$3,950'],
                                ['label' => 'Total', 'value' => '$14,450'],
                            ]],
                        ],
                        [
                            'heading' => 'Invoice and get paid',
                            'text' => 'Bill progress payments or final invoices straight from the approved scope. Everything ties back to job costing and your books.',
                            'points' => ['Invoice from the approved scope', 'Progress or final billing', 'Connected to job costing', 'Clear record of what is paid'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When estimates flow into jobs and invoices, you stop re-typing numbers and start getting paid faster.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-text', 'title' => 'Estimates', 'body' => 'Branded and itemized.'],
                        ['icon' => 'pencil-square', 'title' => 'E-sign', 'body' => 'Approve online in seconds.'],
                        ['icon' => 'arrow-path', 'title' => 'To jobs', 'body' => 'Approvals become projects.'],
                        ['icon' => 'document-currency-dollar', 'title' => 'Invoices', 'body' => 'Bill for finished work.'],
                        ['icon' => 'calculator', 'title' => 'Costing-linked', 'body' => 'Ties to job costing.'],
                        ['icon' => 'check-circle', 'title' => 'Paid clarity', 'body' => 'Know what is settled.'],
                    ],
                    'cta' => ['heading' => 'Win the bid and bill it—in one place.', 'sub' => 'Branded estimates that flow straight into jobs and invoices.'],
                ],

                'sheets' => [
                    'icon' => 'document-currency-dollar',
                    'title' => 'Sheets',
                    'body' => 'Balance sheets and profit & loss generated from your live data.',
                    'hero' => 'Financial statements that build themselves',
                    'lead' => 'Your balance sheet and profit & loss are generated from live data—always current, always ready, no spreadsheet wrangling.',
                    'rows' => [
                        [
                            'heading' => 'Live profit & loss',
                            'text' => 'Every expense, payment, and invoice flows into a P&L that is right this minute—not last quarter. See how the business is really doing whenever you want.',
                            'points' => ['Profit & loss from live data', 'Balance sheet always current', 'Filter by period and job', 'No manual bookkeeping exports'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'P&L · This month', 'rows' => [
                                ['label' => 'Revenue', 'value' => '$84,200'],
                                ['label' => 'Costs', 'value' => '$58,640'],
                                ['label' => 'Net', 'value' => '$25,560'],
                            ]],
                        ],
                        [
                            'heading' => 'Ready for anyone who asks',
                            'text' => 'When your accountant, lender, or partner needs numbers, they are already done. Export a clean statement in seconds.',
                            'points' => ['Hand off to your accountant fast', 'Statements lenders trust', 'Always reconciled to your feed', 'Export when you need to'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Financial statements that are always current mean you make decisions on real numbers, not gut feel.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-currency-dollar', 'title' => 'Profit & loss', 'body' => 'Live, not last quarter.'],
                        ['icon' => 'scale', 'title' => 'Balance sheet', 'body' => 'Always up to date.'],
                        ['icon' => 'funnel', 'title' => 'Filterable', 'body' => 'By period and job.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Reconciled', 'body' => 'Tied to your bank feed.'],
                        ['icon' => 'arrow-down-tray', 'title' => 'Exportable', 'body' => 'Hand off in seconds.'],
                        ['icon' => 'chart-bar', 'title' => 'Decision-ready', 'body' => 'Real numbers, anytime.'],
                    ],
                    'cta' => ['heading' => 'Know your numbers without the spreadsheet.', 'sub' => 'Live P&L and balance sheet, generated from your real data.'],
                ],

                'categories' => [
                    'icon' => 'tag',
                    'title' => 'Categories',
                    'body' => 'Consistent categories keep your books and reports trustworthy.',
                    'hero' => 'Consistent categories, trustworthy books',
                    'lead' => 'A clean set of categories applied everywhere means your reports actually mean something—and tax time is far less painful.',
                    'rows' => [
                        [
                            'heading' => 'One consistent set',
                            'text' => 'Define the categories that fit your business once, and Hive applies them across expenses, checks, and payments so nothing is miscoded.',
                            'points' => ['Define categories that fit your trade', 'Applied across every transaction', 'Suggested automatically as you go', 'No more one-off mislabels'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Top categories · This month', 'rows' => [
                                ['icon' => 'tag', 'label' => 'Materials', 'sub' => '$32,400'],
                                ['icon' => 'tag', 'label' => 'Labor', 'sub' => '$21,800'],
                                ['icon' => 'tag', 'label' => 'Vehicle & fuel', 'sub' => '$3,140'],
                            ]],
                        ],
                        [
                            'heading' => 'Reports you can trust',
                            'text' => 'When everything is coded the same way, your P&L and job costs tell the truth—and your accountant thanks you.',
                            'points' => ['Reliable profit & loss', 'Accurate job costs', 'Cleaner tax prep', 'Spot trends with confidence'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Consistent categories are the quiet foundation under every report you actually trust.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'tag', 'title' => 'Custom set', 'body' => 'Fit your trade.'],
                        ['icon' => 'sparkles', 'title' => 'Auto-suggested', 'body' => 'Coded as you go.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Everywhere', 'body' => 'Across all transactions.'],
                        ['icon' => 'document-currency-dollar', 'title' => 'Clean P&L', 'body' => 'Reports that add up.'],
                        ['icon' => 'calculator', 'title' => 'Accurate costing', 'body' => 'Jobs coded right.'],
                        ['icon' => 'check-badge', 'title' => 'Tax-ready', 'body' => 'Less year-end cleanup.'],
                    ],
                    'cta' => ['heading' => 'Build books you can actually trust.', 'sub' => 'One consistent category set applied across everything.'],
                ],

                'job-costing' => [
                    'icon' => 'calculator',
                    'title' => 'Job costing',
                    'body' => 'See true cost and margin on every project as the money moves.',
                    'hero' => 'Know your margin on every job',
                    'lead' => 'See true cost and live margin on each project as expenses, labor, and payments move—so you find out you are over before it is too late.',
                    'rows' => [
                        [
                            'heading' => 'Cost that builds itself',
                            'text' => 'Materials, labor, and sub payments all land on the job automatically. Your cost-to-date is always current without you tallying anything.',
                            'points' => ['Materials, labor, and subs combined', 'Cost-to-date always current', 'Compare against the estimate', 'No manual tallying'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Maple St · Margin', 'rows' => [
                                ['label' => 'Contract', 'value' => '$48,000'],
                                ['label' => 'Cost to date', 'value' => '$30,100'],
                                ['label' => 'Projected margin', 'value' => '24%'],
                            ]],
                        ],
                        [
                            'heading' => 'Catch overruns early',
                            'text' => 'When a job starts trending over, you see it while you can still do something about it—not at the final invoice.',
                            'points' => ['Spot overruns in real time', 'Protect your margin', 'Decide before it is too late', 'Bid better on the next job'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Live job costing is the difference between finding out you lost money and preventing it.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'calculator', 'title' => 'True cost', 'body' => 'Materials, labor, subs.'],
                        ['icon' => 'bolt', 'title' => 'Live', 'body' => 'Updates as money moves.'],
                        ['icon' => 'scale', 'title' => 'Vs estimate', 'body' => 'Compare to your bid.'],
                        ['icon' => 'chart-bar', 'title' => 'Margin', 'body' => 'See profit per job.'],
                        ['icon' => 'exclamation-triangle', 'title' => 'Overrun alerts', 'body' => 'Catch it early.'],
                        ['icon' => 'light-bulb', 'title' => 'Better bids', 'body' => 'Learn from real data.'],
                    ],
                    'cta' => ['heading' => 'Stop guessing whether a job made money.', 'sub' => 'Live cost and margin on every project, as it happens.'],
                ],

                'lien-waivers' => [
                    'icon' => 'document-check',
                    'title' => 'Lien waivers',
                    'body' => 'Send and collect signed waivers with secure, account-free links.',
                    'hero' => 'Collect lien waivers without the chase',
                    'lead' => 'Send waivers and collect signatures through secure links—no accounts, no printing—so the paperwork that protects your payments is always done.',
                    'rows' => [
                        [
                            'heading' => 'Send in seconds',
                            'text' => 'Generate the right waiver and send a secure link to the sub or supplier. They sign on any device, no login required.',
                            'points' => ['Conditional and unconditional waivers', 'Secure, account-free signing links', 'Sign on any phone or computer', 'Tied to the job and payment'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Waivers · Maple St', 'rows' => [
                                ['icon' => 'document-check', 'label' => 'Rivera Plumbing', 'sub' => 'Signed · 6/12'],
                                ['icon' => 'document-check', 'label' => 'Apex Electric', 'sub' => 'Signed · 6/14'],
                                ['icon' => 'clock', 'label' => 'Summit Drywall', 'sub' => 'Sent · awaiting'],
                            ]],
                        ],
                        [
                            'heading' => 'Protected and organized',
                            'text' => 'Every signed waiver is stored against the job and payment, so when a GC or lender asks, the proof is one click away.',
                            'points' => ['Stored against the job', 'One click to retrieve', 'Track who has and has not signed', 'Protect your right to payment'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Waivers collected on time keep your payments flowing and your projects free of liens.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-check', 'title' => 'Right waiver', 'body' => 'Conditional or unconditional.'],
                        ['icon' => 'link', 'title' => 'Secure links', 'body' => 'No account to sign.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'Any device', 'body' => 'Sign from a phone.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Stored with the project.'],
                        ['icon' => 'clock', 'title' => 'Track status', 'body' => 'See who still owes.'],
                        ['icon' => 'shield-check', 'title' => 'Protected', 'body' => 'Keep payments safe.'],
                    ],
                    'cta' => ['heading' => 'Never chase a waiver again.', 'sub' => 'Send secure links and collect signatures the easy way.'],
                ],

                'insurance-certificates' => [
                    'icon' => 'shield-check',
                    'title' => 'Insurance certificates',
                    'body' => 'Store certificates of insurance and get alerts before they expire.',
                    'hero' => 'Stay covered with every COI on file',
                    'lead' => 'Keep every certificate of insurance organized and get alerted before any of them lapse—so you are never exposed on a job.',
                    'rows' => [
                        [
                            'heading' => 'Every COI in one place',
                            'text' => 'Store certificates for each sub and supplier, tied to the vendor and the jobs they work. No more digging through email for proof of coverage.',
                            'points' => ['Store COIs by vendor', 'Tied to the jobs they work', 'See coverage at a glance', 'Request updates in a tap'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Coverage · Subs', 'rows' => [
                                ['icon' => 'shield-check', 'label' => 'Rivera Plumbing', 'sub' => 'Valid to 11/30'],
                                ['icon' => 'shield-check', 'label' => 'Apex Electric', 'sub' => 'Valid to 9/15'],
                                ['icon' => 'exclamation-triangle', 'label' => 'Summit Drywall', 'sub' => 'Expires in 9 days'],
                            ]],
                        ],
                        [
                            'heading' => 'Alerts before they lapse',
                            'text' => 'Hive watches expiration dates and warns you in advance, so you can get a renewed certificate before a sub steps on site uncovered.',
                            'points' => ['Automatic expiration alerts', 'Catch lapses before they happen', 'Protect yourself from liability', 'Keep GCs and lenders satisfied'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'An expired COI you did not catch is a claim waiting to land on you. Hive makes sure you catch it.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'shield-check', 'title' => 'COIs on file', 'body' => 'Every certificate stored.'],
                        ['icon' => 'user-group', 'title' => 'By vendor', 'body' => 'Tied to each sub.'],
                        ['icon' => 'bell-alert', 'title' => 'Expiry alerts', 'body' => 'Warned in advance.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Linked to projects.'],
                        ['icon' => 'envelope', 'title' => 'Request updates', 'body' => 'Ask agents fast.'],
                        ['icon' => 'scale', 'title' => 'Less liability', 'body' => 'Never uncovered.'],
                    ],
                    'cta' => ['heading' => 'Never let coverage lapse on a job.', 'sub' => 'Every COI on file with alerts before it expires.'],
                ],

                'workers-comp' => [
                    'icon' => 'clipboard-document-check',
                    'title' => "Workers' comp",
                    'body' => 'Verify coverage and get alerts before it lapses.',
                    'hero' => 'Keep workers&rsquo; comp current—automatically',
                    'lead' => 'Verify that every sub carries workers&rsquo; comp and get warned before any policy lapses, so an injury never becomes your problem.',
                    'rows' => [
                        [
                            'heading' => 'Verify before they work',
                            'text' => 'Confirm comp coverage for each sub up front and keep the proof on file, tied to the vendor and job. No coverage, no surprises.',
                            'points' => ['Verify comp for every sub', 'Proof stored by vendor', 'Linked to the jobs they work', 'Flag anyone without coverage'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => "Workers' comp · Subs", 'rows' => [
                                ['icon' => 'clipboard-document-check', 'label' => 'Rivera Plumbing', 'sub' => 'Active'],
                                ['icon' => 'clipboard-document-check', 'label' => 'Apex Electric', 'sub' => 'Active'],
                                ['icon' => 'exclamation-triangle', 'label' => 'Summit Drywall', 'sub' => 'Lapses 7/15'],
                            ]],
                        ],
                        [
                            'heading' => 'Alerts that protect you',
                            'text' => 'Hive watches policy dates and alerts you before coverage lapses, so you are never on the hook for an uninsured crew on your site.',
                            'points' => ['Advance lapse alerts', 'Protect against claims', 'Stay audit-ready', 'Peace of mind on every site'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'One uninsured injury can sink a small contractor. Hive keeps comp current so it never does.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'clipboard-document-check', 'title' => 'Verified', 'body' => 'Coverage confirmed.'],
                        ['icon' => 'user-group', 'title' => 'By sub', 'body' => 'Proof per vendor.'],
                        ['icon' => 'bell-alert', 'title' => 'Lapse alerts', 'body' => 'Warned early.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Tied to projects.'],
                        ['icon' => 'shield-check', 'title' => 'Protected', 'body' => 'Claims covered.'],
                        ['icon' => 'check-badge', 'title' => 'Audit-ready', 'body' => 'Proof on hand.'],
                    ],
                    'cta' => ['heading' => 'Make sure every sub is covered.', 'sub' => 'Verify workers&rsquo; comp and get alerts before it lapses.'],
                ],

                'timesheets-payroll' => [
                    'icon' => 'clock',
                    'title' => 'Timesheets & payroll',
                    'body' => 'Approve crew hours and pay your team from the same place.',
                    'hero' => 'From hours to paid—without spreadsheets',
                    'lead' => 'Crews log hours from the field, you approve in a tap, and payroll flows from the same screen—with labor cost landing on every job.',
                    'rows' => [
                        [
                            'heading' => 'Hours from the field',
                            'text' => 'Your crew clocks time against the right job and task from their phone. You review the week and approve without chasing paper timecards.',
                            'points' => ['Mobile time tracking by job', 'One-tap timesheet approval', 'Labor lands in job costing', 'No paper timecards'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'This week · Maple St', 'rows' => [
                                ['icon' => 'clock', 'label' => 'Greg M. · Plumbing', 'sub' => '32.5 hrs'],
                                ['icon' => 'clock', 'label' => 'Tony R. · Framing', 'sub' => '28.0 hrs'],
                                ['icon' => 'clock', 'label' => 'Sam K. · Tile', 'sub' => '18.0 hrs'],
                            ]],
                        ],
                        [
                            'heading' => 'Pay from approved hours',
                            'text' => 'Approved time rolls straight into payments, with a running balance per worker and records that match your books.',
                            'points' => ['Payroll from approved hours', 'Running balance per worker', 'Records match your books', 'Pay your crew on time'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When hours, costing, and payroll share one flow, your crew gets paid right and your job costs stay honest.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'clock', 'title' => 'Mobile time', 'body' => 'Clock in from the job.'],
                        ['icon' => 'check-circle', 'title' => 'Approve', 'body' => 'Review hours in a tap.'],
                        ['icon' => 'banknotes', 'title' => 'Payroll', 'body' => 'Pay from approved time.'],
                        ['icon' => 'scale', 'title' => 'Balances', 'body' => 'Per-worker totals.'],
                        ['icon' => 'calculator', 'title' => 'Job costed', 'body' => 'Labor on the right job.'],
                        ['icon' => 'arrows-right-left', 'title' => 'In sync', 'body' => 'Matches your books.'],
                    ],
                    'cta' => ['heading' => 'Get payroll off the kitchen table.', 'sub' => 'Field hours, one-tap approval, and pay from the same place.'],
                ],

            ],
        ],

        'estimates' => [
            'label' => 'Estimates & Documents',
            'eyebrow' => 'Estimates & documents',
            'grid_heading' => 'Everything you need to close work',
            'cards' => [

                'ai-estimates' => [
                    'icon' => 'document-text',
                    'title' => 'AI estimates',
                    'body' => 'Draft itemized estimates in minutes and refine them your way.',
                    'hero' => 'Draft a winning estimate in minutes',
                    'lead' => 'Describe the job and let AI draft an itemized estimate you can refine, brand, and send—so you bid more work in less time.',
                    'rows' => [
                        [
                            'heading' => 'From scope to estimate, fast',
                            'text' => 'Type the scope or start from a past job and Hive drafts itemized lines with quantities and pricing. You tweak, you brand, you send.',
                            'points' => ['AI drafts itemized lines for you', 'Start from scratch or a past job', 'Adjust quantities and pricing freely', 'Send branded and ready to sign'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Draft · Kitchen remodel', 'rows' => [
                                ['label' => 'Cabinetry & install', 'value' => '$8,400'],
                                ['label' => 'Countertops', 'value' => '$3,950'],
                                ['label' => 'Tile & backsplash', 'value' => '$2,100'],
                            ]],
                        ],
                        [
                            'heading' => 'Bid more, win more',
                            'text' => 'Faster estimates mean you respond while the lead is hot. The polish helps you stand out from the contractor still scribbling on a notepad.',
                            'points' => ['Respond while leads are hot', 'Look more professional than the rest', 'Reuse winning templates', 'Turn approvals into live jobs'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The contractor who sends a clean estimate first usually wins the job. Hive helps you be that contractor.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'sparkles', 'title' => 'AI drafted', 'body' => 'Itemized in minutes.'],
                        ['icon' => 'document-duplicate', 'title' => 'Templates', 'body' => 'Reuse what wins.'],
                        ['icon' => 'pencil', 'title' => 'Editable', 'body' => 'Refine every line.'],
                        ['icon' => 'swatch', 'title' => 'Branded', 'body' => 'Looks like you.'],
                        ['icon' => 'pencil-square', 'title' => 'E-sign ready', 'body' => 'Approve online.'],
                        ['icon' => 'arrow-path', 'title' => 'To jobs', 'body' => 'Approvals start work.'],
                    ],
                    'cta' => ['heading' => 'Bid faster and win more work.', 'sub' => 'Let AI draft the estimate so you can send it first.'],
                ],

                'invoices' => [
                    'icon' => 'document-currency-dollar',
                    'title' => 'Invoices',
                    'body' => 'Send branded invoices and get paid for the work you finished.',
                    'hero' => 'Invoice the work and get paid faster',
                    'lead' => 'Send clean, branded invoices straight from the approved scope—progress or final—so the money comes in without the back-and-forth.',
                    'rows' => [
                        [
                            'heading' => 'Bill from what you agreed',
                            'text' => 'Invoice directly from the approved estimate or change orders. No re-typing, no disputes about what was included.',
                            'points' => ['Invoice from approved scope', 'Progress or final billing', 'Itemized and easy to read', 'Tied to the job and your books'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Invoice #318', 'rows' => [
                                ['label' => 'Progress · rough-in', 'value' => '$4,200'],
                                ['label' => 'Materials', 'value' => '$1,180'],
                                ['label' => 'Amount due', 'value' => '$5,380'],
                            ]],
                        ],
                        [
                            'heading' => 'Clear for clients, clean for you',
                            'text' => 'Clients see exactly what they are paying for with a due date front and center. You see what is outstanding at a glance.',
                            'points' => ['Clear due dates clients trust', 'Track what is outstanding', 'Connected to job costing', 'A record of what is paid'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A professional invoice tied to the agreed scope gets paid faster and starts fewer arguments.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-currency-dollar', 'title' => 'Branded', 'body' => 'Look professional.'],
                        ['icon' => 'arrow-path', 'title' => 'From scope', 'body' => 'No re-typing.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Due dates', 'body' => 'Clear to clients.'],
                        ['icon' => 'scale', 'title' => 'Outstanding', 'body' => 'See what is owed.'],
                        ['icon' => 'calculator', 'title' => 'Costing-linked', 'body' => 'Ties to the job.'],
                        ['icon' => 'check-circle', 'title' => 'Paid clarity', 'body' => 'Know what settled.'],
                    ],
                    'cta' => ['heading' => 'Get paid for the work you finished.', 'sub' => 'Branded invoices from the scope you already agreed on.'],
                ],

                'e-signatures' => [
                    'icon' => 'pencil-square',
                    'title' => 'E-signatures',
                    'body' => 'Collect legally-binding client signatures from any device.',
                    'hero' => 'Sign-off from any device, in seconds',
                    'lead' => 'Collect legally-binding signatures on estimates, change orders, and contracts from any phone or computer—no printing, no scanning.',
                    'rows' => [
                        [
                            'heading' => 'Approval without the paperwork',
                            'text' => 'Send a document and your client signs with a tap, wherever they are. The approval is captured and timestamped instantly.',
                            'points' => ['Sign on any device', 'Legally-binding and timestamped', 'No printing or scanning', 'Approval recorded instantly'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Signature · Estimate #1042', 'rows' => [
                                ['icon' => 'pencil-square', 'label' => 'Sent to client', 'sub' => 'Mon 9:10 AM'],
                                ['icon' => 'eye', 'label' => 'Opened', 'sub' => 'Mon 9:14 AM'],
                                ['icon' => 'check-badge', 'label' => 'Signed', 'sub' => 'Mon 9:21 AM'],
                            ]],
                        ],
                        [
                            'heading' => 'Protect every agreement',
                            'text' => 'Signed documents are stored against the job, so there is always proof of what was agreed and when—no he-said-she-said.',
                            'points' => ['Stored against the job', 'Proof of what was agreed', 'Easy to retrieve later', 'Keeps everyone honest'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A signature you can prove is the difference between getting paid and eating the cost.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'pencil-square', 'title' => 'E-sign', 'body' => 'Tap to approve.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'Any device', 'body' => 'Phone or computer.'],
                        ['icon' => 'shield-check', 'title' => 'Binding', 'body' => 'Legally valid.'],
                        ['icon' => 'clock', 'title' => 'Timestamped', 'body' => 'When it happened.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Stored with proof.'],
                        ['icon' => 'eye', 'title' => 'Open tracking', 'body' => 'See when viewed.'],
                    ],
                    'cta' => ['heading' => 'Get sign-off without the paperwork.', 'sub' => 'Legally-binding signatures from any device, stored with the job.'],
                ],

                'change-orders' => [
                    'icon' => 'arrows-right-left',
                    'title' => 'Change orders',
                    'body' => 'Capture scope and price changes so nothing is done for free.',
                    'hero' => 'Get paid for every change',
                    'lead' => 'Capture scope and price changes the moment they come up, get them approved, and make sure no extra work goes unpaid.',
                    'rows' => [
                        [
                            'heading' => 'Document the change',
                            'text' => 'When the job changes, write a clear change order with the added work and cost. The client approves before you lift a tool.',
                            'points' => ['Capture added scope and cost', 'Approved before work starts', 'Clear record of what changed', 'No more free upgrades'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Change order · Can lighting', 'rows' => [
                                ['label' => '6 recessed lights', 'value' => '+$1,250'],
                                ['label' => 'Schedule impact', 'value' => '+1 day'],
                                ['label' => 'Status', 'value' => 'Approved'],
                            ]],
                        ],
                        [
                            'heading' => 'Flows into the invoice',
                            'text' => 'Approved change orders roll into the job and the next invoice automatically, so the extra work always shows up on the bill.',
                            'points' => ['Rolls into the job total', 'Billed on the next invoice', 'Protects your margin', 'No surprises at the end'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The work that kills margin is the change nobody wrote down. Hive makes sure it gets written down—and billed.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'arrows-right-left', 'title' => 'Capture', 'body' => 'Scope and price.'],
                        ['icon' => 'pencil-square', 'title' => 'Approved', 'body' => 'Signed before work.'],
                        ['icon' => 'document-text', 'title' => 'Documented', 'body' => 'Clear record.'],
                        ['icon' => 'banknotes', 'title' => 'Billed', 'body' => 'On the next invoice.'],
                        ['icon' => 'calculator', 'title' => 'Margin-safe', 'body' => 'Nothing free.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Schedule', 'body' => 'Shows time impact.'],
                    ],
                    'cta' => ['heading' => 'Stop doing extra work for free.', 'sub' => 'Capture every change, get it approved, and bill it.'],
                ],

                'bids-proposals' => [
                    'icon' => 'clipboard-document-list',
                    'title' => 'Bids & proposals',
                    'body' => 'Track every bid from sent to signed and follow up on time.',
                    'hero' => 'Never let a bid go cold',
                    'lead' => 'Track every proposal from sent to signed, see what is outstanding, and follow up at the right time—so more of your bids turn into jobs.',
                    'rows' => [
                        [
                            'heading' => 'Your whole pipeline in view',
                            'text' => 'See every bid you have out, where it stands, and how long it has been sitting. The ones that need a nudge are obvious.',
                            'points' => ['Track bids from sent to signed', 'See what is outstanding', 'Know which need follow-up', 'Measure your win rate'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Open bids', 'rows' => [
                                ['icon' => 'clipboard-document-list', 'label' => 'Maple St remodel', 'sub' => 'Sent · 3 days ago'],
                                ['icon' => 'eye', 'label' => 'Oak Ave addition', 'sub' => 'Viewed · follow up'],
                                ['icon' => 'check-badge', 'label' => 'Pine Ct deck', 'sub' => 'Signed'],
                            ]],
                        ],
                        [
                            'heading' => 'Follow up at the right time',
                            'text' => 'Hive nudges you when a proposal has gone quiet, so the work you bid does not slip away to the contractor who simply called back.',
                            'points' => ['Timely follow-up reminders', 'See when a bid was opened', 'Close more of what you send', 'Stop losing work to silence'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Most bids are lost to silence, not price. Following up on time wins jobs you already earned.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'clipboard-document-list', 'title' => 'Pipeline', 'body' => 'Every bid tracked.'],
                        ['icon' => 'eye', 'title' => 'Open tracking', 'body' => 'See when viewed.'],
                        ['icon' => 'bell-alert', 'title' => 'Follow-ups', 'body' => 'Nudged on time.'],
                        ['icon' => 'check-badge', 'title' => 'Won', 'body' => 'Signed to jobs.'],
                        ['icon' => 'chart-bar', 'title' => 'Win rate', 'body' => 'Measure success.'],
                        ['icon' => 'arrow-path', 'title' => 'To projects', 'body' => 'Start work fast.'],
                    ],
                    'cta' => ['heading' => 'Turn more bids into signed jobs.', 'sub' => 'Track every proposal and follow up before it goes cold.'],
                ],

                'lien-waivers' => [
                    'icon' => 'document-check',
                    'title' => 'Lien waivers',
                    'body' => 'Send and collect signed waivers with secure, account-free links.',
                    'hero' => 'Lien waivers, signed and on file',
                    'lead' => 'Send waivers and collect signatures through secure links with no accounts or printing—keeping the paperwork that protects payment always done.',
                    'rows' => [
                        [
                            'heading' => 'Send and sign in seconds',
                            'text' => 'Generate the right waiver, send a secure link, and let your sub sign on any device. It comes back tied to the job and payment.',
                            'points' => ['Conditional and unconditional waivers', 'Secure, account-free links', 'Sign from any phone', 'Tied to the job and payment'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Waivers · Maple St', 'rows' => [
                                ['icon' => 'document-check', 'label' => 'Rivera Plumbing', 'sub' => 'Signed'],
                                ['icon' => 'document-check', 'label' => 'Apex Electric', 'sub' => 'Signed'],
                                ['icon' => 'clock', 'label' => 'Summit Drywall', 'sub' => 'Awaiting'],
                            ]],
                        ],
                        [
                            'heading' => 'Proof when it counts',
                            'text' => 'Signed waivers are stored against the job, so when a GC or lender asks for them, the answer is one click away.',
                            'points' => ['Stored against the job', 'One click to produce', 'Track who still owes', 'Protect your right to payment'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Waivers collected on time keep payments flowing and projects lien-free.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-check', 'title' => 'Right waiver', 'body' => 'Conditional or not.'],
                        ['icon' => 'link', 'title' => 'Secure links', 'body' => 'No account needed.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'Any device', 'body' => 'Sign from a phone.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Stored with proof.'],
                        ['icon' => 'clock', 'title' => 'Track status', 'body' => 'Who still owes.'],
                        ['icon' => 'shield-check', 'title' => 'Protected', 'body' => 'Payments safe.'],
                    ],
                    'cta' => ['heading' => 'Keep every waiver signed and on file.', 'sub' => 'Secure links your subs can sign from anywhere.'],
                ],

            ],
        ],

        'clients' => [
            'label' => 'Leads & Clients',
            'eyebrow' => 'Leads & clients',
            'grid_heading' => 'From first call to happy homeowner',
            'cards' => [

                'lead-pipeline' => [
                    'icon' => 'magnifying-glass-plus',
                    'title' => 'Lead pipeline',
                    'body' => 'Capture and track new opportunities so none slip away.',
                    'hero' => 'Catch every lead before it slips away',
                    'lead' => 'Capture new opportunities in one pipeline, track where each stands, and follow up on time so the calls you worked hard to earn turn into jobs.',
                    'rows' => [
                        [
                            'heading' => 'One place for every opportunity',
                            'text' => 'New inquiries land in your pipeline with the details you need. Move them through stages so you always know what is hot and what is next.',
                            'points' => ['Capture leads from calls and forms', 'Track each through clear stages', 'Add notes, value, and next steps', 'Nothing falls through the cracks'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Pipeline', 'rows' => [
                                ['icon' => 'magnifying-glass-plus', 'label' => 'Maple St remodel', 'sub' => 'New · est. $48k'],
                                ['icon' => 'phone', 'label' => 'Oak Ave addition', 'sub' => 'Contacted'],
                                ['icon' => 'clipboard-document-list', 'label' => 'Pine Ct deck', 'sub' => 'Bid sent'],
                            ]],
                        ],
                        [
                            'heading' => 'Follow up and win',
                            'text' => 'Reminders keep you on top of every lead, so you reach out while interest is high—not a week after they hired someone else.',
                            'points' => ['Timely follow-up reminders', 'Reach out while interest is high', 'See your conversion at a glance', 'Win more of what you chase'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Leads go cold fast. A pipeline that nudges you turns more first calls into signed jobs.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'magnifying-glass-plus', 'title' => 'Capture', 'body' => 'Every opportunity in.'],
                        ['icon' => 'view-columns', 'title' => 'Stages', 'body' => 'Track each step.'],
                        ['icon' => 'bell-alert', 'title' => 'Follow-ups', 'body' => 'Reminded on time.'],
                        ['icon' => 'pencil-square', 'title' => 'Notes', 'body' => 'Context on each.'],
                        ['icon' => 'chart-bar', 'title' => 'Conversion', 'body' => 'See win rate.'],
                        ['icon' => 'arrow-path', 'title' => 'To clients', 'body' => 'Won in one click.'],
                    ],
                    'cta' => ['heading' => 'Turn more first calls into jobs.', 'sub' => 'Capture every lead and follow up before it goes cold.'],
                ],

                'lead-to-client' => [
                    'icon' => 'arrow-path',
                    'title' => 'Lead-to-client',
                    'body' => 'Convert won leads into clients and projects in one click.',
                    'hero' => 'Won the job? Start it in one click',
                    'lead' => 'Convert a won lead into a client and a live project instantly—carrying the contact, notes, and estimate with it so nothing is re-entered.',
                    'rows' => [
                        [
                            'heading' => 'No re-typing, no lost context',
                            'text' => 'When a lead says yes, turn it into a client and project with one click. Their info, history, and estimate come along automatically.',
                            'points' => ['Convert lead to client instantly', 'Spin up the project at the same time', 'Carry contact, notes, and estimate', 'Zero duplicate data entry'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Convert · Maple St', 'rows' => [
                                ['icon' => 'user-plus', 'label' => 'Client created', 'sub' => 'The Hendersons'],
                                ['icon' => 'folder-plus', 'label' => 'Project started', 'sub' => 'Kitchen remodel'],
                                ['icon' => 'document-text', 'label' => 'Estimate attached', 'sub' => '$48,000'],
                            ]],
                        ],
                        [
                            'heading' => 'Hit the ground running',
                            'text' => 'The new project is ready for scheduling, costing, and client updates from minute one, so you start strong instead of setting up.',
                            'points' => ['Project ready to schedule', 'Costing starts immediately', 'Client portal available', 'Strong, organized start'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The moment a client says yes is the moment to get organized—not the moment to start data entry.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'arrow-path', 'title' => 'One click', 'body' => 'Lead to client.'],
                        ['icon' => 'folder-plus', 'title' => 'Project', 'body' => 'Created at once.'],
                        ['icon' => 'document-text', 'title' => 'Estimate', 'body' => 'Comes along.'],
                        ['icon' => 'clipboard', 'title' => 'History kept', 'body' => 'All notes carry.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Schedule-ready', 'body' => 'Start planning.'],
                        ['icon' => 'computer-desktop', 'title' => 'Portal on', 'body' => 'Client can see it.'],
                    ],
                    'cta' => ['heading' => 'Start the job the second they say yes.', 'sub' => 'Convert a lead to a client and project in one click.'],
                ],

                'client-directory' => [
                    'icon' => 'identification',
                    'title' => 'Client directory',
                    'body' => 'Every homeowner with their full job and contact history.',
                    'hero' => 'Every client and their whole history',
                    'lead' => 'Keep every homeowner in one directory with their contact details, projects, payments, and conversations—so you always have the full picture.',
                    'rows' => [
                        [
                            'heading' => 'The full picture, one place',
                            'text' => 'Open a client and see every job you have done, what they have paid, and your whole conversation history. No more hunting across apps.',
                            'points' => ['All projects per client', 'Payment and balance history', 'Full conversation thread', 'Contact details always current'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'The Hendersons', 'rows' => [
                                ['icon' => 'folder', 'label' => 'Kitchen remodel', 'sub' => 'In progress'],
                                ['icon' => 'folder', 'label' => 'Bathroom · 2024', 'sub' => 'Completed'],
                                ['icon' => 'banknotes', 'label' => 'Lifetime billed', 'sub' => '$71,500'],
                            ]],
                        ],
                        [
                            'heading' => 'Repeat work made easy',
                            'text' => 'When a past client calls again, you already know their home, their preferences, and their history—so the next job starts on the right foot.',
                            'points' => ['Know returning clients instantly', 'Reference past work and notes', 'Personalize every interaction', 'Win more repeat business'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Your best leads are past clients. Knowing their history makes the next job easier to win and run.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'identification', 'title' => 'Directory', 'body' => 'Every client in one place.'],
                        ['icon' => 'folder', 'title' => 'All projects', 'body' => 'Past and present.'],
                        ['icon' => 'banknotes', 'title' => 'Payments', 'body' => 'Full money history.'],
                        ['icon' => 'chat-bubble-left-right', 'title' => 'Conversations', 'body' => 'Every thread.'],
                        ['icon' => 'phone', 'title' => 'Contacts', 'body' => 'Always current.'],
                        ['icon' => 'arrow-path', 'title' => 'Repeat work', 'body' => 'Start fast.'],
                    ],
                    'cta' => ['heading' => 'Keep every client at your fingertips.', 'sub' => 'Full job, payment, and conversation history in one directory.'],
                ],

                'homeowner-portal' => [
                    'icon' => 'computer-desktop',
                    'title' => 'Homeowner portal',
                    'body' => 'A real-time window into the project clients can check anytime.',
                    'hero' => 'Give clients a window into their project',
                    'lead' => 'A private, real-time portal lets homeowners see status, schedule, photos, documents, and payments anytime—so they call you less and trust you more.',
                    'rows' => [
                        [
                            'heading' => 'Always in the loop',
                            'text' => 'Clients open a secure link and see exactly where their project stands. Fewer "any update?" texts, more confidence in you.',
                            'points' => ['Live status and schedule', 'Job-site photos and progress', 'Documents and payments', 'Secure, no app required'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Client view · Maple St', 'rows' => [
                                ['icon' => 'eye', 'label' => 'Status', 'sub' => '62% · electrical'],
                                ['icon' => 'calendar-date-range', 'label' => 'Next visit', 'sub' => 'Tue 6/30'],
                                ['icon' => 'photo', 'label' => 'New photos', 'sub' => '4 added'],
                            ]],
                        ],
                        [
                            'heading' => 'Less hand-holding for you',
                            'text' => 'When clients can answer their own questions, you spend less time on the phone and more time building. Updates flow without extra effort.',
                            'points' => ['Cut down status calls and texts', 'Updates push automatically', 'Sets you apart from competitors', 'Happier, calmer clients'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A client who can see progress is a client who trusts you—and interrupts your day far less.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'eye', 'title' => 'Live status', 'body' => 'Always current.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Schedule', 'body' => 'What is next.'],
                        ['icon' => 'photo', 'title' => 'Photos', 'body' => 'Progress shots.'],
                        ['icon' => 'pencil-square', 'title' => 'Documents', 'body' => 'Review and sign.'],
                        ['icon' => 'banknotes', 'title' => 'Payments', 'body' => 'See balances.'],
                        ['icon' => 'finger-print', 'title' => 'Secure', 'body' => 'Private link.'],
                    ],
                    'cta' => ['heading' => 'Give clients a portal they love.', 'sub' => 'Real-time project access that cuts the status calls.'],
                ],

                'schedule-sharing' => [
                    'icon' => 'paper-airplane',
                    'title' => 'Schedule sharing',
                    'body' => 'Send live "what is next" updates without lifting a finger.',
                    'hero' => 'Share what is next—automatically',
                    'lead' => 'Send clients a live schedule link that always shows the next visit and milestone, so they stay informed without you sending a single update.',
                    'rows' => [
                        [
                            'heading' => 'A live link, not a phone call',
                            'text' => 'Clients get a schedule that updates itself. When a date moves, their view moves too—no new email, no awkward call.',
                            'points' => ['Live "what is next" for clients', 'Updates the moment dates change', 'No manual update messages', 'Works on any device'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Client schedule', 'rows' => [
                                ['icon' => 'calendar-date-range', 'label' => 'Electrical rough-in', 'sub' => 'Tue 6/30'],
                                ['icon' => 'clipboard-document-check', 'label' => 'Inspection', 'sub' => 'Thu 7/2'],
                                ['icon' => 'swatch', 'label' => 'Finishes begin', 'sub' => 'Mon 7/6'],
                            ]],
                        ],
                        [
                            'heading' => 'Fewer surprises, fewer calls',
                            'text' => 'When clients can see the plan, they stop asking and start trusting. Changes are communicated the instant they happen.',
                            'points' => ['Clients always know the plan', 'Changes communicated instantly', 'Fewer "when are you coming?" calls', 'A more professional experience'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Most client frustration is just not knowing. A live schedule fixes that without adding to your day.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'paper-airplane', 'title' => 'Live link', 'body' => 'Always current.'],
                        ['icon' => 'bolt', 'title' => 'Auto-updates', 'body' => 'When dates move.'],
                        ['icon' => 'calendar-date-range', 'title' => 'What is next', 'body' => 'Upcoming visits.'],
                        ['icon' => 'flag', 'title' => 'Milestones', 'body' => 'Big moments shown.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'Any device', 'body' => 'No app needed.'],
                        ['icon' => 'face-smile', 'title' => 'Fewer calls', 'body' => 'Clients self-serve.'],
                    ],
                    'cta' => ['heading' => 'Keep clients informed on autopilot.', 'sub' => 'A live schedule link that updates itself.'],
                ],

                'contact-sync' => [
                    'icon' => 'at-symbol',
                    'title' => 'Contact sync',
                    'body' => 'Contacts flow in from your email so records stay current.',
                    'hero' => 'Contacts that keep themselves current',
                    'lead' => 'Hive pulls contacts in from your email so client and vendor records stay up to date without you maintaining a separate address book.',
                    'rows' => [
                        [
                            'heading' => 'No more double entry',
                            'text' => 'New people you email show up in Hive with their details, ready to attach to a lead, client, or vendor. Your records build themselves.',
                            'points' => ['Contacts flow in from email', 'Attach to leads, clients, or vendors', 'Details stay current automatically', 'No separate address book to keep'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Synced contacts', 'rows' => [
                                ['icon' => 'at-symbol', 'label' => 'J. Henderson', 'sub' => 'Client · Maple St'],
                                ['icon' => 'at-symbol', 'label' => 'Rivera Plumbing', 'sub' => 'Vendor'],
                                ['icon' => 'at-symbol', 'label' => 'City Inspector', 'sub' => 'Contact'],
                            ]],
                        ],
                        [
                            'heading' => 'Everyone connected to the work',
                            'text' => 'Because contacts tie to jobs and conversations, you always know how each person fits—and reach them in a tap.',
                            'points' => ['Linked to jobs and threads', 'Reach anyone in a tap', 'Records stay accurate', 'Less admin, more building'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The address book you never have to maintain is the one that is actually up to date when you need it.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'at-symbol', 'title' => 'Email sync', 'body' => 'Contacts flow in.'],
                        ['icon' => 'user-plus', 'title' => 'Auto-add', 'body' => 'New people captured.'],
                        ['icon' => 'arrow-path', 'title' => 'Current', 'body' => 'Details stay fresh.'],
                        ['icon' => 'folder', 'title' => 'Linked', 'body' => 'Tied to jobs.'],
                        ['icon' => 'phone', 'title' => 'One-tap reach', 'body' => 'Call or email fast.'],
                        ['icon' => 'sparkles', 'title' => 'Less admin', 'body' => 'No manual entry.'],
                    ],
                    'cta' => ['heading' => 'Stop maintaining an address book.', 'sub' => 'Let contacts sync in and stay current on their own.'],
                ],

            ],
        ],

        'vendors' => [
            'label' => 'Vendors & Compliance',
            'eyebrow' => 'Vendors & compliance',
            'grid_heading' => 'Keep your subs in sync—and covered',
            'cards' => [

                'vendor-directory' => [
                    'icon' => 'user-group',
                    'title' => 'Vendor directory',
                    'body' => 'Every sub and supplier with trade, rates, and job history.',
                    'hero' => 'Every sub and supplier at your fingertips',
                    'lead' => 'Keep every vendor in one directory with their trade, rates, contact info, and job history—so you always know who to call and what they cost.',
                    'rows' => [
                        [
                            'heading' => 'Your whole bench, organized',
                            'text' => 'Store each sub and supplier with the details that matter: trade, typical rates, contacts, and every job they have worked for you.',
                            'points' => ['Trade, rates, and contacts', 'Full job history per vendor', 'Notes on quality and reliability', 'Find the right sub fast'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Subs · Plumbing', 'rows' => [
                                ['icon' => 'user-group', 'label' => 'Rivera Plumbing', 'sub' => '12 jobs · $42/hr'],
                                ['icon' => 'user-group', 'label' => 'Apex Mechanical', 'sub' => '5 jobs'],
                                ['icon' => 'user-group', 'label' => 'BlueLine Plumbing', 'sub' => '2 jobs'],
                            ]],
                        ],
                        [
                            'heading' => 'Connected to everything',
                            'text' => 'Each vendor links to their payments, insurance, and the jobs they are on, so the full relationship is one click away.',
                            'points' => ['Linked to payments and balances', 'Tied to COIs and coverage', 'See current and past jobs', 'Reach them in a tap'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Knowing exactly who to call—and what they cost—turns staffing a job into a two-minute task.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'user-group', 'title' => 'Directory', 'body' => 'Every vendor in one place.'],
                        ['icon' => 'wrench', 'title' => 'Trade & rates', 'body' => 'Know who and how much.'],
                        ['icon' => 'folder', 'title' => 'Job history', 'body' => 'Everything they worked.'],
                        ['icon' => 'wallet', 'title' => 'Payments', 'body' => 'Balances linked.'],
                        ['icon' => 'shield-check', 'title' => 'Compliance', 'body' => 'COIs attached.'],
                        ['icon' => 'phone', 'title' => 'One-tap reach', 'body' => 'Call or text fast.'],
                    ],
                    'cta' => ['heading' => 'Know exactly who to call.', 'sub' => 'Every sub and supplier with rates, history, and coverage.'],
                ],

                'vendor-payments' => [
                    'icon' => 'wallet',
                    'title' => 'Vendor payments',
                    'body' => 'Pay subs and keep every payment tied to the right job.',
                    'hero' => 'Pay subs and keep the job straight',
                    'lead' => 'Record and track payments to every sub and supplier with each dollar tied to the right job and balance, so labor cost always lands where it belongs.',
                    'rows' => [
                        [
                            'heading' => 'On the right job, every time',
                            'text' => 'When you pay a sub, the cost attaches to the project automatically and the vendor balance updates. No more guessing which job a payment covered.',
                            'points' => ['Pay subs and suppliers easily', 'Cost lands on the right job', 'Running balance per vendor', 'Clean records for 1099s'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Rivera Plumbing', 'rows' => [
                                ['label' => 'Invoiced', 'value' => '$6,400'],
                                ['label' => 'Paid', 'value' => '$4,000'],
                                ['label' => 'Balance', 'value' => '$2,400'],
                            ]],
                        ],
                        [
                            'heading' => 'Pay the subs who keep you covered',
                            'text' => 'Payments connect to each vendor&rsquo;s insurance and comp, so you can spot expired paperwork before you cut the next check.',
                            'points' => ['Linked to COIs and comp', 'Flag expired paperwork first', 'Feeds job costing', 'Matches your bank feed'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Paying subs through Hive keeps cost, balances, and compliance in one place—no separate spreadsheet.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'wallet', 'title' => 'Pay subs', 'body' => 'From one place.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Cost on the project.'],
                        ['icon' => 'scale', 'title' => 'Balances', 'body' => 'Per vendor.'],
                        ['icon' => 'shield-check', 'title' => 'Compliance', 'body' => 'COIs linked.'],
                        ['icon' => 'document-text', 'title' => '1099-ready', 'body' => 'Clean year-end.'],
                        ['icon' => 'calculator', 'title' => 'Feeds costing', 'body' => 'Labor tracked.'],
                    ],
                    'cta' => ['heading' => 'Pay your subs without losing the thread.', 'sub' => 'Every payout tied to the job, balance, and coverage.'],
                ],

                'coi-tracking' => [
                    'icon' => 'shield-check',
                    'title' => 'COI tracking',
                    'body' => 'Store certificates of insurance and watch expiration dates.',
                    'hero' => 'Never let a certificate slip past',
                    'lead' => 'Store every certificate of insurance, tie it to the vendor and job, and get alerted before any of them expire—so you are never exposed.',
                    'rows' => [
                        [
                            'heading' => 'Every COI on file',
                            'text' => 'Keep certificates organized by vendor and connected to the jobs they work. Proof of coverage is always one search away.',
                            'points' => ['Store COIs by vendor', 'Tied to the jobs they work', 'See coverage status at a glance', 'Request updates from agents fast'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Coverage status', 'rows' => [
                                ['icon' => 'shield-check', 'label' => 'Rivera Plumbing', 'sub' => 'Valid to 11/30'],
                                ['icon' => 'shield-check', 'label' => 'Apex Electric', 'sub' => 'Valid to 9/15'],
                                ['icon' => 'exclamation-triangle', 'label' => 'Summit Drywall', 'sub' => 'Expires in 9 days'],
                            ]],
                        ],
                        [
                            'heading' => 'Alerts before it lapses',
                            'text' => 'Hive watches expiration dates and warns you in advance, so you can collect a renewed certificate before a sub works uncovered.',
                            'points' => ['Automatic expiration alerts', 'Catch lapses before they happen', 'Reduce your liability', 'Satisfy GCs and lenders'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'An expired COI you missed is a claim waiting to land on you. Hive makes sure you never miss one.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'shield-check', 'title' => 'COIs stored', 'body' => 'Every certificate.'],
                        ['icon' => 'user-group', 'title' => 'By vendor', 'body' => 'Organized per sub.'],
                        ['icon' => 'bell-alert', 'title' => 'Expiry alerts', 'body' => 'Warned in advance.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Linked to projects.'],
                        ['icon' => 'envelope', 'title' => 'Request', 'body' => 'Ask agents fast.'],
                        ['icon' => 'scale', 'title' => 'Less liability', 'body' => 'Never uncovered.'],
                    ],
                    'cta' => ['heading' => 'Keep every COI current.', 'sub' => 'Store certificates and get alerts before they expire.'],
                ],

                'workers-comp' => [
                    'icon' => 'clipboard-document-check',
                    'title' => "Workers' comp",
                    'body' => 'Verify coverage and get alerts before it lapses.',
                    'hero' => 'Make sure every sub is covered',
                    'lead' => 'Verify workers&rsquo; comp for each sub, store the proof, and get warned before any policy lapses—so an injury never becomes your liability.',
                    'rows' => [
                        [
                            'heading' => 'Verify before they step on site',
                            'text' => 'Confirm comp coverage up front and keep the proof tied to the vendor and job. Anyone without coverage is flagged before they work.',
                            'points' => ['Verify comp for every sub', 'Proof stored by vendor', 'Linked to the jobs they work', 'Flag the uncovered'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => "Workers' comp", 'rows' => [
                                ['icon' => 'clipboard-document-check', 'label' => 'Rivera Plumbing', 'sub' => 'Active'],
                                ['icon' => 'clipboard-document-check', 'label' => 'Apex Electric', 'sub' => 'Active'],
                                ['icon' => 'exclamation-triangle', 'label' => 'Summit Drywall', 'sub' => 'Lapses 7/15'],
                            ]],
                        ],
                        [
                            'heading' => 'Protected from the claim you did not see',
                            'text' => 'Advance lapse alerts mean you never have an uninsured crew on your site, keeping you off the hook if something goes wrong.',
                            'points' => ['Advance lapse alerts', 'Protect against claims', 'Stay audit-ready', 'Peace of mind on site'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'One uninsured injury can sink a small contractor. Hive keeps comp current so it never does.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'clipboard-document-check', 'title' => 'Verified', 'body' => 'Coverage confirmed.'],
                        ['icon' => 'user-group', 'title' => 'By sub', 'body' => 'Proof per vendor.'],
                        ['icon' => 'bell-alert', 'title' => 'Lapse alerts', 'body' => 'Warned early.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Tied to projects.'],
                        ['icon' => 'shield-check', 'title' => 'Protected', 'body' => 'Claims covered.'],
                        ['icon' => 'check-badge', 'title' => 'Audit-ready', 'body' => 'Proof on hand.'],
                    ],
                    'cta' => ['heading' => 'Keep workers&rsquo; comp current.', 'sub' => 'Verify coverage and get alerts before it lapses.'],
                ],

                'insurance-agents' => [
                    'icon' => 'building-office-2',
                    'title' => 'Insurance agents',
                    'body' => 'Keep agent contacts handy for fast certificate requests.',
                    'hero' => 'Get certificates without the runaround',
                    'lead' => 'Keep each vendor&rsquo;s insurance agent on file so a fresh certificate or comp verification is a quick request, not a week of phone tag.',
                    'rows' => [
                        [
                            'heading' => 'The right agent, on hand',
                            'text' => 'Store the agency and agent behind every vendor&rsquo;s coverage. When you need an updated COI, you know exactly who to ask.',
                            'points' => ['Agent contacts by vendor', 'Request updates in a tap', 'No hunting for who to call', 'Faster turnaround on COIs'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Agents', 'rows' => [
                                ['icon' => 'building-office-2', 'label' => 'Coast Insurance', 'sub' => 'Rivera Plumbing'],
                                ['icon' => 'building-office-2', 'label' => 'Summit Agency', 'sub' => 'Apex Electric'],
                                ['icon' => 'building-office-2', 'label' => 'Harbor Group', 'sub' => 'Summit Drywall'],
                            ]],
                        ],
                        [
                            'heading' => 'Renewals without the delay',
                            'text' => 'When a certificate is about to lapse, reach the agent directly from Hive and keep your project covered without losing days.',
                            'points' => ['Contact agents from Hive', 'Tie requests to the vendor', 'Keep projects covered', 'No costly coverage gaps'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The fastest way to fix an expiring COI is knowing exactly which agent to email—Hive keeps that one tap away.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'building-office-2', 'title' => 'Agents on file', 'body' => 'Per vendor.'],
                        ['icon' => 'envelope', 'title' => 'Quick request', 'body' => 'Ask in a tap.'],
                        ['icon' => 'shield-check', 'title' => 'Coverage-linked', 'body' => 'Tied to COIs.'],
                        ['icon' => 'clock', 'title' => 'Faster COIs', 'body' => 'No phone tag.'],
                        ['icon' => 'user-group', 'title' => 'By vendor', 'body' => 'Know who to ask.'],
                        ['icon' => 'check-circle', 'title' => 'No gaps', 'body' => 'Stay covered.'],
                    ],
                    'cta' => ['heading' => 'Get certificates without the runaround.', 'sub' => 'Every vendor&rsquo;s agent on file for fast requests.'],
                ],

                'document-audits' => [
                    'icon' => 'document-magnifying-glass',
                    'title' => 'Document audits',
                    'body' => 'Automated checks so missing paperwork surfaces early.',
                    'hero' => 'Catch missing paperwork before it bites',
                    'lead' => 'Automated audits scan your vendors and jobs for missing or expiring documents, so gaps surface early—not when a GC or inspector asks.',
                    'rows' => [
                        [
                            'heading' => 'A standing check on your files',
                            'text' => 'Hive continuously checks for missing COIs, lapsed comp, unsigned waivers, and incomplete vendor records, then shows you exactly what is wrong.',
                            'points' => ['Scan for missing documents', 'Flag expiring coverage', 'Spot unsigned waivers', 'See gaps by vendor and job'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Audit · Maple St', 'rows' => [
                                ['icon' => 'exclamation-triangle', 'label' => 'Summit Drywall', 'sub' => 'COI expiring'],
                                ['icon' => 'exclamation-triangle', 'label' => 'Apex Electric', 'sub' => 'Waiver unsigned'],
                                ['icon' => 'check-circle', 'label' => 'Rivera Plumbing', 'sub' => 'All clear'],
                            ]],
                        ],
                        [
                            'heading' => 'Always ready for inspection',
                            'text' => 'When the GC, lender, or inspector asks for paperwork, you are ready—because Hive already told you what was missing and you fixed it.',
                            'points' => ['Fix gaps before anyone asks', 'Stay audit and inspection ready', 'Reduce compliance risk', 'Protect your reputation'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Missing paperwork found early is a quick email. Found during an audit, it can stop a job cold.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-magnifying-glass', 'title' => 'Auto-audit', 'body' => 'Standing checks.'],
                        ['icon' => 'shield-check', 'title' => 'Missing COIs', 'body' => 'Surfaced early.'],
                        ['icon' => 'clipboard-document-check', 'title' => 'Lapsed comp', 'body' => 'Flagged fast.'],
                        ['icon' => 'document-check', 'title' => 'Unsigned waivers', 'body' => 'Caught in time.'],
                        ['icon' => 'folder', 'title' => 'By job', 'body' => 'Gaps per project.'],
                        ['icon' => 'check-badge', 'title' => 'Audit-ready', 'body' => 'Always prepared.'],
                    ],
                    'cta' => ['heading' => 'Find the gap before the auditor does.', 'sub' => 'Automated checks surface missing paperwork early.'],
                ],

            ],
        ],

        'planning' => [
            'label' => 'Planning',
            'eyebrow' => 'Projects & planning',
            'grid_heading' => 'Plan the work and work the plan',
            'cards' => [

                'gantt' => [
                    'icon' => 'calendar-date-range',
                    'title' => 'Gantt timeline',
                    'body' => 'Drag-and-drop scheduling with dependencies across every job.',
                    'hero' => 'See every job on one timeline',
                    'lead' => 'Drag-and-drop scheduling with dependencies lets you plan crews across every project at once—so you stop overbooking and start finishing on time.',
                    'rows' => [
                        [
                            'heading' => 'Schedule by dragging',
                            'text' => 'Lay out tasks on a visual timeline, set what depends on what, and shift dates by dragging. The whole plan adjusts around the change.',
                            'points' => ['Drag-and-drop task scheduling', 'Dependencies that auto-shift', 'See all jobs at once', 'Spot conflicts before they happen'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Timeline · This week', 'rows' => [
                                ['icon' => 'calendar-date-range', 'label' => 'Demo · Maple St', 'sub' => 'Mon–Tue'],
                                ['icon' => 'calendar-date-range', 'label' => 'Rough-in · Oak Ave', 'sub' => 'Wed–Fri'],
                                ['icon' => 'calendar-date-range', 'label' => 'Inspection · Pine Ct', 'sub' => 'Thu'],
                            ]],
                        ],
                        [
                            'heading' => 'Finish jobs on time',
                            'text' => 'When a date slips, dependencies move with it and you see the ripple instantly—so you can react before a delay snowballs.',
                            'points' => ['Delays ripple visibly', 'React before they snowball', 'Keep crews fully booked', 'Hit your completion dates'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A timeline that shows the ripple of every delay is how small contractors keep multiple jobs on track at once.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'calendar-date-range', 'title' => 'Timeline', 'body' => 'All jobs at once.'],
                        ['icon' => 'arrows-pointing-out', 'title' => 'Drag & drop', 'body' => 'Reschedule fast.'],
                        ['icon' => 'link', 'title' => 'Dependencies', 'body' => 'Auto-shift dates.'],
                        ['icon' => 'exclamation-triangle', 'title' => 'Conflicts', 'body' => 'Caught early.'],
                        ['icon' => 'user-group', 'title' => 'Crew view', 'body' => 'Who is where.'],
                        ['icon' => 'flag', 'title' => 'Milestones', 'body' => 'Track key dates.'],
                    ],
                    'cta' => ['heading' => 'Keep every job on schedule.', 'sub' => 'One drag-and-drop timeline across all your projects.'],
                ],

                'kanban' => [
                    'icon' => 'view-columns',
                    'title' => 'Kanban board',
                    'body' => 'Move work through stages on a board the whole crew understands.',
                    'hero' => 'Move work across a board everyone gets',
                    'lead' => 'A simple board moves tasks through stages your whole crew understands at a glance—so everyone knows what is next without a meeting.',
                    'rows' => [
                        [
                            'heading' => 'Stages anyone can follow',
                            'text' => 'Drag cards from to-do to doing to done. The board makes the state of the work obvious to the office and the field alike.',
                            'points' => ['Visual stages for every task', 'Drag cards as work moves', 'Assign owners and due dates', 'Clear to office and field'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Board · Maple St', 'rows' => [
                                ['icon' => 'view-columns', 'label' => 'To do', 'sub' => 'Tile, paint'],
                                ['icon' => 'view-columns', 'label' => 'Doing', 'sub' => 'Electrical'],
                                ['icon' => 'view-columns', 'label' => 'Done', 'sub' => 'Demo, plumbing'],
                            ]],
                        ],
                        [
                            'heading' => 'Less status-chasing',
                            'text' => 'When the board is the source of truth, nobody has to ask where things stand. Updates happen as work moves, not in a meeting.',
                            'points' => ['One source of truth', 'Fewer status meetings', 'Everyone stays aligned', 'Nothing forgotten'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A board the crew actually understands replaces a dozen "where are we?" texts a day.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'view-columns', 'title' => 'Stages', 'body' => 'To-do to done.'],
                        ['icon' => 'arrows-pointing-out', 'title' => 'Drag cards', 'body' => 'As work moves.'],
                        ['icon' => 'user-plus', 'title' => 'Assign', 'body' => 'Owners per task.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Due dates', 'body' => 'On every card.'],
                        ['icon' => 'eye', 'title' => 'Clear', 'body' => 'Field and office.'],
                        ['icon' => 'bell-alert', 'title' => 'No surprises', 'body' => 'Nothing slips.'],
                    ],
                    'cta' => ['heading' => 'Make the work obvious to everyone.', 'sub' => 'A board your whole crew understands at a glance.'],
                ],

                'projects' => [
                    'icon' => 'folder',
                    'title' => 'Projects',
                    'body' => 'Each job keeps its scope, documents, costs, and history together.',
                    'hero' => 'Everything about a job, in one place',
                    'lead' => 'Each project keeps its scope, schedule, documents, costs, photos, and conversations together—so the whole story of a job is one click away.',
                    'rows' => [
                        [
                            'heading' => 'No more scattered job info',
                            'text' => 'Open a project and find the estimate, schedule, expenses, photos, and messages all in one place. Nothing lives in a separate app or a text thread.',
                            'points' => ['Scope, schedule, and documents', 'Costs and photos together', 'Conversations attached to the job', 'The full history in one place'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Project · Maple St', 'rows' => [
                                ['icon' => 'document-text', 'label' => 'Scope & estimate', 'sub' => '$48,000'],
                                ['icon' => 'calendar-date-range', 'label' => 'Schedule', 'sub' => '62% done'],
                                ['icon' => 'photo', 'label' => 'Photos', 'sub' => '24 on file'],
                            ]],
                        ],
                        [
                            'heading' => 'The single source of truth',
                            'text' => 'Because everything connects to the project, your costing, client portal, and reports all draw from the same place—and stay consistent.',
                            'points' => ['One source for every detail', 'Powers costing and reports', 'Feeds the client portal', 'Consistent everywhere'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When a job lives in one place, you stop losing time hunting and start trusting your numbers.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'folder', 'title' => 'One place', 'body' => 'Everything together.'],
                        ['icon' => 'document-text', 'title' => 'Documents', 'body' => 'Scope and files.'],
                        ['icon' => 'calculator', 'title' => 'Costs', 'body' => 'Live job costing.'],
                        ['icon' => 'photo', 'title' => 'Photos', 'body' => 'Progress on file.'],
                        ['icon' => 'chat-bubble-left-right', 'title' => 'Messages', 'body' => 'Tied to the job.'],
                        ['icon' => 'clock', 'title' => 'History', 'body' => 'The full story.'],
                    ],
                    'cta' => ['heading' => 'Keep every job in one place.', 'sub' => 'Scope, costs, photos, and history—together.'],
                ],

                'crew-scheduling' => [
                    'icon' => 'user-group',
                    'title' => 'Crew scheduling',
                    'body' => 'Assign people to tasks and see who is available when.',
                    'hero' => 'Put the right people on the right job',
                    'lead' => 'Assign crew to tasks and see who is available when, so you stop double-booking people and start running a tighter, more profitable schedule.',
                    'rows' => [
                        [
                            'heading' => 'Availability at a glance',
                            'text' => 'See who is free, who is booked, and who is overloaded before you commit a date. Assign the right people without the guesswork.',
                            'points' => ['See availability across jobs', 'Assign people to tasks', 'Avoid double-booking', 'Balance the workload'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Crew · Tuesday', 'rows' => [
                                ['icon' => 'user', 'label' => 'Greg M.', 'sub' => 'Maple St · rough-in'],
                                ['icon' => 'user', 'label' => 'Tony R.', 'sub' => 'Oak Ave · framing'],
                                ['icon' => 'user', 'label' => 'Sam K.', 'sub' => 'Available'],
                            ]],
                        ],
                        [
                            'heading' => 'Everyone knows where to be',
                            'text' => 'Assignments flow to the crew so they show up at the right site ready to work. No morning phone calls to sort out the day.',
                            'points' => ['Crew sees their assignments', 'Show up ready at the right site', 'Fewer morning scramble calls', 'A more productive day'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A crew that knows where to be by 7 AM is a crew that bills more hours and wastes less fuel.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'user-group', 'title' => 'Assign', 'body' => 'People to tasks.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Availability', 'body' => 'Who is free.'],
                        ['icon' => 'exclamation-triangle', 'title' => 'No double-book', 'body' => 'Conflicts flagged.'],
                        ['icon' => 'scale', 'title' => 'Balanced', 'body' => 'Even workload.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'Crew sees it', 'body' => 'On their phone.'],
                        ['icon' => 'bolt', 'title' => 'Productive', 'body' => 'Ready by 7 AM.'],
                    ],
                    'cta' => ['heading' => 'Stop double-booking your crew.', 'sub' => 'See availability and assign the right people every time.'],
                ],

                'shared-schedules' => [
                    'icon' => 'paper-airplane',
                    'title' => 'Shared schedules',
                    'body' => 'Live schedule links keep clients and crews aligned automatically.',
                    'hero' => 'One schedule everyone can see',
                    'lead' => 'Live schedule links keep clients and crews looking at the same up-to-date plan—so changes reach everyone the instant they happen.',
                    'rows' => [
                        [
                            'heading' => 'Aligned without the busywork',
                            'text' => 'Share a live link with clients and crew. When a date moves, their view moves too—no group texts, no outdated printouts.',
                            'points' => ['Live links for clients and crew', 'Updates the moment dates change', 'No mass texts or printouts', 'Everyone on the same page'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Shared · Maple St', 'rows' => [
                                ['icon' => 'paper-airplane', 'label' => 'Client link', 'sub' => 'What is next'],
                                ['icon' => 'user-group', 'label' => 'Crew link', 'sub' => 'Full schedule'],
                                ['icon' => 'bolt', 'label' => 'Auto-updates', 'sub' => 'On every change'],
                            ]],
                        ],
                        [
                            'heading' => 'Fewer crossed wires',
                            'text' => 'When everyone sees the same plan, the calls about timing stop and the work flows. Changes are communicated by default.',
                            'points' => ['Stop the timing phone tag', 'Changes communicated by default', 'Clients and crew aligned', 'A smoother running job'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A single shared schedule is the cheapest way to cut the daily back-and-forth about who is doing what, when.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'paper-airplane', 'title' => 'Live links', 'body' => 'Clients and crew.'],
                        ['icon' => 'bolt', 'title' => 'Auto-update', 'body' => 'On every change.'],
                        ['icon' => 'users', 'title' => 'Aligned', 'body' => 'One plan for all.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'Any device', 'body' => 'No app needed.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Current', 'body' => 'Never stale.'],
                        ['icon' => 'face-smile', 'title' => 'Fewer calls', 'body' => 'Less back-and-forth.'],
                    ],
                    'cta' => ['heading' => 'Get everyone on the same schedule.', 'sub' => 'Live links that keep clients and crew aligned.'],
                ],

                'reminders' => [
                    'icon' => 'bell-alert',
                    'title' => 'Reminders',
                    'body' => 'Automatic nudges before scheduled work so nothing is missed.',
                    'hero' => 'Nudges so nothing falls through',
                    'lead' => 'Automatic reminders before scheduled work, inspections, and milestones keep you and your crew ahead of every date—nothing slips.',
                    'rows' => [
                        [
                            'heading' => 'Reminded before it matters',
                            'text' => 'Hive nudges the right people before a visit, an inspection, or a deadline, so prep happens on time and dates are never missed.',
                            'points' => ['Reminders before scheduled work', 'Heads-up for inspections', 'Milestone and deadline alerts', 'Sent to the right people'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Reminders', 'rows' => [
                                ['icon' => 'bell-alert', 'label' => 'Inspection tomorrow', 'sub' => 'Maple St · 9 AM'],
                                ['icon' => 'bell-alert', 'label' => 'Tile delivery', 'sub' => 'Mon AM'],
                                ['icon' => 'bell-alert', 'label' => 'Permit expires', 'sub' => 'In 5 days'],
                            ]],
                        ],
                        [
                            'heading' => 'Stay ahead, not behind',
                            'text' => 'Instead of reacting to missed dates, you get ahead of them. Fewer failed inspections, fewer idle crews, fewer expensive surprises.',
                            'points' => ['Get ahead of every date', 'Fewer failed inspections', 'Fewer idle-crew mornings', 'Fewer costly surprises'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A reminder the day before is far cheaper than a missed inspection or a crew standing around waiting.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'bell-alert', 'title' => 'Auto-nudges', 'body' => 'Before work.'],
                        ['icon' => 'clipboard-document-check', 'title' => 'Inspections', 'body' => 'Never missed.'],
                        ['icon' => 'flag', 'title' => 'Milestones', 'body' => 'Stay ahead.'],
                        ['icon' => 'truck', 'title' => 'Deliveries', 'body' => 'Be ready.'],
                        ['icon' => 'users', 'title' => 'Right people', 'body' => 'Targeted alerts.'],
                        ['icon' => 'check-circle', 'title' => 'Nothing slips', 'body' => 'Stay on top.'],
                    ],
                    'cta' => ['heading' => 'Never miss another date.', 'sub' => 'Automatic nudges before every visit and deadline.'],
                ],

            ],
        ],

        'team' => [
            'label' => 'Team & Time',
            'eyebrow' => 'Team & Time',
            'grid_heading' => 'Time and pay, in sync',
            'cards' => [

                'time-tracking' => [
                    'icon' => 'clock',
                    'title' => 'Mobile time tracking',
                    'body' => 'Crews log hours by job and task right from their phone.',
                    'hero' => 'Hours logged from the job site',
                    'lead' => 'Your crew clocks time against the right job and task from their phone—so labor cost is accurate, captured live, and never reconstructed on a Friday.',
                    'rows' => [
                        [
                            'heading' => 'Clock in from the field',
                            'text' => 'No paper timecards and no end-of-week guessing. Crew members tap to start and stop time on the job and task they are working.',
                            'points' => ['Track time by job and task', 'Start and stop from any phone', 'Works on site, no office needed', 'Accurate to the minute'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'This week · Maple St', 'rows' => [
                                ['icon' => 'clock', 'label' => 'Greg M. · Plumbing', 'sub' => '32.5 hrs'],
                                ['icon' => 'clock', 'label' => 'Tony R. · Framing', 'sub' => '28.0 hrs'],
                                ['icon' => 'clock', 'label' => 'Sam K. · Tile', 'sub' => '18.0 hrs'],
                            ]],
                        ],
                        [
                            'heading' => 'Labor that lands on the job',
                            'text' => 'Every hour flows straight into job costing, so the cost of labor shows up on the right project as the work happens.',
                            'points' => ['Hours feed job costing', 'Labor cost on the right job', 'See it live, not after', 'No spreadsheet re-entry'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Labor is your biggest cost. Tracking it live by job is how you find out which work actually makes money.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'clock', 'title' => 'By job & task', 'body' => 'Time on the right work.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'From the phone', 'body' => 'Clock in anywhere.'],
                        ['icon' => 'bolt', 'title' => 'Live', 'body' => 'Captured as it happens.'],
                        ['icon' => 'calculator', 'title' => 'Job costed', 'body' => 'Feeds costing.'],
                        ['icon' => 'document-text', 'title' => 'No paper', 'body' => 'Timecards gone.'],
                        ['icon' => 'check-circle', 'title' => 'Accurate', 'body' => 'To the minute.'],
                    ],
                    'cta' => ['heading' => 'Get accurate hours from the field.', 'sub' => 'Crews clock in by job, and labor lands where it belongs.'],
                ],

                'timesheet-approval' => [
                    'icon' => 'check-circle',
                    'title' => 'Timesheet approval',
                    'body' => 'Review and approve hours before they hit payroll.',
                    'hero' => 'Approve the week in a few taps',
                    'lead' => 'Review your crew&rsquo;s hours, fix anything off, and approve before a dollar of payroll goes out—so you pay for the time that was actually worked.',
                    'rows' => [
                        [
                            'heading' => 'Review before you pay',
                            'text' => 'See the whole week by person and job, catch anything that looks off, and approve with confidence. No surprises on payday.',
                            'points' => ['See hours by person and job', 'Catch mistakes before payroll', 'Edit and approve in a tap', 'A clear approval trail'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Pending approval', 'rows' => [
                                ['icon' => 'clock', 'label' => 'Greg M.', 'sub' => '40.0 hrs · review'],
                                ['icon' => 'clock', 'label' => 'Tony R.', 'sub' => '38.5 hrs · review'],
                                ['icon' => 'check-circle', 'label' => 'Sam K.', 'sub' => '36.0 hrs · approved'],
                            ]],
                        ],
                        [
                            'heading' => 'Straight into payroll',
                            'text' => 'Approved hours flow into payments and job costing at once, so what you approve is exactly what you pay and what the job is charged.',
                            'points' => ['Approved hours feed payroll', 'And feed job costing', 'Pay matches what was worked', 'One consistent record'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A quick approval step catches the errors that quietly cost you money week after week.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'check-circle', 'title' => 'Approve', 'body' => 'Before payroll.'],
                        ['icon' => 'eye', 'title' => 'Review', 'body' => 'By person and job.'],
                        ['icon' => 'pencil', 'title' => 'Edit', 'body' => 'Fix what is off.'],
                        ['icon' => 'banknotes', 'title' => 'To payroll', 'body' => 'Flows straight in.'],
                        ['icon' => 'calculator', 'title' => 'To costing', 'body' => 'On the right job.'],
                        ['icon' => 'clipboard-document-check', 'title' => 'Trail', 'body' => 'Clear approvals.'],
                    ],
                    'cta' => ['heading' => 'Pay for the hours that were worked.', 'sub' => 'Review and approve before payroll runs.'],
                ],

                'payroll-payments' => [
                    'icon' => 'banknotes',
                    'title' => 'Payroll payments',
                    'body' => 'Pay your team from approved hours in one flow.',
                    'hero' => 'Pay your crew from the same screen',
                    'lead' => 'Approved hours roll straight into payments, so paying your team is one clean flow—recorded against the job and matched to your books.',
                    'rows' => [
                        [
                            'heading' => 'From approved to paid',
                            'text' => 'No re-keying hours into another system. Approved time becomes a payout you can review and send, with the math already done.',
                            'points' => ['Payroll from approved hours', 'Pay rate and totals calculated', 'Review then send', 'Recorded against the job'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Payout · Greg M.', 'rows' => [
                                ['label' => '32.5 hrs @ $42', 'value' => '$1,365.00'],
                                ['label' => 'Prior balance', 'value' => '$0.00'],
                                ['label' => 'Pay this week', 'value' => '$1,365.00'],
                            ]],
                        ],
                        [
                            'heading' => 'Clean books, every time',
                            'text' => 'Each payout is recorded and synced to your books and job costing, so payroll never throws your numbers out of balance.',
                            'points' => ['Synced to your books', 'Lands in job costing', 'Accurate cost per job', 'No separate payroll silo'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Payroll that flows from approved hours into your books means your labor cost is always right—automatically.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'banknotes', 'title' => 'From hours', 'body' => 'Approved to paid.'],
                        ['icon' => 'calculator', 'title' => 'Calculated', 'body' => 'Math is done.'],
                        ['icon' => 'eye', 'title' => 'Review', 'body' => 'Before you send.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Cost recorded.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Synced', 'body' => 'Matches books.'],
                        ['icon' => 'check-badge', 'title' => 'On time', 'body' => 'Crew paid right.'],
                    ],
                    'cta' => ['heading' => 'Run payroll without the spreadsheet.', 'sub' => 'Approved hours become payouts in one flow.'],
                ],

                'running-balances' => [
                    'icon' => 'scale',
                    'title' => 'Running balances',
                    'body' => 'Always know what you owe each worker to date.',
                    'hero' => 'Always know what you owe',
                    'lead' => 'Track a running balance for every worker and sub, so you always know exactly what is owed and never lose track of an advance or partial pay.',
                    'rows' => [
                        [
                            'heading' => 'A live balance per person',
                            'text' => 'Every hour, payout, and advance adjusts the balance, so the number you see is always what you actually owe—down to the dollar.',
                            'points' => ['Live balance per worker', 'Accounts for advances', 'Handles partial payments', 'Always accurate'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Balances', 'rows' => [
                                ['label' => 'Greg M.', 'value' => '$0.00'],
                                ['label' => 'Tony R.', 'value' => '$420.00'],
                                ['label' => 'Sam K.', 'value' => '$1,365.00'],
                            ]],
                        ],
                        [
                            'heading' => 'No awkward conversations',
                            'text' => 'When a worker asks what they are owed, you have the answer instantly—no digging, no disputes, no eroded trust.',
                            'points' => ['Answer "what am I owed?" instantly', 'Avoid pay disputes', 'Keep crew trust high', 'Clear history for both sides'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A crew that trusts they will be paid right—and can see it—is a crew that sticks around.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'scale', 'title' => 'Per person', 'body' => 'Live balance each.'],
                        ['icon' => 'arrow-trending-up', 'title' => 'Advances', 'body' => 'Tracked cleanly.'],
                        ['icon' => 'banknotes', 'title' => 'Partial pay', 'body' => 'Handled right.'],
                        ['icon' => 'bolt', 'title' => 'Always live', 'body' => 'Up to the minute.'],
                        ['icon' => 'chat-bubble-left-right', 'title' => 'No disputes', 'body' => 'Clear answers.'],
                        ['icon' => 'clock', 'title' => 'History', 'body' => 'For both sides.'],
                    ],
                    'cta' => ['heading' => 'Know what every worker is owed.', 'sub' => 'A live running balance for your whole crew.'],
                ],

                'roles-permissions' => [
                    'icon' => 'lock-closed',
                    'title' => 'Roles & permissions',
                    'body' => 'Control who can see finances, clients, and settings.',
                    'hero' => 'Give people exactly the access they need',
                    'lead' => 'Roles and permissions let your team do their jobs without seeing your finances, client list, or settings—so you can delegate without worry.',
                    'rows' => [
                        [
                            'heading' => 'The right access for each role',
                            'text' => 'A foreman sees schedules and time; the office sees clients and invoices; only you see the full financial picture. Set it once and relax.',
                            'points' => ['Control access by role', 'Hide finances from the field', 'Limit who edits settings', 'Delegate with confidence'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Roles', 'rows' => [
                                ['icon' => 'user', 'label' => 'Foreman', 'sub' => 'Schedule & time'],
                                ['icon' => 'user', 'label' => 'Office', 'sub' => 'Clients & invoices'],
                                ['icon' => 'lock-closed', 'label' => 'Owner', 'sub' => 'Full access'],
                            ]],
                        ],
                        [
                            'heading' => 'Grow the team safely',
                            'text' => 'As you add people, you hand out only the access they need. Your sensitive numbers stay private even as more hands touch the system.',
                            'points' => ['Onboard new people safely', 'Keep sensitive data private', 'Reduce costly mistakes', 'Scale without losing control'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'You can only grow as fast as you can delegate. Roles let you hand off work without handing over your books.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'lock-closed', 'title' => 'By role', 'body' => 'Tailored access.'],
                        ['icon' => 'eye-slash', 'title' => 'Hide finances', 'body' => 'From the field.'],
                        ['icon' => 'cog-6-tooth', 'title' => 'Settings lock', 'body' => 'Limit edits.'],
                        ['icon' => 'user-plus', 'title' => 'Onboard', 'body' => 'Add people safe.'],
                        ['icon' => 'shield-check', 'title' => 'Private', 'body' => 'Sensitive data safe.'],
                        ['icon' => 'arrow-trending-up', 'title' => 'Scale', 'body' => 'Grow in control.'],
                    ],
                    'cta' => ['heading' => 'Delegate without handing over the books.', 'sub' => 'Give each person exactly the access they need.'],
                ],

                'job-costing' => [
                    'icon' => 'calculator',
                    'title' => 'Job costing',
                    'body' => 'Labor cost lands on the right project automatically.',
                    'hero' => 'Labor cost on the right job, automatically',
                    'lead' => 'Every approved hour lands on the project it was worked, so labor—your biggest cost—shows up in job costing without anyone tallying timecards.',
                    'rows' => [
                        [
                            'heading' => 'Labor that costs itself',
                            'text' => 'Because crews track time by job, their hours and pay flow into each project&rsquo;s cost automatically. No allocation spreadsheets, no estimates.',
                            'points' => ['Hours map to the right job', 'Pay rates roll into cost', 'No manual allocation', 'Always current'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Maple St · Labor', 'rows' => [
                                ['label' => 'Hours to date', 'value' => '186'],
                                ['label' => 'Labor cost', 'value' => '$7,940'],
                                ['label' => '% of budget', 'value' => '71%'],
                            ]],
                        ],
                        [
                            'heading' => 'See which work pays',
                            'text' => 'With labor costed accurately, you finally see which jobs and tasks actually make money—and bid the next ones smarter.',
                            'points' => ['True labor cost per job', 'Spot the profitable work', 'Catch overruns early', 'Bid the next job better'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Most contractors underprice labor because they never track it by job. Hive shows you the real number.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'calculator', 'title' => 'Auto-costed', 'body' => 'Labor on the job.'],
                        ['icon' => 'clock', 'title' => 'From hours', 'body' => 'Tracked by job.'],
                        ['icon' => 'bolt', 'title' => 'Live', 'body' => 'Always current.'],
                        ['icon' => 'chart-bar', 'title' => 'Profit view', 'body' => 'See what pays.'],
                        ['icon' => 'exclamation-triangle', 'title' => 'Overruns', 'body' => 'Caught early.'],
                        ['icon' => 'light-bulb', 'title' => 'Better bids', 'body' => 'Price it right.'],
                    ],
                    'cta' => ['heading' => 'Cost your labor without the math.', 'sub' => 'Approved hours land on the right job automatically.'],
                ],

            ],
        ],

        'communication' => [
            'label' => 'Communication',
            'eyebrow' => 'Communication',
            'grid_heading' => 'Every conversation, captured',
            'cards' => [

                'shared-inbox' => [
                    'icon' => 'chat-bubble-left-right',
                    'title' => 'Shared inbox',
                    'body' => 'Your whole team works one set of conversations—no personal numbers required.',
                    'hero' => 'One inbox for your whole team',
                    'lead' => 'Calls and texts run through one shared business line, so your team works the same conversations and no client thread lives on a personal phone.',
                    'rows' => [
                        [
                            'heading' => 'No more personal numbers',
                            'text' => 'Clients and subs text one business number. Anyone on your team can pick up the thread, and the conversation stays with the company—not an employee&rsquo;s phone.',
                            'points' => ['One shared business line', 'Team works the same threads', 'No client on a personal phone', 'Continuity when staff change'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Shared inbox', 'rows' => [
                                ['icon' => 'chat-bubble-left-right', 'label' => 'The Hendersons', 'sub' => 'Tile question'],
                                ['icon' => 'chat-bubble-left-right', 'label' => 'Rivera Plumbing', 'sub' => 'Schedule'],
                                ['icon' => 'chat-bubble-left-right', 'label' => 'City Inspector', 'sub' => 'Confirmed'],
                            ]],
                        ],
                        [
                            'heading' => 'Tied to the job',
                            'text' => 'Every conversation connects to the right client and project, so context is never lost and anyone who steps in is instantly up to speed.',
                            'points' => ['Threads linked to jobs', 'Full context for whoever replies', 'Nothing lost in a DM', 'Searchable history'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When client conversations live with the company, you never lose a relationship because an employee left.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'chat-bubble-left-right', 'title' => 'Shared line', 'body' => 'One business number.'],
                        ['icon' => 'users', 'title' => 'Team access', 'body' => 'Anyone can reply.'],
                        ['icon' => 'folder', 'title' => 'Job-linked', 'body' => 'Tied to projects.'],
                        ['icon' => 'eye-slash', 'title' => 'No personal #', 'body' => 'Privacy kept.'],
                        ['icon' => 'magnifying-glass', 'title' => 'Searchable', 'body' => 'Find any thread.'],
                        ['icon' => 'shield-check', 'title' => 'Continuity', 'body' => 'Stays with you.'],
                    ],
                    'cta' => ['heading' => 'Keep client threads with the company.', 'sub' => 'One shared inbox, no personal numbers required.'],
                ],

                'translations' => [
                    'icon' => 'language',
                    'title' => 'Translations',
                    'body' => 'Message crews in their language and read their replies in yours.',
                    'hero' => 'Talk to every crew in their language',
                    'lead' => 'Message a sub or crew member in their language and read their replies in yours—automatically—so language never slows a job down.',
                    'rows' => [
                        [
                            'heading' => 'Two languages, one thread',
                            'text' => 'You type in English, they read it in Spanish; they reply in Spanish, you read English. The translation happens in the message, both ways.',
                            'points' => ['Send and receive translated', 'Works both directions', 'In the same thread', 'No separate app'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Thread · Tony R.', 'rows' => [
                                ['icon' => 'language', 'label' => 'You (EN)', 'sub' => 'Start tile Monday'],
                                ['icon' => 'language', 'label' => 'Tony (ES)', 'sub' => 'Entendido, lunes'],
                                ['icon' => 'check-badge', 'label' => 'You read', 'sub' => 'Understood, Monday'],
                            ]],
                        ],
                        [
                            'heading' => 'Fewer mistakes on site',
                            'text' => 'Clear instructions in the language someone actually reads means less rework, fewer mix-ups, and a safer job.',
                            'points' => ['Clearer instructions', 'Less rework and mix-ups', 'A safer job site', 'Stronger crew relationships'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A misread instruction can cost a day. Translating in the thread keeps everyone literally on the same page.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'language', 'title' => 'Both ways', 'body' => 'Send and read.'],
                        ['icon' => 'chat-bubble-left-right', 'title' => 'In thread', 'body' => 'Same conversation.'],
                        ['icon' => 'bolt', 'title' => 'Automatic', 'body' => 'No extra steps.'],
                        ['icon' => 'shield-check', 'title' => 'Fewer errors', 'body' => 'Clear instructions.'],
                        ['icon' => 'users', 'title' => 'Any crew', 'body' => 'Reach everyone.'],
                        ['icon' => 'face-smile', 'title' => 'Better bonds', 'body' => 'Stronger teams.'],
                    ],
                    'cta' => ['heading' => 'Never let language slow a job.', 'sub' => 'Message crews in their language, read replies in yours.'],
                ],

                'text-to-task' => [
                    'icon' => 'calendar-date-range',
                    'title' => 'Text-to-task',
                    'body' => 'Turn an incoming message into a scheduled task with AI, reviewed before saving.',
                    'hero' => 'Turn a text into a task—instantly',
                    'lead' => 'When a client or sub texts something that needs doing, AI drafts a scheduled task from it, ready for you to review and save in a tap.',
                    'rows' => [
                        [
                            'heading' => 'Catch the ask, make the task',
                            'text' => 'A message like "can you also fix the back gate Thursday?" becomes a draft task with the right job and date—so it never gets lost in the thread.',
                            'points' => ['AI reads the message', 'Drafts a task with job and date', 'You review before it saves', 'Nothing falls through'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Suggested task', 'rows' => [
                                ['icon' => 'chat-bubble-left-right', 'label' => 'From: Henderson', 'sub' => 'Fix back gate Thu'],
                                ['icon' => 'calendar-date-range', 'label' => 'Task drafted', 'sub' => 'Maple St · Thu'],
                                ['icon' => 'check-circle', 'label' => 'Review & save', 'sub' => 'One tap'],
                            ]],
                        ],
                        [
                            'heading' => 'Reviewed, never blind',
                            'text' => 'AI suggests; you decide. Every task is shown for your approval before it lands on the schedule, so you stay in control.',
                            'points' => ['You approve every task', 'Edit before saving', 'Stay fully in control', 'No surprise calendar items'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The small asks buried in texts are the ones that get forgotten. Text-to-task makes sure they get scheduled.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'sparkles', 'title' => 'AI reads', 'body' => 'Spots the ask.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Drafts task', 'body' => 'Job and date.'],
                        ['icon' => 'check-circle', 'title' => 'Reviewed', 'body' => 'You approve.'],
                        ['icon' => 'pencil', 'title' => 'Editable', 'body' => 'Tweak first.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Right project.'],
                        ['icon' => 'bell-alert', 'title' => 'Nothing lost', 'body' => 'Always captured.'],
                    ],
                    'cta' => ['heading' => 'Stop losing asks in the thread.', 'sub' => 'Turn a text into a scheduled task in one tap.'],
                ],

                'recorded-calls' => [
                    'icon' => 'microphone',
                    'title' => 'Recorded calls',
                    'body' => 'Every call captured, transcribed, and summarized automatically.',
                    'hero' => 'Every call captured and summarized',
                    'lead' => 'Calls are recorded, transcribed, and summarized with action items—so you never lose what was promised on the phone again.',
                    'rows' => [
                        [
                            'heading' => 'Never rely on memory',
                            'text' => 'Each call is recorded and transcribed, then summarized into the key points and action items, attached to the right client and job.',
                            'points' => ['Calls recorded and transcribed', 'Summarized with action items', 'Attached to client and job', 'Searchable later'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Call · Henderson', 'rows' => [
                                ['icon' => 'microphone', 'label' => 'Recorded', 'sub' => '6:42'],
                                ['icon' => 'document-text', 'label' => 'Transcript', 'sub' => 'Ready'],
                                ['icon' => 'check-circle', 'label' => 'Action item', 'sub' => 'Send tile quote'],
                            ]],
                        ],
                        [
                            'heading' => 'Settle "you said" disputes',
                            'text' => 'When there is a question about what was agreed on the phone, you have the recording and the summary—no more he-said-she-said.',
                            'points' => ['Proof of what was said', 'End phone disputes', 'Hold subs accountable', 'Protect your business'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The promises made on the phone are the ones most often forgotten. Recording them protects everyone.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'microphone', 'title' => 'Recorded', 'body' => 'Every call.'],
                        ['icon' => 'document-text', 'title' => 'Transcribed', 'body' => 'Full text.'],
                        ['icon' => 'sparkles', 'title' => 'Summarized', 'body' => 'Key points.'],
                        ['icon' => 'check-circle', 'title' => 'Action items', 'body' => 'Pulled out.'],
                        ['icon' => 'folder', 'title' => 'Attached', 'body' => 'To the job.'],
                        ['icon' => 'magnifying-glass', 'title' => 'Searchable', 'body' => 'Find it later.'],
                    ],
                    'cta' => ['heading' => 'Never lose a phone promise again.', 'sub' => 'Calls recorded, transcribed, and summarized for you.'],
                ],

                'email-tracking' => [
                    'icon' => 'envelope',
                    'title' => 'Email tracking',
                    'body' => 'Know when important emails are opened and keep records on the job.',
                    'hero' => 'Know when your emails land',
                    'lead' => 'See when important emails are opened and keep every message on the job record—so you know whether a client really saw that estimate.',
                    'rows' => [
                        [
                            'heading' => 'No more wondering',
                            'text' => 'Send an estimate or update and see when it is opened. You know whether to follow up or give them space—instead of guessing.',
                            'points' => ['See when emails are opened', 'Know whether to follow up', 'Time your outreach', 'Stop guessing'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Sent · Estimate', 'rows' => [
                                ['icon' => 'envelope', 'label' => 'Delivered', 'sub' => 'Mon 9:10 AM'],
                                ['icon' => 'eye', 'label' => 'Opened', 'sub' => 'Mon 9:14 AM'],
                                ['icon' => 'eye', 'label' => 'Opened again', 'sub' => 'Tue 7:02 AM'],
                            ]],
                        ],
                        [
                            'heading' => 'On the record, on the job',
                            'text' => 'Important emails are kept with the client and project, so the paper trail lives where the work does—not buried in a personal inbox.',
                            'points' => ['Emails kept on the job', 'A clear paper trail', 'Out of personal inboxes', 'Easy to reference'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Knowing a client opened your estimate three times tells you exactly when to make the call.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'eye', 'title' => 'Open tracking', 'body' => 'See when read.'],
                        ['icon' => 'clock', 'title' => 'Timing', 'body' => 'Reach out right.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Kept on record.'],
                        ['icon' => 'document-text', 'title' => 'Paper trail', 'body' => 'Clear history.'],
                        ['icon' => 'envelope', 'title' => 'Delivery', 'body' => 'Confirmed sent.'],
                        ['icon' => 'magnifying-glass', 'title' => 'Reference', 'body' => 'Find it fast.'],
                    ],
                    'cta' => ['heading' => 'Know if they actually saw it.', 'sub' => 'Open tracking and records that live on the job.'],
                ],

                'client-updates' => [
                    'icon' => 'users',
                    'title' => 'Client updates',
                    'body' => 'Push schedule and status updates to homeowners without extra effort.',
                    'hero' => 'Keep clients posted—without the effort',
                    'lead' => 'Push schedule and status updates to homeowners automatically, so they stay informed and reassured while you stay focused on the build.',
                    'rows' => [
                        [
                            'heading' => 'Updates that send themselves',
                            'text' => 'As work progresses and dates change, clients get the update through their portal and notifications—no separate message for you to write.',
                            'points' => ['Status and schedule updates', 'Sent through the portal', 'No extra messages to write', 'Clients always reassured'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Pushed to client', 'rows' => [
                                ['icon' => 'eye', 'label' => 'Status update', 'sub' => 'Electrical started'],
                                ['icon' => 'calendar-date-range', 'label' => 'Schedule', 'sub' => 'Tile Mon 7/6'],
                                ['icon' => 'photo', 'label' => 'New photos', 'sub' => '4 added'],
                            ]],
                        ],
                        [
                            'heading' => 'Happier clients, fewer calls',
                            'text' => 'Informed clients call less and trust more. A steady drip of updates makes you look organized and on top of every job.',
                            'points' => ['Fewer "any update?" calls', 'More client trust', 'Look organized and pro', 'Better reviews and referrals'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Proactive updates are the cheapest marketing you have—they turn clients into referrals.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'bolt', 'title' => 'Automatic', 'body' => 'Sends itself.'],
                        ['icon' => 'eye', 'title' => 'Status', 'body' => 'Progress shared.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Schedule', 'body' => 'What is next.'],
                        ['icon' => 'photo', 'title' => 'Photos', 'body' => 'Progress shots.'],
                        ['icon' => 'face-smile', 'title' => 'Fewer calls', 'body' => 'Clients calm.'],
                        ['icon' => 'star', 'title' => 'Referrals', 'body' => 'Better reviews.'],
                    ],
                    'cta' => ['heading' => 'Keep clients posted on autopilot.', 'sub' => 'Status and schedule updates that send themselves.'],
                ],

            ],
        ],

        'photos' => [
            'label' => 'Photos & Timelapses',
            'eyebrow' => 'Jobsite photos & timelapses',
            'grid_heading' => 'Everything in the jobsite camera',
            'cards' => [

                'progress-photos' => [
                    'icon' => 'camera',
                    'title' => 'Progress photos',
                    'body' => 'Shoot from the phone straight into the right job, organized and time-stamped.',
                    'hero' => 'Every job photographed, nothing lost in a camera roll',
                    'lead' => 'Crews shoot straight into the project from their phone. Photos land in the right job, in order, stamped with when they were taken and who took them—no texting them around, no folder to organize later.',
                    'rows' => [
                        [
                            'heading' => 'Shot on site, filed on its own',
                            'text' => 'Open the project, tap the shutter. The photo is stored against that job with the time it was taken and the crew member who took it. What used to be a phone full of unsorted pictures becomes a documented record while the work happens.',
                            'points' => ['Capture straight into a project from any phone', 'Time and photographer recorded on every shot', 'Several albums per job—one per room or phase', 'Upload from the desktop when a camera was used'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Kitchen remodel · today', 'rows' => [
                                ['icon' => 'camera', 'label' => 'Cabinet delivery', 'sub' => '22 photos · Czarek'],
                                ['icon' => 'photo', 'label' => 'Project images', 'sub' => '6 photos'],
                                ['icon' => 'clock', 'label' => 'Latest', 'sub' => '12:52 PM today'],
                            ]],
                        ],
                        [
                            'heading' => 'The originals are kept, untouched',
                            'text' => 'Hive stores the full-resolution file exactly as the camera produced it, and derives everything else from that. The record never degrades, and the original is available to the person who took it and the company that owns the job.',
                            'points' => ['Full-resolution original archived on upload', 'Location and capture time preserved', 'Originals restricted to the photographer and admins', 'Everything else is re-derived, never overwritten'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When a question comes up two years later—what was behind that wall, when was it done—the original photo is still there at full quality.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'camera', 'title' => 'From the phone', 'body' => 'Straight into the job.'],
                        ['icon' => 'folder', 'title' => 'Organized', 'body' => 'Albums per room.'],
                        ['icon' => 'clock', 'title' => 'Time-stamped', 'body' => 'When and by whom.'],
                        ['icon' => 'archive-box', 'title' => 'Originals kept', 'body' => 'Full resolution.'],
                        ['icon' => 'map-pin', 'title' => 'Located', 'body' => 'Where it was shot.'],
                        ['icon' => 'arrow-up-tray', 'title' => 'Or upload', 'body' => 'Desktop too.'],
                    ],
                    'cta' => ['heading' => 'Stop losing photos in a camera roll.', 'sub' => 'Every shot filed to the job, the moment it is taken.'],
                ],

                'timelapses' => [
                    'icon' => 'film',
                    'title' => 'Project timelapses',
                    'body' => 'Shoot the same view each visit and watch the whole job play back in seconds.',
                    'hero' => 'Months of work, played back in seconds',
                    'lead' => 'Point the camera at the same view each visit and Hive builds a timelapse out of it. Foundation to finish in one smooth sequence—the clearest way to show a client, or a prospect, what your crew actually did.',
                    'rows' => [
                        [
                            'heading' => 'An onion skin keeps every shot on the same view',
                            'text' => 'The last frame shows faintly over the live viewfinder, so lining up the next shot takes a second. Frames stay consistent from the start, which is what makes the finished sequence play smoothly instead of jumping around.',
                            'points' => ['Previous frame ghosted over the viewfinder', 'Shoot the next frame in seconds', 'Works months apart, by whoever is on site', 'Reorder or drop a frame whenever you like'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Cabinet delivery', 'rows' => [
                                ['label' => 'Frames', 'value' => '22'],
                                ['label' => 'Span', 'value' => '3 hours'],
                                ['label' => 'Playback', 'value' => '8 seconds'],
                            ]],
                        ],
                        [
                            'heading' => 'Build one from photos you already took',
                            'text' => 'Pick shots out of a job album and Hive assembles them into a sequence in the order they were taken. A timelapse can start after the fact, from the photos your crew was already shooting.',
                            'points' => ['Select photos and build a sequence from them', 'Ordered by when they were shot', 'The album stays exactly as it was', 'Play it, or step frame by frame'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Good to know', 'note' => 'Your photo album is never modified—the timelapse gets its own copies to work with.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'film', 'title' => 'Timelapse', 'body' => 'The whole job.'],
                        ['icon' => 'viewfinder-circle', 'title' => 'Onion skin', 'body' => 'Same view twice.'],
                        ['icon' => 'play', 'title' => 'Playback', 'body' => 'In the browser.'],
                        ['icon' => 'squares-plus', 'title' => 'From photos', 'body' => 'Build it after.'],
                        ['icon' => 'arrows-up-down', 'title' => 'Reorder', 'body' => 'Any time.'],
                        ['icon' => 'share', 'title' => 'Show it', 'body' => 'Clients love it.'],
                    ],
                    'cta' => ['heading' => 'Show the whole job in eight seconds.', 'sub' => 'The most convincing thing you can put in front of a client.'],
                ],

                'auto-alignment' => [
                    'icon' => 'viewfinder-circle',
                    'title' => 'Automatic alignment',
                    'body' => 'Handheld shots are registered onto one steady viewpoint so playback does not jump.',
                    'hero' => 'Handheld shots, rock-steady playback',
                    'lead' => 'Nobody holds a phone in exactly the same spot twice. Hive registers each frame onto the sequence so the building stays put and only the work changes—the difference between a slideshow and a real timelapse.',
                    'rows' => [
                        [
                            'heading' => 'Every frame lined up to the same view',
                            'text' => 'Hive finds the same points across two photos and lines the new one up on them, correcting the small shifts and tilts a handheld shot always has. Straight lines stay straight: walls and cabinets are never bent to force a match.',
                            'points' => ['Corrects handheld shift, tilt and zoom', 'Matches shots taken months and seasons apart', 'Straight edges stay straight—no warping', 'A frame it cannot place honestly is kept as shot'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Registration', 'rows' => [
                                ['label' => 'Aligned automatically', 'value' => '21 of 22'],
                                ['label' => 'Kept as shot', 'value' => '1'],
                                ['label' => 'Edges bent', 'value' => '0'],
                            ]],
                        ],
                        [
                            'heading' => 'Choose the view the sequence holds',
                            'text' => 'Pick which frame sets the framing and the rest re-register onto it—useful when a later shot frames the room better. When a frame needs a human eye, pan, zoom and rotate it by hand over the previous one.',
                            'points' => ['Any frame can define the sequence view', 'Hand-align a stubborn frame over an onion skin', 'Hand-tuned frames are protected from re-runs', 'Originals are never modified—only copies'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Under the hood', 'note' => 'Alignment runs on a learned image matcher—the same technology behind photogrammetry and drone mapping—so it holds up on bare framing and blank drywall where older methods give up.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'viewfinder-circle', 'title' => 'Registered', 'body' => 'One steady view.'],
                        ['icon' => 'sparkles', 'title' => 'Learned matching', 'body' => 'Finds the same spot.'],
                        ['icon' => 'lock-closed', 'title' => 'No warping', 'body' => 'Lines stay straight.'],
                        ['icon' => 'hand-raised', 'title' => 'Hand-align', 'body' => 'When you want.'],
                        ['icon' => 'flag', 'title' => 'Set the view', 'body' => 'Any frame.'],
                        ['icon' => 'shield-check', 'title' => 'Non-destructive', 'body' => 'Originals safe.'],
                    ],
                    'cta' => ['heading' => 'A timelapse that does not jump around.', 'sub' => 'Every frame registered onto the same view, automatically.'],
                ],

                'color-matching' => [
                    'icon' => 'swatch',
                    'title' => 'Consistent color',
                    'body' => 'Morning, afternoon and winter light evened out so the sequence does not flicker.',
                    'hero' => 'One consistent look across every visit',
                    'lead' => 'Photos taken at 7am, at noon, and in December do not match. Hive evens the tone across a sequence so materials read the same throughout—while still letting the day look like the day.',
                    'rows' => [
                        [
                            'heading' => 'Materials that stay the same color',
                            'text' => 'Floor protection, tape, cabinet finishes—anything that is physically the same object in every frame should look the same. Hive matches those consistently while leaving natural changes in daylight intact, so the sequence reads smooth rather than flat.',
                            'points' => ['Evens exposure and color cast between frames', 'Keeps the character of the day and season', 'Whole-image only—nothing is selectively repainted', 'Runs automatically, every frame'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Across 22 frames', 'rows' => [
                                ['icon' => 'sun', 'label' => 'Shot from', 'sub' => '2:29 PM to 5:52 PM'],
                                ['icon' => 'swatch', 'label' => 'Material color', 'sub' => 'Matched across all'],
                                ['icon' => 'eye', 'label' => 'Result', 'sub' => 'No flicker on playback'],
                            ]],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'swatch', 'title' => 'Even tone', 'body' => 'Across the set.'],
                        ['icon' => 'sun', 'title' => 'Any hour', 'body' => 'Morning or dusk.'],
                        ['icon' => 'calendar', 'title' => 'Any season', 'body' => 'Months apart.'],
                        ['icon' => 'eye', 'title' => 'No flicker', 'body' => 'Smooth playback.'],
                        ['icon' => 'photo', 'title' => 'Honest', 'body' => 'Whole image only.'],
                        ['icon' => 'bolt', 'title' => 'Automatic', 'body' => 'Nothing to set.'],
                    ],
                    'cta' => ['heading' => 'No flicker between frames.', 'sub' => 'Color evened across every visit, automatically.'],
                ],

                'face-blurring' => [
                    'icon' => 'shield-check',
                    'title' => 'Faces blurred',
                    'body' => 'Crew and bystanders are automatically blurred in every photo people can see.',
                    'hero' => 'Privacy handled before anyone sees the photo',
                    'lead' => 'Jobsite photos catch people—your crew, a subcontractor, a homeowner walking through. Hive finds faces and blurs them automatically on every copy that gets viewed or shared, so sharing progress never means sharing someone\'s face.',
                    'rows' => [
                        [
                            'heading' => 'Automatic, on every shared copy',
                            'text' => 'Detection runs the moment a photo is stored, and again on any copy the system derives afterwards. Nobody has to remember to do it, and there is no step to skip when you are in a hurry to show a client something.',
                            'points' => ['Faces found and blurred on upload', 'Applies to photos and every timelapse frame', 'Catches people across the room, not just close-ups', 'Nothing to enable—it is simply how photos are stored'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'The archive is different', 'note' => 'The untouched original stays intact for the record—reachable only by the person who took it and the company that owns the job.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'shield-check', 'title' => 'Automatic', 'body' => 'On every upload.'],
                        ['icon' => 'user-group', 'title' => 'Anyone', 'body' => 'Crew or visitor.'],
                        ['icon' => 'film', 'title' => 'Frames too', 'body' => 'Whole timelapse.'],
                        ['icon' => 'eye-slash', 'title' => 'Unidentifiable', 'body' => 'Properly blurred.'],
                        ['icon' => 'archive-box', 'title' => 'Original kept', 'body' => 'For the record.'],
                        ['icon' => 'lock-closed', 'title' => 'Restricted', 'body' => 'Taker and admins.'],
                    ],
                    'cta' => ['heading' => 'Share progress, not faces.', 'sub' => 'Blurring happens before anyone opens the photo.'],
                ],

                'sharing-photos' => [
                    'icon' => 'share',
                    'title' => 'Share with clients',
                    'body' => 'Text photos to a homeowner or let them watch progress in their portal.',
                    'hero' => 'The update your client actually wants',
                    'lead' => 'Pick a few photos and text them, or point the homeowner at their portal where the job\'s photos and timelapses are waiting. Most "how is it going?" calls stop happening once people can see for themselves.',
                    'rows' => [
                        [
                            'heading' => 'Texted in seconds, or always available',
                            'text' => 'Select the shots that tell the story and send them to the client on the same thread you already use. Everything is also in the homeowner portal, organized by job, so they can look whenever they think of it.',
                            'points' => ['Select and text photos from the job', 'Homeowner portal shows photos and timelapses', 'Organized by project and phase', 'Fewer status calls and check-in visits'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Sent to homeowner', 'rows' => [
                                ['icon' => 'chat-bubble-left-right', 'label' => 'Text', 'sub' => '4 photos · today'],
                                ['icon' => 'film', 'label' => 'Timelapse', 'sub' => 'In their portal'],
                                ['icon' => 'check-circle', 'label' => 'Result', 'sub' => 'No status call'],
                            ]],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'chat-bubble-left-right', 'title' => 'Text them', 'body' => 'Straight from the job.'],
                        ['icon' => 'home', 'title' => 'Portal', 'body' => 'Always available.'],
                        ['icon' => 'film', 'title' => 'Timelapse', 'body' => 'Clients love it.'],
                        ['icon' => 'squares-2x2', 'title' => 'Organized', 'body' => 'By job and phase.'],
                        ['icon' => 'phone-x-mark', 'title' => 'Fewer calls', 'body' => 'They can see.'],
                        ['icon' => 'sparkles', 'title' => 'Wins work', 'body' => 'Proof you deliver.'],
                    ],
                    'cta' => ['heading' => 'Let them watch it happen.', 'sub' => 'Photos and timelapses, in their hands.'],
                ],
            ],
        ],

        'automation' => [
            'label' => 'Automation & AI',
            'eyebrow' => 'Automation & AI',
            'grid_heading' => 'Let the busywork run itself',
            'cards' => [

                'receipt-ai' => [
                    'icon' => 'document-magnifying-glass',
                    'title' => 'Receipt AI',
                    'body' => 'Reads vendors, totals, and line items from any receipt.',
                    'hero' => 'Receipts that read themselves',
                    'lead' => 'Snap or forward a receipt and AI pulls the vendor, total, date, and every line item—so your books fill in without a single keystroke.',
                    'rows' => [
                        [
                            'heading' => 'From photo to posted',
                            'text' => 'Whether it is a crumpled paper receipt or an emailed PDF, AI reads it and creates a clean expense with line items, ready to assign to a job.',
                            'points' => ['Reads vendor, total, and date', 'Captures every line item', 'Works on photos and PDFs', 'Creates a clean expense'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Receipt · Menards', 'rows' => [
                                ['icon' => 'document-magnifying-glass', 'label' => 'Vendor', 'sub' => 'Menards'],
                                ['icon' => 'banknotes', 'label' => 'Total', 'sub' => '$312.84'],
                                ['icon' => 'list-bullet', 'label' => 'Line items', 'sub' => '11 captured'],
                            ]],
                        ],
                        [
                            'heading' => 'Hours back every week',
                            'text' => 'No more typing receipts into a spreadsheet at night. The data entry is done the moment a receipt comes in, with the line-item detail intact.',
                            'points' => ['No manual data entry', 'Line-item detail kept', 'Hours saved each week', 'Books that stay current'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The receipt pile is where bookkeeping goes to die. Reading them automatically is how you stay caught up.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-magnifying-glass', 'title' => 'Reads it', 'body' => 'Vendor and total.'],
                        ['icon' => 'list-bullet', 'title' => 'Line items', 'body' => 'Every line.'],
                        ['icon' => 'camera', 'title' => 'Any format', 'body' => 'Photo or PDF.'],
                        ['icon' => 'folder', 'title' => 'To a job', 'body' => 'Assign fast.'],
                        ['icon' => 'bolt', 'title' => 'Instant', 'body' => 'No typing.'],
                        ['icon' => 'check-circle', 'title' => 'Accurate', 'body' => 'Clean expense.'],
                    ],
                    'cta' => ['heading' => 'Stop typing in receipts.', 'sub' => 'AI reads vendor, total, and every line item for you.'],
                ],

                'vendor-matching' => [
                    'icon' => 'arrows-right-left',
                    'title' => 'Vendor matching',
                    'body' => 'Transactions match themselves to the right vendor and job.',
                    'hero' => 'Transactions that sort themselves',
                    'lead' => 'Bank and card transactions match themselves to the right vendor and job, so reconciliation stops being a weekend chore.',
                    'rows' => [
                        [
                            'heading' => 'The matching is done for you',
                            'text' => 'AI recognizes vendors from messy bank descriptions and links each transaction to the right vendor and job—learning your patterns as it goes.',
                            'points' => ['Recognizes messy descriptions', 'Links to vendor and job', 'Learns your patterns', 'Less every week'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Auto-matched', 'rows' => [
                                ['icon' => 'arrows-right-left', 'label' => 'SQ *RIVERA PLB', 'sub' => 'Rivera Plumbing'],
                                ['icon' => 'arrows-right-left', 'label' => 'MENARDS #214', 'sub' => 'Menards · Maple St'],
                                ['icon' => 'check-badge', 'label' => 'Confidence', 'sub' => 'High'],
                            ]],
                        ],
                        [
                            'heading' => 'Books that reconcile fast',
                            'text' => 'With transactions pre-matched, you just confirm instead of categorize. Reconciliation goes from hours to minutes.',
                            'points' => ['Confirm instead of categorize', 'Hours become minutes', 'Fewer miscategorized costs', 'Job costing stays accurate'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Matching transactions by hand is the slowest part of the books. Automating it is hours back every month.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'arrows-right-left', 'title' => 'Auto-match', 'body' => 'Vendor and job.'],
                        ['icon' => 'sparkles', 'title' => 'Learns', 'body' => 'Your patterns.'],
                        ['icon' => 'check-badge', 'title' => 'Confident', 'body' => 'Scored matches.'],
                        ['icon' => 'calculator', 'title' => 'Job costed', 'body' => 'Right project.'],
                        ['icon' => 'clock', 'title' => 'Faster', 'body' => 'Minutes, not hours.'],
                        ['icon' => 'check-circle', 'title' => 'Accurate', 'body' => 'Clean books.'],
                    ],
                    'cta' => ['heading' => 'Let reconciliation do itself.', 'sub' => 'Transactions match to the right vendor and job.'],
                ],

                'retailer-scraping' => [
                    'icon' => 'globe-alt',
                    'title' => 'Retailer scraping',
                    'body' => 'Pull itemized receipts straight from supplier accounts.',
                    'hero' => 'Itemized receipts, pulled for you',
                    'lead' => 'Connect your supplier accounts and Hive pulls full itemized receipts automatically—so you get line-item detail without saving a single slip.',
                    'rows' => [
                        [
                            'heading' => 'Straight from the source',
                            'text' => 'For the stores you buy from most, Hive grabs the complete itemized receipt right from your account—every SKU, quantity, and price.',
                            'points' => ['Pulls from supplier accounts', 'Full SKU-level detail', 'No saving paper slips', 'Nothing missed'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Pulled · Home Depot', 'rows' => [
                                ['icon' => 'globe-alt', 'label' => 'Order #4821', 'sub' => '14 items'],
                                ['icon' => 'list-bullet', 'label' => 'Line detail', 'sub' => 'SKU & qty'],
                                ['icon' => 'folder', 'label' => 'Assigned', 'sub' => 'Maple St'],
                            ]],
                        ],
                        [
                            'heading' => 'Better than a photo',
                            'text' => 'Scraped receipts carry detail a photo can fade or cut off—giving you cleaner records, tighter job costing, and easier returns.',
                            'points' => ['More detail than a photo', 'Cleaner permanent records', 'Tighter job costing', 'Easier returns and warranties'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'The detail on a faded paper receipt is gone in months. Pulling it from the source keeps it forever.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'globe-alt', 'title' => 'From source', 'body' => 'Supplier accounts.'],
                        ['icon' => 'list-bullet', 'title' => 'SKU detail', 'body' => 'Every line.'],
                        ['icon' => 'bolt', 'title' => 'Automatic', 'body' => 'No slips.'],
                        ['icon' => 'folder', 'title' => 'Assigned', 'body' => 'To the job.'],
                        ['icon' => 'arrow-uturn-left', 'title' => 'Returns', 'body' => 'Easy proof.'],
                        ['icon' => 'check-circle', 'title' => 'Permanent', 'body' => 'Never fades.'],
                    ],
                    'cta' => ['heading' => 'Get the full receipt automatically.', 'sub' => 'Itemized detail pulled from your supplier accounts.'],
                ],

                'text-to-task' => [
                    'icon' => 'calendar-date-range',
                    'title' => 'Text-to-task',
                    'body' => 'Turn an incoming message into a scheduled task, reviewed first.',
                    'hero' => 'AI turns messages into scheduled work',
                    'lead' => 'Incoming texts that contain a job to do become draft tasks on your schedule—written by AI, reviewed by you—so nothing slips through the cracks.',
                    'rows' => [
                        [
                            'heading' => 'The AI does the typing',
                            'text' => 'A message that mentions work becomes a draft task with the right job, date, and details filled in. You just glance and confirm.',
                            'points' => ['AI reads incoming messages', 'Drafts a complete task', 'Job, date, and details set', 'You confirm in a tap'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Drafted from text', 'rows' => [
                                ['icon' => 'chat-bubble-left-right', 'label' => 'Incoming', 'sub' => 'Patch drywall Fri'],
                                ['icon' => 'calendar-date-range', 'label' => 'Task', 'sub' => 'Oak Ave · Fri'],
                                ['icon' => 'check-circle', 'label' => 'Confirm', 'sub' => 'One tap'],
                            ]],
                        ],
                        [
                            'heading' => 'Always reviewed first',
                            'text' => 'AI never schedules behind your back. Every suggested task waits for your approval, so you keep full control of your calendar.',
                            'points' => ['Nothing scheduled blindly', 'Approve or edit first', 'Full calendar control', 'Trustworthy automation'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Automation you can trust is automation you can review. Hive drafts; you decide.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'sparkles', 'title' => 'AI drafts', 'body' => 'From a text.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Scheduled', 'body' => 'Right date.'],
                        ['icon' => 'check-circle', 'title' => 'Reviewed', 'body' => 'You approve.'],
                        ['icon' => 'pencil', 'title' => 'Editable', 'body' => 'Tweak first.'],
                        ['icon' => 'folder', 'title' => 'On the job', 'body' => 'Right project.'],
                        ['icon' => 'bell-alert', 'title' => 'Captured', 'body' => 'Never lost.'],
                    ],
                    'cta' => ['heading' => 'Turn messages into scheduled work.', 'sub' => 'AI drafts the task; you confirm in a tap.'],
                ],

                'call-summaries' => [
                    'icon' => 'microphone',
                    'title' => 'Call summaries',
                    'body' => 'Every recorded call transcribed and summarized with action items.',
                    'hero' => 'Every call, summarized for you',
                    'lead' => 'Recorded calls are transcribed and distilled into a short summary with action items—so you get the gist and the to-dos without replaying anything.',
                    'rows' => [
                        [
                            'heading' => 'The takeaways, not the replay',
                            'text' => 'AI turns a long call into a few clear points and a list of action items, attached to the right client and job, ready to act on.',
                            'points' => ['Full transcript captured', 'Short, clear summary', 'Action items pulled out', 'Tied to client and job'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Summary · Henderson', 'rows' => [
                                ['icon' => 'sparkles', 'label' => 'Summary', 'sub' => '3 key points'],
                                ['icon' => 'check-circle', 'label' => 'Action', 'sub' => 'Send tile quote'],
                                ['icon' => 'check-circle', 'label' => 'Action', 'sub' => 'Confirm Mon start'],
                            ]],
                        ],
                        [
                            'heading' => 'Nothing slips after a call',
                            'text' => 'The promises and next steps from a call become tasks you can act on—so the work agreed on the phone actually gets done.',
                            'points' => ['Promises become tasks', 'Next steps never forgotten', 'Follow-through every time', 'A record you can trust'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Most dropped balls start on a call no one wrote down. Summaries with action items close that gap.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'document-text', 'title' => 'Transcribed', 'body' => 'Full text.'],
                        ['icon' => 'sparkles', 'title' => 'Summarized', 'body' => 'Key points.'],
                        ['icon' => 'check-circle', 'title' => 'Action items', 'body' => 'Pulled out.'],
                        ['icon' => 'folder', 'title' => 'Attached', 'body' => 'To the job.'],
                        ['icon' => 'calendar-date-range', 'title' => 'To tasks', 'body' => 'Act on it.'],
                        ['icon' => 'magnifying-glass', 'title' => 'Searchable', 'body' => 'Find it later.'],
                    ],
                    'cta' => ['heading' => 'Get the gist of every call.', 'sub' => 'Transcribed, summarized, with action items ready.'],
                ],

                'maps-autocomplete' => [
                    'icon' => 'map-pin',
                    'title' => 'Maps & autocomplete',
                    'body' => 'Address autocomplete and job-site maps built right in.',
                    'hero' => 'Addresses and maps, built in',
                    'lead' => 'Address autocomplete fills clean, correct job-site addresses as you type, and built-in maps get your crew to the right door every time.',
                    'rows' => [
                        [
                            'heading' => 'Clean addresses, every time',
                            'text' => 'Start typing and pick the verified address. No typos, no wrong unit numbers, no crew driving to the wrong street.',
                            'points' => ['Autocomplete as you type', 'Verified, standardized addresses', 'No typos or wrong units', 'Consistent records'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'New job site', 'rows' => [
                                ['icon' => 'map-pin', 'label' => 'Typed', 'sub' => '142 Maple...'],
                                ['icon' => 'check-badge', 'label' => 'Verified', 'sub' => '142 Maple St'],
                                ['icon' => 'map', 'label' => 'Mapped', 'sub' => 'Directions ready'],
                            ]],
                        ],
                        [
                            'heading' => 'Crews find the door',
                            'text' => 'Every job carries a map and directions, so your crew gets to the right site fast—less time lost, less fuel burned, fewer late starts.',
                            'points' => ['Maps on every job', 'One-tap directions', 'Fewer wrong-site trips', 'On-time starts'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A wrong address is a wasted morning. Clean addresses and built-in maps keep crews moving.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'map-pin', 'title' => 'Autocomplete', 'body' => 'As you type.'],
                        ['icon' => 'check-badge', 'title' => 'Verified', 'body' => 'No typos.'],
                        ['icon' => 'map', 'title' => 'Built-in maps', 'body' => 'On every job.'],
                        ['icon' => 'arrow-top-right-on-square', 'title' => 'Directions', 'body' => 'One tap.'],
                        ['icon' => 'clock', 'title' => 'On time', 'body' => 'Find it fast.'],
                        ['icon' => 'bolt', 'title' => 'Less fuel', 'body' => 'No wrong trips.'],
                    ],
                    'cta' => ['heading' => 'Get crews to the right door.', 'sub' => 'Address autocomplete and maps built right in.'],
                ],

            ],
        ],

    ],

];
