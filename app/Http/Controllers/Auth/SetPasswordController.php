<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * First-login password setup for guest-created accounts. The buyer signs in with
 * the temporary password we emailed, then lands here to choose their own.
 */
class SetPasswordController extends Controller
{
    public function show(Request $request)
    {
        if (! $request->user()->must_set_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/set-password');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $user->password = Hash::make($data['password']);
        $user->must_set_password = false;
        $user->save();

        return redirect()->route('dashboard')->with('success', 'Password set — welcome to DropRSVP!');
    }
}
