<?php
// Edit these values to match your setup.
return [
    // Database
    'db_host'  => '127.0.0.1',
    'db_port'  => 3306,
    'db_name'  => 'resort_db',
    'db_user'  => 'root',
    'db_pass'  => '',

    // Set to true on your own computer to see detailed errors. Keep false on a live site.
    'debug'    => false,

    // Name of the whole site (shown on the resort directory). Each resort has its own name, times and contact details on the staff desk.
    'platform'      => 'Vista',

    // Defaults only: the selected resort overrides these
    'resort'        => "Vista del Mar Resort",
    'tagline'       => 'Casitas by the sea, treehouses, and curacha at sunset.',
    'currency'      => '₱',
    'timezone'      => 'Asia/Manila',
    'checkin_time'  => '2:00 PM',
    'checkout_time' => '12:00 NN',
    'contact_email' => 'reservations@resort.test',
    'contact_phone' => '+63 900 000 0000',

    // ---------- Email ----------
    // driver 'log'  : nothing is sent; each email is written to storage/mail.log (good for testing on your own computer).
    // driver 'smtp' : real email through your SMTP account (Gmail app password, Brevo, Mailtrap, your host...).
    'mail' => [
        'driver'     => 'smtp',
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'encryption' => 'tls',
        'username'   => 'bajeloveleandro@gmail.com',
        'password'   => 'wguh qhvr icie wcsj',
        'from_email' => 'bajeloveleandro@gmail.com',
        'from_name'  => 'Vista',
    ],

    // Guests must enter a 6-digit code emailed to them before an online booking is created.
    'verify_email' => true,

    // ---------- Security ----------
    // Full address of the site, no trailing slash, used for links in emails (for example https://resort.example.com/resort).
    // Leave '' on your own computer. SET THIS on a live site so password-reset links can't be spoofed.
    'base_url'    => '',
    // Redirect every http:// request to https:// (turn on once your site has an SSL certificate).
    'force_https' => false,

    // Key that protects guest ID numbers: 64 hex characters (generate one with: php -r "echo bin2hex(random_bytes(32));").
    // Leave '' to let the site create storage/id.key on first use. Back the key up: lost key = unreadable ID numbers.
    'id_key'      => '',

    // Booking rules (applied to online bookings and staff bookings)
    'min_nights'          => 1,
    'max_nights'          => 30,
    'max_advance_days'    => 365,   // how far ahead guests can book
    'max_rooms_per_booking' => 5,
    'max_guests_per_booking' => 20,
    'free_cancel_days'    => 2,     // guests may cancel online if check-in is at least this many days away

    // ---------- On-site e-wallet payments (GCash & Maya — staff verifies via reference number) ----------
    // Each resort sets its own required downpayment % and GCash / Maya account details in Admin > Settings.
    'payment' => [
        'methods' => ['gcash', 'paymaya'],
    ],
];
