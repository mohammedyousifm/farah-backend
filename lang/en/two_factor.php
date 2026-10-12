<?php

// Two-step login for the admin and provider dashboards (a code by SMS or by e-mail).
return [
    'enabled' => 'On',
    'disabled' => 'Off',
    'code' => 'Code',
    'resend' => 'Send the code again',
    'resent' => 'We sent a new code',
    'too_many' => 'Too many attempts. Wait a minute and try again.',
    'invalid' => 'The code is wrong or has expired.',
    'phone' => 'Mobile number',
    'phone_hint' => 'Used to text you a verification code when you sign in.',
    'phone_taken' => 'This number belongs to another account.',

    'sms' => [
        'title' => 'Verification codes by SMS',
        'description' => 'Receive a temporary code by text message on :phone to confirm it is you when signing in.',
        'no_phone' => 'Add your mobile number in the form above and save it to switch SMS codes on.',
        'login_label' => 'Text a code to your mobile',
        'code_label' => 'Enter the 6-digit code we sent to :phone',
        'enable' => 'Switch on',
        'disable' => 'Switch off',
        'enable_heading' => 'Switch on verification codes by SMS',
        'disable_heading' => 'Switch off verification codes by SMS',
        'enabled_notice' => 'Verification codes by SMS are on',
        'disabled_notice' => 'Verification codes by SMS are off',
        'message' => 'Your Farah dashboard sign-in code: :code. Valid for :minutes minutes. Do not share it.',
    ],
];
