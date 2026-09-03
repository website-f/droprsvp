<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\AccountPrivacyService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile — a PDPA erasure: block if they still have
     * obligations, otherwise anonymise their retained records and soft-delete.
     */
    public function destroy(ProfileDeleteRequest $request, AccountPrivacyService $privacy): RedirectResponse
    {
        $user = $request->user();

        $blockers = $privacy->deletionBlockers($user);
        if (! empty($blockers)) {
            return back()->withErrors(['password' => $blockers[0]]);
        }

        Auth::logout();

        $privacy->erase($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /** Download a JSON export of everything we hold about the user (PDPA access). */
    public function export(Request $request, AccountPrivacyService $privacy): StreamedResponse
    {
        $data = $privacy->export($request->user());
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, 'droprsvp-data-'.$request->user()->id.'.json', ['Content-Type' => 'application/json']);
    }
}
