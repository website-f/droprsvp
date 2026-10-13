<?php

namespace App\Http\Controllers;

use App\Support\Cities;
use App\Support\Profile;
use Illuminate\Http\Request;

/**
 * The consumer "about you" profile, shown after sign-up to anyone without a
 * phone number on file. Only the phone is required; the rest mirrors the
 * checkout demographics and is there for anyone happy to help the organizer
 * understand their audience.
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
                'birth_year' => $u->birth_year,
                'city' => $u->city,
                'country' => $u->country,
            ],
            'countries' => Profile::COUNTRIES,
            // Grouped by state so the picker can narrow a 66-city list to
            // the dozen that belong to whichever state they choose.
            'cities' => Cities::all(),
            'birthYears' => Profile::birthYears(),
            'done' => (bool) $u->profile_completed_at,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            // The one thing this form insists on. Everything else used to be
            // required too, on a page that marked none of it — so people met
            // "The birth year field is required" only after pressing Continue.
            'phone' => ['required', 'string', 'max:40'],
            'gender' => ['nullable', 'in:female,male,other,na'],
            // A year, not a band: a band goes stale on the next birthday and
            // asks people to place themselves. Profile::bandFor derives the band
            // so analytics and the admin filters keep working unchanged.
            'birth_year' => ['nullable', 'integer', 'min:'.Profile::EARLIEST_BIRTH_YEAR, 'max:'.date('Y')],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:60'],
        ]);

        $user = $request->user();

        // A field left blank keeps whatever was there before, so skipping a
        // question here never wipes an answer given at checkout.
        $answered = array_filter($data, fn ($value) => filled($value));

        $user->update([
            ...$answered,
            ...(isset($answered['birth_year']) ? ['age_band' => Profile::bandFor((int) $answered['birth_year'])] : []),
            'profile_completed_at' => now(),
        ]);

        return redirect()->intended(route('dashboard'))->with('success', 'Thanks — your profile is complete.');
    }
}
