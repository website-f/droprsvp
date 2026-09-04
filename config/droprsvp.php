<?php

return [
    // Buyer-paid booking fee, added to each paid ticket order at checkout: the
    // HIGHER of this percentage of the ticket spend, or the flat amount below.
    // Superadmin-editable under Settings → Payments.
    'platform_fee_percent' => (float) env('DROPRSVP_PLATFORM_FEE_PERCENT', 5),
    'platform_fee_flat' => (float) env('DROPRSVP_PLATFORM_FEE_FLAT', 2),

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
