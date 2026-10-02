<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\SocialLinks;
use App\Support\WebsiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        // Accept a website written the way people write one ("instagram.com/x",
        // "www.example.com"). The `url` rule below rejects both for want of a
        // scheme, which turned an OPTIONAL field into one that could not be
        // filled in without knowing to type "https://" first.
        //
        // Only when the key is actually present: forcing it in as null would
        // make the `url` rule run on a field that was never submitted (the
        // non-organizer branch has no `nullable` in front of it).
        if ($request->has('website')) {
            $request->merge(['website' => WebsiteUrl::normalise($request->input('website'))]);
        }

        $data = $request->validate([
            // Uploaded via the shared /uploads endpoint, so what arrives is a URL.
            'avatar' => ['nullable', 'string', 'max:2048'],
            'business_name' => [$isOrganizer ? 'nullable' : 'prohibited', 'string', 'max:120'],
            'poster' => [$isOrganizer ? 'nullable' : 'prohibited', 'string', 'max:2048'],
            'website' => [$isOrganizer ? 'nullable' : 'prohibited', 'url', 'max:2048'],
            'bio' => [$isOrganizer ? 'nullable' : 'prohibited', 'string', 'max:2000'],
            // { platform: link-or-handle }; cleaned and checked per platform below.
            'socials' => [$isOrganizer ? 'nullable' : 'prohibited', 'array'],
            'socials.*' => ['nullable', 'string', 'max:2048'],
        ]);

        [$socials, $socialErrors] = SocialLinks::clean((array) ($data['socials'] ?? []));

        if ($socialErrors) {
            throw ValidationException::withMessages(
                collect($socialErrors)->mapWithKeys(fn ($m, $p) => ["socials.{$p}" => $m])->all(),
            );
        }

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
                // Only touched when the form sent the field, so an older
                // client that does not know about socials cannot wipe them.
                ...(array_key_exists('socials', $data) ? ['socials' => $socials ?: null] : []),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branding updated.')]);

        return to_route('profile.edit');
    }
}
