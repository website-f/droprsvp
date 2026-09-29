<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Profile photo, and for an organizer their company branding.
 *
 * The company logo used to be settable only on the host APPLICATION form, and
 * re-submitting that form resets the profile to `pending` and re-opens a review.
 * So an approved organizer had no way to change their logo that didn't put their
 * account back in the queue — which is why nobody could find where to upload one.
 *
 * This writes the same columns without touching `status`, `submitted_at` or any
 * other part of the review cycle. Branding is not an application.
 */
class BrandingController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $isOrganizer = $user->hasAnyRole(['organizer', 'superadmin']);

        $data = $request->validate([
            // Uploaded via the shared /uploads endpoint, so what arrives is a URL.
            'avatar' => ['nullable', 'string', 'max:2048'],
            'business_name' => [$isOrganizer ? 'nullable' : 'prohibited', 'string', 'max:120'],
            'poster' => [$isOrganizer ? 'nullable' : 'prohibited', 'string', 'max:2048'],
            'website' => [$isOrganizer ? 'nullable' : 'prohibited', 'url', 'max:2048'],
            'bio' => [$isOrganizer ? 'nullable' : 'prohibited', 'string', 'max:2000'],
        ]);

        // `nullable` allows a key to be absent entirely, not just empty, so every
        // read needs a default — a partial submit would otherwise 500.
        $user->forceFill(['avatar' => ($data['avatar'] ?? null) ?: null])->save();

        if ($isOrganizer) {
            // updateOrCreate so a superadmin (or an organizer who has not applied
            // yet) still gets a profile row to hang their branding on.
            $user->organizerProfile()->updateOrCreate(['user_id' => $user->id], [
                'business_name' => ($data['business_name'] ?? null) ?: null,
                'poster' => ($data['poster'] ?? null) ?: null,
                'website' => ($data['website'] ?? null) ?: null,
                'bio' => ($data['bio'] ?? null) ?: null,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branding updated.')]);

        return to_route('profile.edit');
    }
}
