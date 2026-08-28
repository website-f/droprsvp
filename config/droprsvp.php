<?php

return [
    // Percentage the platform keeps from an organizer's gross ticket revenue.
    'platform_fee_percent' => (float) env('DROPRSVP_PLATFORM_FEE_PERCENT', 5),

    // Default price + duration for boosting (promoting) an event. Superadmin-editable.
    'boost_price' => (float) env('DROPRSVP_BOOST_PRICE', 49),
    'boost_days' => (int) env('DROPRSVP_BOOST_DAYS', 7),

    // Premium membership price (per month) + duration in days. Superadmin-editable.
    'premium_price' => (float) env('DROPRSVP_PREMIUM_PRICE', 19),
    'premium_days' => (int) env('DROPRSVP_PREMIUM_DAYS', 30),
];
