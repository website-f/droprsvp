<?php

namespace App\Support;

/** Shared demographic option lists for the "about you" profile + admin filters. */
class Profile
{
    public const GENDERS = ['female', 'male', 'other', 'na'];

    public const AGE_BANDS = ['under-18', '18-24', '25-34', '35-44', '45-54', '55+'];

    public const COUNTRIES = [
        'Malaysia', 'Singapore', 'Indonesia', 'Thailand', 'Philippines', 'Vietnam', 'Brunei', 'Cambodia',
        'India', 'China', 'Japan', 'South Korea', 'Hong Kong', 'Taiwan', 'Australia', 'New Zealand',
        'United Kingdom', 'United States', 'Canada', 'United Arab Emirates', 'Saudi Arabia', 'Other',
    ];
}
