<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * "Continue with Google" — a lightweight OAuth 2.0 flow using the standard
 * Google endpoints (no extra package). New sign-ins become free attendee
 * accounts (buyer role); existing accounts are matched by google_id then email.
 */
class GoogleController extends Controller
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const USERINFO = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function redirect(Request $request)
    {
        abort_unless($this->configured(), 404);

        // CSRF state for the round-trip.
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away(self::AUTH.'?'.http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request)
    {
        abort_unless($this->configured(), 404);

        // Verify state + presence of a code (user may have cancelled).
        $state = $request->session()->pull('google_oauth_state');
        if (! $state || ! hash_equals($state, (string) $request->query('state')) || ! $request->filled('code')) {
            return redirect()->route('login')->withErrors(['email' => 'Google sign-in was cancelled or failed. Please try again.']);
        }

        $token = Http::asForm()->post(self::TOKEN, [
            'code' => $request->query('code'),
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = $token->json('access_token');
        if (! $token->successful() || ! $accessToken) {
            return redirect()->route('login')->withErrors(['email' => 'Could not verify your Google account. Please try again.']);
        }

        $profile = Http::withToken($accessToken)->get(self::USERINFO)->json();
        $email = $profile['email'] ?? null;
        $googleId = $profile['sub'] ?? null;
        if (! $email || ! $googleId) {
            return redirect()->route('login')->withErrors(['email' => 'Google didn’t return your email. Please try another method.']);
        }

        $user = $this->findOrCreate($googleId, $email, $profile);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function findOrCreate(string $googleId, string $email, array $profile): User
    {
        $user = User::where('google_id', $googleId)->first()
            ?? User::where('email', mb_strtolower($email))->first();

        if ($user) {
            // Link Google + backfill avatar/verification on an existing account.
            $user->forceFill(array_filter([
                'google_id' => $user->google_id ?: $googleId,
                'avatar' => $user->avatar ?: ($profile['picture'] ?? null),
                'email_verified_at' => $user->email_verified_at ?: now(),
            ]))->save();

            return $user;
        }

        $user = User::create([
            'name' => $profile['name'] ?? Str::before($email, '@'),
            'email' => mb_strtolower($email),
            'google_id' => $googleId,
            'avatar' => $profile['picture'] ?? null,
            'password' => bcrypt(Str::random(40)),   // random — they sign in with Google
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']));

        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user));
        } catch (\Throwable $e) {
            report($e);
        }

        return $user;
    }

    private function configured(): bool
    {
        return (bool) config('services.google.client_id') && (bool) config('services.google.client_secret');
    }

    private function redirectUri(): string
    {
        return (string) (config('services.google.redirect') ?: route('google.callback'));
    }
}
