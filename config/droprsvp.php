<?php

return [
    // How the platform charges organizers on ticket revenue: 'percent' of gross,
    // or 'fixed' — a flat amount per paid order. Superadmin-editable under Settings.
    'platform_fee_type' => env('DROPRSVP_PLATFORM_FEE_TYPE', 'percent'),
    // Percentage the platform keeps from an organizer's gross ticket revenue.
    'platform_fee_percent' => (float) env('DROPRSVP_PLATFORM_FEE_PERCENT', 5),
    // Flat amount kept per paid order when the fee type is 'fixed'.
    'platform_fee_fixed' => (float) env('DROPRSVP_PLATFORM_FEE_FIXED', 0),

    // Default price + duration for boosting (promoting) an event. Superadmin-editable.
    'boost_price' => (float) env('DROPRSVP_BOOST_PRICE', 49),
    'boost_days' => (int) env('DROPRSVP_BOOST_DAYS', 7),

    // Premium membership price (per month) + duration in days. Superadmin-editable.
    'premium_price' => (float) env('DROPRSVP_PREMIUM_PRICE', 19),
    'premium_days' => (int) env('DROPRSVP_PREMIUM_DAYS', 30),

    // Tax applied at checkout (0 = off). Superadmin-editable under Admin → Settings.
    'tax_percent' => (float) env('DROPRSVP_TAX_PERCENT', 0),
    'tax_label' => env('DROPRSVP_TAX_LABEL', 'SST'),
];
