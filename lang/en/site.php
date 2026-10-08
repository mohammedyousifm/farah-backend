<?php

// Public website: shared layout, static pages, contact page and error pages.
return [
    'brand' => 'Farah',
    'og_image_alt' => 'Farah logo',

    'a11y' => [
        'skip' => 'Skip to content',
        'main_nav' => 'Main navigation',
        'footer_nav' => 'Site links',
        'menu_open' => 'Open menu',
        'menu_close' => 'Close menu',
        'breadcrumb' => 'Breadcrumb',
        'menu' => 'Menu',
        'home' => 'Farah home page',
        'switch_lang' => 'View this page in Arabic',
    ],

    'nav' => [
        'home' => 'Home',
        'contact' => 'Contact',
    ],

    'footer' => [
        'company' => 'Company',
        'whatsapp' => 'WhatsApp',
    ],

    'page' => [
        'updated' => 'Last updated: :date',
        'empty' => 'This page is being updated. Contact us if you have any questions.',
        'other_pages' => 'Other pages',
        'help_title' => 'Have a question?',
        'help_body' => 'Our support team is happy to help.',
    ],

    'contact' => [
        'meta_title' => 'Contact us | Farah',
        'meta_description' => 'Reach the Farah support team by phone, email or WhatsApp with any question about the platform or joining as a provider.',
        'badge' => 'Support',
        'title' => 'Contact us',
        'subtitle' => 'Whether you are a provider or a customer, our team is happy to answer your questions.',
        'phone' => 'Phone',
        'phone_hint' => 'Call us directly',
        'email' => 'Email',
        'email_hint' => 'We usually reply within one business day',
        'whatsapp' => 'WhatsApp',
        'whatsapp_hint' => 'Message us any time',
        'providers_title' => 'Already a provider on Farah?',
        'providers_body' => 'Sign in to your dashboard to follow your venues, bookings and payouts.',
        'faq_title' => 'Quick answers',
        'faq_body' => 'Your question may already be answered in the FAQ.',
        'faq_link' => 'Browse the FAQ',
        'none' => 'Contact details will be available soon.',
    ],

    'errors' => [
        'home' => 'Back to home',
        'contact' => 'Contact us',
        'retry' => 'Try again',
        '401' => [
            'title' => 'Sign-in required',
            'body' => 'Please sign in to access this page.',
        ],
        '403' => [
            'title' => 'Access denied',
            'body' => 'You do not have permission to view this page.',
        ],
        '419' => [
            'title' => 'Page expired',
            'body' => 'Your session expired to keep your account safe. Refresh the page and try again.',
        ],
        '429' => [
            'title' => 'Too many requests',
            'body' => 'You sent too many requests in a short time. Please wait a moment and try again.',
        ],
        '404' => [
            'title' => 'Page not found',
            'body' => 'The page may have moved or is no longer available. Check the link or head back home.',
        ],
        '500' => [
            'title' => 'Something went wrong',
            'body' => 'We hit a problem while handling your request. Our team is on it; please try again shortly.',
        ],
        '503' => [
            'title' => 'Back soon',
            'body' => 'We are doing some maintenance to improve the platform. Thanks for your patience, we will be back shortly.',
        ],
    ],
];
