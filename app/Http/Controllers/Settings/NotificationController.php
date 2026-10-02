<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\EmailConsent;
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
            // Organizers they chose to hear from at checkout — each its own list.
            'organizerLists' => EmailConsent::query()
                ->where('email', Consent::normalise($request->user()->email))
                ->where('scope', 'like', 'organizer:%')
                ->where('status', 'subscribed')
                ->with('organizer.organizerProfile:id,user_id,business_name')
                ->get()
                ->filter(fn (EmailConsent $c) => $c->organizer)
                ->map(fn (EmailConsent $c) => [
                    'id' => $c->organizer_id,
                    'name' => $c->organizer->organizerProfile?->business_name ?: $c->organizer->name,
                    'since' => $c->consented_at?->format('j M Y'),
                ])
                ->values(),
        ]);
    }

    /** Stop emails from one organizer, leaving every other list as it is. */
    public function unsubscribeOrganizer(Request $request, User $organizer): RedirectResponse
    {
        Consent::revoke($request->user()->email, 'settings', $organizer->id, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'You will no longer get emails from '.($organizer->organizerProfile?->business_name ?: $organizer->name).'.']);

        return back();
    }

    /** Persist the user's opt-in/opt-out choices. */
    public function update(Request $request): RedirectResponse
    {
        $keys = array_keys(User::NOTIFICATION_CHANNELS);

        $data = $request->validate([
            // A channel left out (a form from before it existed) keeps its value.
            ...array_fill_keys($keys, ['sometimes', 'boolean']),
            'marketing_email' => ['sometimes', 'boolean'],
        ]);

        $current = $request->user()->notificationSettings();
        $prefs = [];
        foreach ($keys as $key) {
            $prefs[$key] = (bool) ($data[$key] ?? $current[$key]);
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
