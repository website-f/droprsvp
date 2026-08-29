<?php

namespace App\Http\Controllers;

use App\Support\Profile;
use Illuminate\Http\Request;

/**
 * The consumer "about you" profile — required after sign-up so the organizer/
 * superadmin can understand the audience. Mirrors the checkout demographics.
 */
class AboutYouController extends Controller
{
    public function edit(Request $request)
    {
        $u = $request->user();

        return inertia('profile/about-you', [
            'profile' => [
                'phone' => $u->phone,
                'gender' => $u->gender ?: 'na',
                'age_band' => $u->age_band,
                'city' => $u->city,
                'country' => $u->country,
            ],
            'countries' => Profile::COUNTRIES,
            'done' => (bool) $u->profile_completed_at,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'gender' => ['required', 'in:female,male,other,na'],
            'age_band' => ['required', 'in:under-18,18-24,25-34,35-44,45-54,55+'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['required', 'string', 'max:60'],
        ]);

        $request->user()->update([...$data, 'profile_completed_at' => now()]);

        return redirect()->intended(route('dashboard'))->with('success', 'Thanks — your profile is complete.');
    }
}
