<?php

return [

    'sections' => [

        'status' => [
            'label' => 'Live project status',
            'eyebrow' => 'Your homeowner portal',
            'grid_heading' => 'What live status shows you',
            'cards' => [

                'real-time-updates' => [
                    'icon' => 'bolt',
                    'title' => 'Real-time updates',
                    'body' => 'Progress changes the instant your contractor logs it.',
                    'hero' => 'See progress the moment it happens',
                    'lead' => 'When your contractor marks a task done, your project view updates right away—so what you see is always current, no phone call required.',
                    'rows' => [
                        [
                            'heading' => 'Always up to the minute',
                            'text' => 'Your project board reflects the work as it is finished. No waiting for an end-of-week email or wondering whether something moved.',
                            'points' => ['Updates the instant work is logged', 'No refresh or phone call needed', 'See it day or night', 'Always the latest picture'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Just updated', 'rows' => [
                                ['icon' => 'check-circle', 'label' => 'Rough plumbing', 'sub' => 'Marked done · 2:14 PM'],
                                ['icon' => 'wrench-screwdriver', 'label' => 'Electrical rough-in', 'sub' => 'Started today'],
                                ['icon' => 'calendar-date-range', 'label' => 'Inspection', 'sub' => 'Up next · Thu'],
                            ]],
                        ],
                        [
                            'heading' => 'Peace of mind, on demand',
                            'text' => 'Check in whenever you are curious and get a real answer—not a guess. Live updates mean you never feel out of the loop on your own home.',
                            'points' => ['Check progress any time', 'No more wondering', 'Fewer "any update?" calls', 'Confidence your project is moving'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Knowing exactly where your project stands—right now—is the difference between stressing and relaxing during a remodel.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'bolt', 'title' => 'Instant', 'body' => 'Updates as it happens.'],
                        ['icon' => 'clock', 'title' => 'Anytime', 'body' => 'Day or night.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'On your phone', 'body' => 'No app needed.'],
                        ['icon' => 'eye', 'title' => 'Always current', 'body' => 'Never stale.'],
                        ['icon' => 'bell-alert', 'title' => 'Notified', 'body' => 'When it matters.'],
                        ['icon' => 'face-smile', 'title' => 'Reassuring', 'body' => 'Stay in the loop.'],
                    ],
                    'cta' => ['heading' => 'Always know where your project stands.', 'sub' => 'Live updates the moment your contractor logs them.'],
                ],

                'phase-breakdown' => [
                    'icon' => 'list-bullet',
                    'title' => 'Phase breakdown',
                    'body' => 'Each stage of the job spelled out in plain language.',
                    'hero' => 'Understand every stage of your project',
                    'lead' => 'Your job is laid out as clear phases in plain language—so you always know what each step means and where you are in the process.',
                    'rows' => [
                        [
                            'heading' => 'No construction jargon',
                            'text' => 'Each phase is described in everyday terms, so you can follow your project without needing to decode contractor-speak.',
                            'points' => ['Plain-language phase names', 'Know what each step means', 'See the order of work', 'Follow along with ease'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Project phases', 'rows' => [
                                ['icon' => 'check-circle', 'label' => 'Demolition', 'sub' => 'Complete'],
                                ['icon' => 'wrench-screwdriver', 'label' => 'Rough-in', 'sub' => 'In progress'],
                                ['icon' => 'calendar-date-range', 'label' => 'Finishes', 'sub' => 'Upcoming'],
                            ]],
                        ],
                        [
                            'heading' => 'See the whole journey',
                            'text' => 'From demo to final walkthrough, the full set of phases is visible up front—so the project never feels like a black box.',
                            'points' => ['The full journey laid out', 'Know what is coming', 'No surprises mid-project', 'A shared roadmap'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'When you understand the phases, a remodel stops feeling chaotic and starts feeling like a plan you can trust.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'list-bullet', 'title' => 'Clear phases', 'body' => 'Every stage named.'],
                        ['icon' => 'chat-bubble-left-right', 'title' => 'Plain language', 'body' => 'No jargon.'],
                        ['icon' => 'map', 'title' => 'Roadmap', 'body' => 'The whole journey.'],
                        ['icon' => 'arrow-right-circle', 'title' => 'In order', 'body' => 'Step by step.'],
                        ['icon' => 'eye', 'title' => 'Visible', 'body' => 'Up front.'],
                        ['icon' => 'face-smile', 'title' => 'No surprises', 'body' => 'Know what is next.'],
                    ],
                    'cta' => ['heading' => 'Follow every stage in plain language.', 'sub' => 'A clear phase-by-phase roadmap of your project.'],
                ],

                'what-is-next' => [
                    'icon' => 'arrow-right-circle',
                    'title' => 'What is next',
                    'body' => 'Always know the very next step on your project.',
                    'hero' => 'Always know the very next step',
                    'lead' => 'Right at the top of your project, you see what is coming up next—so you can plan your week and know what to expect.',
                    'rows' => [
                        [
                            'heading' => 'The next step, front and center',
                            'text' => 'No digging required. The upcoming task or visit is highlighted so you always know what your contractor is doing next.',
                            'points' => ['Next step highlighted', 'Know what to expect', 'Plan around the work', 'No guessing'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Coming up', 'rows' => [
                                ['icon' => 'arrow-right-circle', 'label' => 'Electrical rough-in', 'sub' => 'In progress'],
                                ['icon' => 'calendar-date-range', 'label' => 'Inspection', 'sub' => 'Thursday'],
                                ['icon' => 'flag', 'label' => 'Drywall', 'sub' => 'Next week'],
                            ]],
                        ],
                        [
                            'heading' => 'Plan your life around it',
                            'text' => 'Knowing what comes next helps you prepare—whether that means clearing a room, being home for a visit, or making a selection in time.',
                            'points' => ['Prepare your home', 'Be ready for visits', 'Make decisions on time', 'Stay one step ahead'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Knowing the next step lets you plan your week around the project instead of being caught off guard.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'arrow-right-circle', 'title' => 'Next step', 'body' => 'Always shown.'],
                        ['icon' => 'calendar-date-range', 'title' => 'When', 'body' => 'On which day.'],
                        ['icon' => 'eye', 'title' => 'Front & center', 'body' => 'No digging.'],
                        ['icon' => 'home', 'title' => 'Prepare', 'body' => 'Ready your home.'],
                        ['icon' => 'check-circle', 'title' => 'On time', 'body' => 'Decide in time.'],
                        ['icon' => 'face-smile', 'title' => 'Ahead', 'body' => 'One step ahead.'],
                    ],
                    'cta' => ['heading' => 'Never wonder what comes next.', 'sub' => 'The next step on your project, always front and center.'],
                ],

                'percent-complete' => [
                    'icon' => 'chart-bar',
                    'title' => 'Percent complete',
                    'body' => 'A simple overall progress bar for the whole job.',
                    'hero' => 'See how far along you are at a glance',
                    'lead' => 'A simple progress bar shows how much of your project is done—so you get the big picture without reading a single detail.',
                    'rows' => [
                        [
                            'heading' => 'The big picture in one number',
                            'text' => 'One glance tells you whether you are just getting started, halfway there, or nearing the finish line.',
                            'points' => ['Overall progress at a glance', 'One simple number', 'No detail-reading required', 'The big picture instantly'],
                            'panel' => ['style' => 'gray', 'type' => 'stat', 'title' => 'Kitchen remodel', 'rows' => [
                                ['label' => 'Overall progress', 'value' => '62%'],
                                ['label' => 'Phases complete', 'value' => '3 of 5'],
                                ['label' => 'On schedule', 'value' => 'Yes'],
                            ]],
                        ],
                        [
                            'heading' => 'Watch it climb',
                            'text' => 'As work gets done, the bar moves—giving you a satisfying, motivating sense of momentum throughout your project.',
                            'points' => ['Progress you can watch grow', 'A motivating milestone', 'Tied to your real job', 'Always accurate'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A simple progress bar turns the stress of "are we there yet?" into the satisfaction of watching your home come together.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'chart-bar', 'title' => 'Progress bar', 'body' => 'The whole job.'],
                        ['icon' => 'eye', 'title' => 'At a glance', 'body' => 'One number.'],
                        ['icon' => 'bolt', 'title' => 'Live', 'body' => 'Climbs as you go.'],
                        ['icon' => 'flag', 'title' => 'Milestones', 'body' => 'Phases done.'],
                        ['icon' => 'check-circle', 'title' => 'Accurate', 'body' => 'Your real job.'],
                        ['icon' => 'face-smile', 'title' => 'Motivating', 'body' => 'Watch it grow.'],
                    ],
                    'cta' => ['heading' => 'See your whole project at a glance.', 'sub' => 'One simple progress bar for the entire job.'],
                ],

                'on-site-activity' => [
                    'icon' => 'users',
                    'title' => 'On-site activity',
                    'body' => 'See what the crew is working on right now.',
                    'hero' => 'Know what the crew is doing today',
                    'lead' => 'See the work happening on your home right now—so you always know who is on site and what they are getting done.',
                    'rows' => [
                        [
                            'heading' => 'Today on your home',
                            'text' => 'Open your project and see the task the crew is focused on today, so you are never left guessing what is happening behind the plastic sheeting.',
                            'points' => ['See today\'s work', 'Know the crew is on site', 'Understand what is happening', 'Stay connected to the job'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'On site today', 'rows' => [
                                ['icon' => 'users', 'label' => 'Crew on site', 'sub' => '2 people'],
                                ['icon' => 'wrench-screwdriver', 'label' => 'Working on', 'sub' => 'Electrical rough-in'],
                                ['icon' => 'clock', 'label' => 'Since', 'sub' => '8:00 AM'],
                            ]],
                        ],
                        [
                            'heading' => 'Feel connected to the work',
                            'text' => 'Even when you are at work or away, you can see your project moving forward—so the remodel never feels like it stalled.',
                            'points' => ['Stay connected from anywhere', 'See momentum daily', 'Never feel in the dark', 'Trust the work is happening'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Seeing the crew at work on your home—even from your desk—replaces anxiety with confidence.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'users', 'title' => 'On site', 'body' => 'Who is there.'],
                        ['icon' => 'wrench-screwdriver', 'title' => 'Today\'s task', 'body' => 'What they do.'],
                        ['icon' => 'clock', 'title' => 'Since when', 'body' => 'Start time.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'From anywhere', 'body' => 'On your phone.'],
                        ['icon' => 'bolt', 'title' => 'Momentum', 'body' => 'See it move.'],
                        ['icon' => 'face-smile', 'title' => 'Connected', 'body' => 'Never in the dark.'],
                    ],
                    'cta' => ['heading' => 'See your home being worked on.', 'sub' => 'Know who is on site and what they are doing today.'],
                ],

                'history-log' => [
                    'icon' => 'clock',
                    'title' => 'History log',
                    'body' => 'Look back at what was completed and when.',
                    'hero' => 'A complete record of every step',
                    'lead' => 'Look back at everything that was completed and exactly when—so you have a clear, lasting record of your whole project.',
                    'rows' => [
                        [
                            'heading' => 'Every step, on the record',
                            'text' => 'As each task is finished, it is logged with a date. The full history of your project is always there to review.',
                            'points' => ['Every completed step logged', 'Dated so the timeline is clear', 'Review any past stage', 'A lasting record'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'History', 'rows' => [
                                ['icon' => 'check-circle', 'label' => 'Demolition', 'sub' => 'Done · Jun 3'],
                                ['icon' => 'check-circle', 'label' => 'Rough plumbing', 'sub' => 'Done · Jun 12'],
                                ['icon' => 'check-circle', 'label' => 'Framing', 'sub' => 'Done · Jun 18'],
                            ]],
                        ],
                        [
                            'heading' => 'Useful long after the job',
                            'text' => 'Your history stays available, so years later you can confirm what was done and when—handy for warranties, future work, or selling your home.',
                            'points' => ['Available after the job', 'Helpful for warranties', 'A reference for future work', 'Proof of what was done'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A dated record of your remodel is the kind of thing you will be glad to have years down the road.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'clock', 'title' => 'Dated log', 'body' => 'When it happened.'],
                        ['icon' => 'check-circle', 'title' => 'Every step', 'body' => 'All recorded.'],
                        ['icon' => 'magnifying-glass', 'title' => 'Reviewable', 'body' => 'Look back anytime.'],
                        ['icon' => 'shield-check', 'title' => 'Lasting', 'body' => 'Kept for you.'],
                        ['icon' => 'document-text', 'title' => 'Warranties', 'body' => 'Handy proof.'],
                        ['icon' => 'home', 'title' => 'Future work', 'body' => 'A reference.'],
                    ],
                    'cta' => ['heading' => 'Keep a record of your whole project.', 'sub' => 'Every completed step, dated and saved for you.'],
                ],

            ],
        ],

        'schedule' => [
            'label' => 'Schedule & reminders',
            'eyebrow' => 'Your homeowner portal',
            'grid_heading' => 'Your schedule, working for you',
            'cards' => [

                'live-schedule-link' => [
                    'icon' => 'link',
                    'title' => 'Live schedule link',
                    'body' => 'One link, texted to you, that is always current.',
                    'hero' => 'One link that is always up to date',
                    'lead' => 'Your contractor texts you a single schedule link—and it stays current automatically, so you never chase down the latest plan.',
                    'rows' => [
                        [
                            'heading' => 'Always the latest plan',
                            'text' => 'When dates change, your link updates on its own. No new texts to sort through, no outdated printouts to throw away.',
                            'points' => ['One link, always current', 'Updates itself when dates move', 'No confusing message threads', 'Open it any time'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Your schedule', 'rows' => [
                                ['icon' => 'calendar-date-range', 'label' => 'Demo', 'sub' => 'Tue 6/30 · 8 AM'],
                                ['icon' => 'wrench-screwdriver', 'label' => 'Rough plumbing', 'sub' => 'Thu 7/2'],
                                ['icon' => 'bolt', 'label' => 'Electrical', 'sub' => 'Mon 7/6'],
                            ]],
                        ],
                        [
                            'heading' => 'Nothing to install',
                            'text' => 'Tap the link and your schedule opens right in your phone. No app, no login, no password to remember.',
                            'points' => ['Opens in your browser', 'No app or password', 'Works on any device', 'Effortless to check'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A single always-current link means you and your contractor are never looking at two different schedules.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'link', 'title' => 'One link', 'body' => 'Always current.'],
                        ['icon' => 'bolt', 'title' => 'Auto-updates', 'body' => 'When dates move.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'No app', 'body' => 'Opens in browser.'],
                        ['icon' => 'finger-print', 'title' => 'No password', 'body' => 'Just tap.'],
                        ['icon' => 'clock', 'title' => 'Anytime', 'body' => 'Check whenever.'],
                        ['icon' => 'face-smile', 'title' => 'Effortless', 'body' => 'Nothing to chase.'],
                    ],
                    'cta' => ['heading' => 'Keep the latest schedule in your pocket.', 'sub' => 'One link that updates itself—no app required.'],
                ],

                'upcoming-visits' => [
                    'icon' => 'calendar-date-range',
                    'title' => 'Upcoming visits',
                    'body' => 'See exactly what is planned and on which day.',
                    'hero' => 'Know exactly when the crew is coming',
                    'lead' => 'See what is planned and on which day, so you can be ready when the crew arrives and plan your own week around the work.',
                    'rows' => [
                        [
                            'heading' => 'Every visit, laid out',
                            'text' => 'Each upcoming visit shows what is happening and when—so there are no surprise knocks at the door and no missed days.',
                            'points' => ['See each planned visit', 'Know the date and the work', 'No surprise arrivals', 'Be home when it counts'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Upcoming', 'rows' => [
                                ['icon' => 'calendar-date-range', 'label' => 'Tile delivery', 'sub' => 'Mon 7/6'],
                                ['icon' => 'wrench-screwdriver', 'label' => 'Tile install', 'sub' => 'Wed 7/8'],
                                ['icon' => 'clipboard-document-check', 'label' => 'Inspection', 'sub' => 'Fri 7/10'],
                            ]],
                        ],
                        [
                            'heading' => 'Plan around the work',
                            'text' => 'Knowing the schedule lets you arrange your life—work from home on big days, keep pets clear, or just know when to expect noise.',
                            'points' => ['Arrange your week', 'Keep pets and kids clear', 'Work from home if needed', 'No disruptions out of nowhere'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Seeing visits in advance turns a disruptive remodel into something you can comfortably plan your life around.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'calendar-date-range', 'title' => 'Each visit', 'body' => 'Date and work.'],
                        ['icon' => 'eye', 'title' => 'Laid out', 'body' => 'See it ahead.'],
                        ['icon' => 'home', 'title' => 'Be ready', 'body' => 'When they come.'],
                        ['icon' => 'truck', 'title' => 'Deliveries', 'body' => 'Know when.'],
                        ['icon' => 'clipboard-document-check', 'title' => 'Inspections', 'body' => 'On the calendar.'],
                        ['icon' => 'face-smile', 'title' => 'No surprises', 'body' => 'Plan ahead.'],
                    ],
                    'cta' => ['heading' => 'Be ready for every visit.', 'sub' => 'See exactly what is planned and when.'],
                ],

                'change-alerts' => [
                    'icon' => 'bell-alert',
                    'title' => 'Change alerts',
                    'body' => 'Get a text the moment a date shifts.',
                    'hero' => 'Hear about changes the moment they happen',
                    'lead' => 'When a date moves, you get a text right away—so a weather delay or supplier hiccup never catches you off guard.',
                    'rows' => [
                        [
                            'heading' => 'No more surprises',
                            'text' => 'Schedules change in construction. When yours does, you find out immediately instead of waiting at home for a crew that is not coming.',
                            'points' => ['Texted the moment a date moves', 'Never wait on a no-show', 'Know the new plan instantly', 'Stay in sync with the crew'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Recent alert', 'rows' => [
                                ['icon' => 'bell-alert', 'label' => 'Tile install moved', 'sub' => 'Wed → Thu'],
                                ['icon' => 'cloud', 'label' => 'Reason', 'sub' => 'Rain delay'],
                                ['icon' => 'check-circle', 'label' => 'New date set', 'sub' => 'Thu 7/9'],
                            ]],
                        ],
                        [
                            'heading' => 'Always know the why',
                            'text' => 'Changes come with context where it helps, so a shift in the plan feels understandable—not like the project is falling apart.',
                            'points' => ['Understand what changed', 'Context where it helps', 'Less worry, more clarity', 'Trust the process'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'An instant heads-up about a moved date is the difference between frustration and simply adjusting your plans.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'bell-alert', 'title' => 'Instant text', 'body' => 'When dates move.'],
                        ['icon' => 'home', 'title' => 'No no-shows', 'body' => 'Never wait.'],
                        ['icon' => 'arrow-path', 'title' => 'New plan', 'body' => 'Right away.'],
                        ['icon' => 'chat-bubble-left-right', 'title' => 'Context', 'body' => 'Know the why.'],
                        ['icon' => 'check-circle', 'title' => 'In sync', 'body' => 'With the crew.'],
                        ['icon' => 'face-smile', 'title' => 'Less worry', 'body' => 'More clarity.'],
                    ],
                    'cta' => ['heading' => 'Never be caught off guard by a change.', 'sub' => 'A text the moment your schedule shifts.'],
                ],

                'reminders' => [
                    'icon' => 'clock',
                    'title' => 'Reminders',
                    'body' => 'A nudge before scheduled work so you can plan around it.',
                    'hero' => 'A friendly nudge before each visit',
                    'lead' => 'Get a reminder before scheduled work, so you have time to prepare the space, move your car, or just be ready for the crew.',
                    'rows' => [
                        [
                            'heading' => 'Time to get ready',
                            'text' => 'A reminder lands ahead of the work, giving you a chance to clear the room, secure pets, or make sure someone is home.',
                            'points' => ['Reminded before each visit', 'Time to prep the space', 'Move cars and pets', 'Be home if needed'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Reminder', 'rows' => [
                                ['icon' => 'clock', 'label' => 'Tomorrow', 'sub' => 'Tile install · 8 AM'],
                                ['icon' => 'home', 'label' => 'Prep', 'sub' => 'Clear the kitchen'],
                                ['icon' => 'check-circle', 'label' => 'You\'re set', 'sub' => 'Ready to go'],
                            ]],
                        ],
                        [
                            'heading' => 'Never blindsided',
                            'text' => 'Instead of remembering the schedule yourself, let the reminders do it—so a visit never sneaks up on a busy week.',
                            'points' => ['No need to track dates', 'Visits never sneak up', 'One less thing to juggle', 'Stress-free preparation'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'A simple reminder the day before means you are always prepared, even during the busiest weeks.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'clock', 'title' => 'Before work', 'body' => 'A timely nudge.'],
                        ['icon' => 'home', 'title' => 'Prep time', 'body' => 'Ready the space.'],
                        ['icon' => 'bell-alert', 'title' => 'Automatic', 'body' => 'No tracking.'],
                        ['icon' => 'truck', 'title' => 'Move cars', 'body' => 'Clear the way.'],
                        ['icon' => 'check-circle', 'title' => 'Be ready', 'body' => 'Every time.'],
                        ['icon' => 'face-smile', 'title' => 'Stress-free', 'body' => 'Never blindsided.'],
                    ],
                    'cta' => ['heading' => 'Always be ready for the crew.', 'sub' => 'A friendly reminder before every scheduled visit.'],
                ],

                'milestones' => [
                    'icon' => 'flag',
                    'title' => 'Milestones',
                    'body' => 'Track the big moments from start to finish.',
                    'hero' => 'Celebrate the milestones along the way',
                    'lead' => 'Track the big moments of your project—from demo day to final walkthrough—so you can see and savor the progress.',
                    'rows' => [
                        [
                            'heading' => 'The moments that matter',
                            'text' => 'Major milestones are marked clearly, so the meaningful steps stand out from the everyday tasks.',
                            'points' => ['Big moments highlighted', 'See the journey\'s key steps', 'Know when you hit each one', 'Something to look forward to'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Milestones', 'rows' => [
                                ['icon' => 'check-circle', 'label' => 'Demo day', 'sub' => 'Complete'],
                                ['icon' => 'flag', 'label' => 'Cabinets in', 'sub' => 'Next week'],
                                ['icon' => 'flag', 'label' => 'Final walkthrough', 'sub' => 'Coming soon'],
                            ]],
                        ],
                        [
                            'heading' => 'Watch your home take shape',
                            'text' => 'Each milestone is a sign your vision is becoming real—turning a long project into a series of rewarding wins.',
                            'points' => ['See your vision arrive', 'Rewarding checkpoints', 'A long job feels shorter', 'Share the excitement'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'Milestones turn months of work into a series of moments worth celebrating as your home comes together.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'flag', 'title' => 'Big moments', 'body' => 'Marked clearly.'],
                        ['icon' => 'eye', 'title' => 'Stand out', 'body' => 'From daily tasks.'],
                        ['icon' => 'check-circle', 'title' => 'Hit them', 'body' => 'Know when.'],
                        ['icon' => 'home', 'title' => 'Take shape', 'body' => 'See it arrive.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Look ahead', 'body' => 'Anticipate.'],
                        ['icon' => 'face-smile', 'title' => 'Celebrate', 'body' => 'Rewarding wins.'],
                    ],
                    'cta' => ['heading' => 'Savor every milestone.', 'sub' => 'Track the big moments from demo to done.'],
                ],

                'on-any-device' => [
                    'icon' => 'device-phone-mobile',
                    'title' => 'On any device',
                    'body' => 'Open it right in your phone—no app required.',
                    'hero' => 'Check your schedule anywhere',
                    'lead' => 'Your schedule works on whatever you have in hand—phone, tablet, or computer—with no app to download and nothing to set up.',
                    'rows' => [
                        [
                            'heading' => 'Whatever device you have',
                            'text' => 'Open the link on your phone during the day or on your laptop at night. It looks great and works the same everywhere.',
                            'points' => ['Phone, tablet, or computer', 'Looks great on any screen', 'Same experience everywhere', 'Always within reach'],
                            'panel' => ['style' => 'gray', 'type' => 'list', 'title' => 'Works on', 'rows' => [
                                ['icon' => 'device-phone-mobile', 'label' => 'Phone', 'sub' => 'On the go'],
                                ['icon' => 'computer-desktop', 'label' => 'Computer', 'sub' => 'At home'],
                                ['icon' => 'check-circle', 'label' => 'No app', 'sub' => 'Just a link'],
                            ]],
                        ],
                        [
                            'heading' => 'Nothing to set up',
                            'text' => 'There is no app store, no account creation, no password. You tap a link and you are in—simple enough for anyone in the household.',
                            'points' => ['No app store visit', 'No account to create', 'Anyone can use it', 'Effortless access'],
                            'panel' => ['style' => 'indigo', 'type' => 'note', 'label' => 'Why it matters', 'note' => 'No app and no password means the whole household can check the schedule without any hassle.'],
                        ],
                    ],
                    'features' => [
                        ['icon' => 'device-phone-mobile', 'title' => 'Phone', 'body' => 'On the go.'],
                        ['icon' => 'computer-desktop', 'title' => 'Computer', 'body' => 'At home.'],
                        ['icon' => 'arrow-down-tray', 'title' => 'No app', 'body' => 'Nothing to install.'],
                        ['icon' => 'finger-print', 'title' => 'No password', 'body' => 'Just a link.'],
                        ['icon' => 'eye', 'title' => 'Looks great', 'body' => 'Any screen.'],
                        ['icon' => 'face-smile', 'title' => 'Easy', 'body' => 'For anyone.'],
                    ],
                    'cta' => ['heading' => 'Your schedule, on any screen.', 'sub' => 'Phone, tablet, or computer—no app required.'],
                ],

            ],
        ],

    ],

];
