<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Edm\Consent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /** Show the notification-preferences page. */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/notifications', [
            'channels' => User::NOTIFICATION_CHANNELS,
            'preferences' => $request->user()->notificationSettings(),
            // Marketing EMAIL is not one of the in-app channels above: it is
            // consent, recorded in email_consents with when and where it was
            // given, and it starts off rather than on.
            'marketingEmail' => Consent::isSubscribed($request->user()->email),
        ]);
    }

    /** Persist the user's opt-in/opt-out choices. */
    public function update(Request $request): RedirectResponse
    {
        $keys = array_keys(User::NOTIFICATION_CHANNELS);

        $data = $request->validate([
            ...array_fill_keys($keys, ['required', 'boolean']),
            'marketing_email' => ['sometimes', 'boolean'],
        ]);

        $prefs = [];
        foreach ($keys as $key) {
            $prefs[$key] = (bool) ($data[$key] ?? true);
        }

        $request->user()->forceFill(['notification_preferences' => $prefs])->save();

        // Only act on a CHANGE. Re-saving the page with the switch untouched must
        // not refresh consented_at, which is evidence of when they agreed.
        if (array_key_exists('marketing_email', $data)) {
            $user = $request->user();
            $wanted = (bool) $data['marketing_email'];

            if ($wanted !== Consent::isSubscribed($user->email)) {
                $wanted
                    ? Consent::grant($user->email, 'settings', $user, null, $request->ip())
                    : Consent::revoke($user->email, 'settings', null, $request->ip());
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences saved.')]);

        return back();
    }
}
