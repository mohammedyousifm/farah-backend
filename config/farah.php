<?php

return [
    'currency' => 'SAR',

    // Working hours and time slots are wall-clock times in this timezone; the database stores UTC.
    'timezone' => env('FARAH_TIMEZONE', 'Asia/Riyadh'),

    /*
     * Default values for settings editable from Admin → Settings.
     * Setting::get('key') falls back to these.
     */
    'defaults' => [
        'vat_rate' => 15,                 // percent, prices are VAT-inclusive
        'prices_include_vat' => true,
        'order_payment_timeout_minutes' => 15,
        'settlement_hold_days' => 3,      // days after the booking ends before funds become available
        'settlement_cycle' => 'weekly',   // weekly | biweekly | monthly
        'settlement_min_amount' => 100,   // SAR, below this nothing is paid out
        'document_expiry_warning_days' => 30,
        'max_bookings_per_order' => 4,   // resources a customer can book in one checkout
        'cancellation_window_hours' => 24, // customer self-cancel allowed until N hours before start
        'support_phone' => '+966500000000',
        'support_email' => 'support@farah.sa',
        'platform_name_ar' => 'فرة',
        'platform_name_en' => 'Farah',
        'maintenance_mode' => false,
        'min_app_version_ios' => '1.0.0',
        'min_app_version_android' => '1.0.0',
    ],

    /*
     * Admin RBAC (spatie/laravel-permission, guard "web").
     * Each module gets "<module>.view" and "<module>.manage".
     * super_admin bypasses all checks via Gate::before.
     */
    'admin_modules' => [
        'dashboard', 'users', 'admins', 'providers', 'documents', 'sectors', 'venues',
        'orders', 'refunds', 'disputes', 'settlements', 'commissions', 'ledger',
        'reviews', 'complaints', 'content', 'notifications', 'reports',
        'settings', 'audit_logs', 'integrations',
    ],

    'admin_roles' => [
        'super_admin' => '*',
        'operations' => ['dashboard', 'providers', 'documents', 'sectors', 'venues', 'users.view', 'reviews', 'complaints.view'],
        'finance' => ['dashboard', 'orders', 'refunds', 'disputes', 'settlements', 'commissions', 'ledger', 'reports', 'providers.view'],
        'content' => ['dashboard', 'content', 'notifications', 'venues.view', 'sectors.view'],
        'support' => ['dashboard', 'complaints', 'reviews', 'disputes', 'orders.view', 'users.view', 'venues.view', 'providers.view', 'refunds.view'],
    ],

    'otp' => [
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
        'max_attempts' => 5,
        'resend_seconds' => 60,
        // Fixed code accepted ONLY in local/testing so the mobile team can log in without SMS.
        // Any other environment (staging, production, ...) requires the real code.
        'dev_code' => in_array(env('APP_ENV'), ['local', 'testing'], true) ? env('OTP_DEV_CODE', '1234') : null,
    ],

    'api' => [
        // Sanctum personal access tokens issued to the mobile app expire after this many days.
        'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 90),
        // Upper bound for ?per_page on paginated endpoints.
        'max_per_page' => 50,
    ],

    'integrations' => [
        'payment' => [
            'driver' => env('PAYMENT_DRIVER', 'fake'),
            'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
        ],
        'sms' => ['driver' => env('SMS_DRIVER', 'log'), 'sender' => env('SMS_SENDER', 'FARAH')],
        'wathq' => ['driver' => env('WATHQ_DRIVER', 'fake'), 'api_key' => env('WATHQ_API_KEY')],
        'accounting' => ['driver' => env('ACCOUNTING_DRIVER', 'null')],
        'push' => ['driver' => env('PUSH_DRIVER', 'log')],
    ],

    /*
     * Refund percentages by policy, keyed by "hours before the booking starts" thresholds.
     * The first threshold the booking satisfies wins.
     */
    'refund_policies' => [
        'flexible' => [24 => 100, 0 => 50],
        'moderate' => [72 => 100, 24 => 50, 0 => 0],
        'strict' => [168 => 50, 0 => 0],
        'none' => [0 => 0],
    ],
];
