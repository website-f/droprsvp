<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationCodeMail;
use App\Mail\WelcomeMail;
use App\Models\RegistrationCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/**
 * Eventbrite-style organizer sign-up:
 *   email → 6-digit code → name + password → (post-login) skippable onboarding.
 */
class OrganizerSignupController extends Controller
{
    private const SESSION_KEY = 'organizer_signup_verified_email';

    public function start()
    {
        return Inertia::render('auth/get-started');
    }

    /** Step 1 — send a verification code to the email. */
    public function sendCode(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:180']]);
        $email = mb_strtolower(trim($data['email']));

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists — please log in instead.']);
        }

        // Throttle: max 5 codes per email / 10 minutes.
        $key = 'org-signup-code:'.$email;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please try again in a few minutes.']);
        }
        RateLimiter::hit($key, 600);

        $code = (string) random_int(100000, 999999);
        RegistrationCode::updateOrCreate(
            ['email' => $email],
            ['code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(15), 'attempts' => 0],
        );

        defer(fn () => Mail::to($email)->send(new RegistrationCodeMail($code)));

        return back()->with('success', 'We sent a 6-digit code to '.$email.'.');
    }

    /** Step 2 — verify the code. */
    public function verifyCode(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        $record = RegistrationCode::where('email', $email)->first();
        if (! $record || $record->isExpired()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new one.']);
        }
        if ($record->attempts >= 6) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Request a new code.']);
        }
        $record->increment('attempts');
        if (! Hash::check($data['code'], $record->code_hash)) {
            throw ValidationException::withMessages(['code' => 'That code is incorrect.']);
        }

        // Remember (server-side) that this email is verified for the final step.
        $request->session()->put(self::SESSION_KEY, $email);

        return back()->with('success', 'Email verified.');
    }

    /** Step 3 — create the account and sign in. */
    public function complete(Request $request)
    {
        $verified = $request->session()->get(self::SESSION_KEY);
        abort_if(! $verified, 419);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please agree to the terms to continue.']);

        if (User::where('email', $verified)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        $user = User::create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $verified,
            'password' => Hash::make($data['password']),
        ]);
        // The code proved they own the address — mark verified so the `verified`
        // middleware lets them straight into the dashboard. (email_verified_at is
        // not mass-assignable, so set it explicitly.)
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(Role::firstOrCreate(['name' => 'organizer', 'guard_name' => 'web']));
        // Start an application — they must submit business details + be approved.
        $user->organizerProfile()->firstOrCreate(['user_id' => $user->id], ['status' => 'incomplete']);

        RegistrationCode::where('email', $verified)->delete();
        $request->session()->forget(self::SESSION_KEY);

        // Warm welcome (deferred, non-fatal — a mail hiccup must not block sign-up).
        \App\Support\Mailer::defer($user->email, new WelcomeMail($user));

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('host.apply');
    }

    /** Post-signup onboarding (skippable). */
    public function welcome(Request $request)
    {
        return Inertia::render('host/welcome', [
            'profile' => $request->user()->organizerProfile,
        ]);
    }

    public function saveOnboarding(Request $request)
    {
        $data = $request->validate([
            'event_types' => ['nullable', 'array'],
            'event_types.*' => ['string', 'max:40'],
            'revenue_band' => ['nullable', 'string', 'max:40'],
            'events_per_year' => ['nullable', 'string', 'max:40'],
            'audience_size' => ['nullable', 'string', 'max:40'],
            'age_range' => ['nullable', 'string', 'max:40'],
        ]);

        $request->user()->organizerProfile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [...$data, 'completed_at' => now()],
        );

        return redirect()->route('dashboard')->with('success', 'You’re all set — welcome to DropRSVP!');
    }

    /**
     * Upgrade a signed-in free/attendee account to a vendor (organizer) so they
     * can host & sell tickets — the logged-in equivalent of the guest
     * "Register as a vendor" flow. Starts an application that needs approval.
     */
    public function becomeVendor(Request $request)
    {
        $user = $request->user();

        if (! $user->hasRole('organizer')) {
            $user->assignRole(Role::firstOrCreate(['name' => 'organizer', 'guard_name' => 'web']));
        }
        $user->organizerProfile()->firstOrCreate(['user_id' => $user->id], ['status' => 'incomplete']);

        return redirect()->route('host.apply');
    }
}
