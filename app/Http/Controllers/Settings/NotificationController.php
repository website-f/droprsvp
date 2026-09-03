<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        ]);
    }

    /** Persist the user's opt-in/opt-out choices. */
    public function update(Request $request): RedirectResponse
    {
        $keys = array_keys(User::NOTIFICATION_CHANNELS);

        $data = $request->validate(
            array_fill_keys($keys, ['required', 'boolean'])
        );

        $prefs = [];
        foreach ($keys as $key) {
            $prefs[$key] = (bool) ($data[$key] ?? true);
        }

        $request->user()->forceFill(['notification_preferences' => $prefs])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences saved.')]);

        return back();
    }
}
