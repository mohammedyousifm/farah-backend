<?php

// Public landing page (/).
return [
    'meta_title' => 'Farah | Book your table, lane or court by the hour',
    'meta_description' => 'Farah is a Saudi platform for booking entertainment and game venues by the hour: billiards, bowling, gaming, karting, padel and more. List your venue and take bookings and payments per table, lane and room.',
    'switch_lang' => 'العربية',

    'nav' => [
        'how' => 'How it works',
        'features' => 'Features',
        'sectors' => 'Activities',
        'plans' => 'Plans',
        'app' => 'Customer app',
        'faq' => 'FAQ',
    ],
    'sign_in' => 'Sign in',
    'join' => 'List your venue',
    'plans' => [
        'title' => 'Plans for store owners',
        'subtitle' => 'Start free, and move up whenever you want more visibility and more activities.',
        'free' => 'Free',
        'currency' => 'SAR',
        'days' => ':days days',
        'up_to' => 'up to :count',
        'start_free' => 'Start free',
        'choose' => 'Start with this plan',
        'note' => 'You choose your plan when you sign up. A paid plan starts once your account is approved and the payment confirmed; until then you are on the free plan.',
    ],

    'hero' => [
        'badge' => 'For billiards halls, bowling centres, gaming lounges, karting tracks and courts',
        'cta_join' => 'List your venue for free',
        'cta_sign_in' => 'I have an account, sign in',
    ],

    'how' => [
        'title' => 'From sign-up to your first payout',
        'subtitle' => 'Five simple steps between you and taking bookings on Farah.',
        'steps' => [
            ['title' => 'Register', 'body' => 'Create your business account and choose the activity types you offer.'],
            ['title' => 'Verify documents', 'body' => 'Upload your CR or national ID and licences; our team reviews them quickly.'],
            ['title' => 'Add your venue', 'body' => 'Set the location and working hours, add activities, the hourly price and how many tables, lanes or rooms you have, then submit for review.'],
            ['title' => 'Take bookings', 'body' => 'Customers pick a table and a time and pay in the app, and you can record walk-in bookings from your dashboard too.'],
            ['title' => 'Get paid', 'body' => 'Your earnings are transferred to your bank in clear, itemised settlements.'],
        ],
    ],

    'features' => [
        'title' => 'Everything you need to run your venue’s bookings',
        'subtitle' => 'A complete web dashboard for you and your team.',
        'items' => [
            ['icon' => 'calendar-days', 'title' => 'Hourly booking per table', 'body' => 'Every table, lane or room has its own schedule, and a booked slot closes automatically, so nothing is booked twice.'],
            ['icon' => 'clock', 'title' => 'Flexible hours and prices', 'body' => 'Set working hours per day, even past midnight, plus the hourly price, slot length and the shortest and longest booking.'],
            ['icon' => 'qr-code', 'title' => 'Arrival check-in', 'body' => 'Scan or type the booking code when the customer arrives for instant validation and no double use.'],
            ['icon' => 'user-group', 'title' => 'Team permissions', 'body' => 'Add managers and staff with precise access: bookings, check-in, finance and more.'],
            ['icon' => 'banknotes', 'title' => 'Finance & payouts', 'body' => 'Available and pending balances, a detailed statement and regular settlements.'],
            ['icon' => 'chart-bar', 'title' => 'Live dashboard', 'body' => 'Today’s bookings, this month’s sales, upcoming slots and customer reviews in one place.'],
        ],
    ],

    'sectors' => [
        'title' => 'Activities that fit your venue',
        'subtitle' => 'Pick your activity type, or request a new one after you register.',
        'categories' => '{0} No sub-categories|{1} 1 category|[2,*] :count categories',
        'empty' => 'Activities will be announced soon.',
    ],

    'app' => [
        'title' => 'For your customers: the Farah app',
        'subtitle' => 'Customers discover venues near them, choose the game, the table and the time, pay securely and show their booking code on arrival.',
        'point_1' => 'Browse venues by city and type of game',
        'point_2' => 'Live availability per table and secure payment',
        'point_3' => 'Reviews, favourites and alerts',
        'soon' => 'Coming soon',
        'app_store' => 'App Store',
        'google_play' => 'Google Play',
        'download_on' => 'Download on the',
        'get_it_on' => 'Get it on',
        'screenshot_alt' => 'A tour of the Farah app: welcome, sign in, explore, choosing a time, payment and the booking code',
    ],

    'stats' => [
        'label' => 'Farah in numbers',
        'providers' => 'approved providers',
        'venues' => 'bookable venues',
        'sectors' => 'activity types',
        'cities' => 'cities',
    ],

    'faq' => [
        'title' => 'Frequently asked questions',
        'items' => [
            ['q' => 'Is joining Farah free?', 'a' => 'Yes. Registration is free and there is no subscription; a commission applies to paid bookings only.'],
            ['q' => 'Who can join as a provider?', 'a' => 'Registered businesses that run entertainment or game venues booked by the hour: billiards halls, bowling centres, gaming lounges, karting tracks, courts and the other activities available on the platform.'],
            ['q' => 'How does a customer book?', 'a' => 'They choose the activity, then the table, lane or room and a free time, and pay in the app. The slot is held for them alone, and they show the booking code on arrival.'],
            ['q' => 'Which documents are required?', 'a' => 'A commercial registration, an IBAN letter, a Google Maps link to your place, and any activity licence that applies.'],
            ['q' => 'Can I record a walk-in customer?', 'a' => 'Yes. Record the walk-in booking from your dashboard and the slot closes in the app at once, so it is never booked twice.'],
            ['q' => 'When do I get paid?', 'a' => 'Funds become available after the booked time is over plus a short hold period, then are transferred to your bank in the settlement cycle.'],
            ['q' => 'Can my team use the account?', 'a' => 'Yes. Add team members with different roles and permissions; each one signs in with their own login.'],
        ],
    ],

    'cta' => [
        'title' => 'Ready to take bookings for your venue?',
        'subtitle' => 'Join the venue owners on Farah and start today.',
    ],

    'footer' => [
        'tagline' => 'The platform for booking entertainment and game venues by the hour, for customers and venue owners.',
        'providers' => 'For providers',
        'contact' => 'Contact us',
        'rights' => 'All rights reserved.',
    ],
];
