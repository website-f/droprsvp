<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Mail\OrganizerApplicationReceivedMail;
use App\Support\Dates;
use App\Support\PlatformAlert;
use App\Support\SocialLinks;
use App\Support\WebsiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The vendor/organizer application. New organizers submit their business details
 * (website, poster, gallery…) and wait for the superadmin to approve. Rejected
 * applicants can re-submit to appeal.
 */
class OrganizerApplicationController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $profile = $user->organizerProfile;

        if ($profile && $profile->status === 'approved') {
            return redirect()->route('host.events.index');
        }
        // A pending application is only editable until the superadmin opens it for
        // review. Once locked, bounce back to the read-only pending screen.
        if ($profile && $profile->status === 'pending' && ! $profile->isEditableByApplicant()) {
            return redirect()->route('host.pending');
        }

        return inertia('host/apply', [
            'application' => [
                'business_name' => $profile?->business_name ?: $user->name,
                // Prefilled from the account, which is the address we already
                // reach them at — they can change it to a business one.
                'email' => $profile?->email ?: $user->email,
                'links' => $this->linksFor($profile),
                'phone' => $profile?->phone,
                'bio' => $profile?->bio,
                'poster' => $profile?->poster,
                'gallery' => $profile?->gallery ?? [],
                'status' => $profile?->status,
                'reason' => $profile?->review_reason,
                // A pending-but-still-editable application is being resubmitted/edited.
                'editing' => (bool) ($profile && $profile->status === 'pending'),
            ],
            'platforms' => collect(SocialLinks::PLATFORMS)
                ->map(fn ($p, $value) => ['value' => $value, 'label' => $p[0], 'hosts' => $p[1]])
                ->values(),
        ]);
    }

    /** The profile's website and socials as the form's rows, website first. */
    private function linksFor($profile): array
    {
        $rows = $profile?->website ? [['type' => 'website', 'value' => $profile->website]] : [];

        foreach ((array) ($profile?->socials ?? []) as $platform => $url) {
            $rows[] = ['type' => $platform, 'value' => $url];
        }

        return $rows;
    }

    /**
     * The "Links" rows — a website and any social profiles, as many as they
     * have — into the profile's website and socials, which the public
     * organizer page and Branding settings already show and edit.
     *
     * A link pasted with the wrong type is filed under the right one (an
     * Instagram URL left on "Website" is their Instagram). Each platform holds
     * one link, so the first of a kind is kept; a second website, which has
     * nowhere else to go, is an error rather than silently dropped.
     *
     * @return array{website: ?string, socials: array<string,string>}
     */
    private function cleanLinks(mixed $rows): array
    {
        $website = null;
        $socials = [];
        $errors = [];

        foreach (array_values(is_array($rows) ? $rows : []) as $i => $row) {
            $type = is_array($row) ? (string) ($row['type'] ?? 'website') : 'website';
            $value = is_array($row) && is_string($row['value'] ?? null) ? trim($row['value']) : '';

            if ($value === '') {
                continue;
            }

            if ($type === 'website') {
                $type = SocialLinks::detect($value) ?? 'website';
            }

            if ($type === 'website') {
                $url = WebsiteUrl::normalise($value);

                if (! $url) {
                    $errors["links.$i.value"] = 'That does not look like a web address.';
                } elseif ($website !== null && $website !== $url) {
                    $errors["links.$i.value"] = 'Only one website can be added. Use the other rows for social profiles.';
                } else {
                    $website = mb_substr($url, 0, 2048);
                }

                continue;
            }

            $url = SocialLinks::normalise($type, $value);

            if ($url === false) {
                $errors["links.$i.value"] = 'That does not look like a '.SocialLinks::label($type).' link.';
            } elseif ($url !== null && ! isset($socials[$type])) {
                $socials[$type] = mb_substr($url, 0, 2048);
            } elseif ($url !== null && $socials[$type] !== $url) {
                $errors["links.$i.value"] = SocialLinks::label($type).' is already added above.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['website' => $website, 'socials' => $socials];
    }

    public function submit(Request $request)
    {
        // Block edits once a pending application has been opened for review.
        $existing = $request->user()->organizerProfile;
        if ($existing && $existing->status === 'pending' && ! $existing->isEditableByApplicant()) {
            return redirect()->route('host.pending')->with('warning', 'Your application is being reviewed and can no longer be edited.');
        }

        // Same normalisation as the branding form: the website is optional, and
        // "instagram.com/x" should be accepted rather than rejected for want of
        // a scheme.
        //
        // Only when the key is actually present: forcing it in as null would
        // make the `url` rule run on a field that was never submitted (the
        // non-organizer branch has no `nullable` in front of it).
        if ($request->has('website')) {
            $request->merge(['website' => WebsiteUrl::normalise($request->input('website'))]);
        }

        // The "Links" list replaces the single website box; an older form
        // still posting `website` keeps working.
        $links = $request->has('links') ? $this->cleanLinks($request->input('links')) : null;

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            // Phone AND email are both required. An application used to be
            // submittable with neither a reachable address nor a usable
            // number — the reviewer then had to guess, or fall back to the
            // sign-in address, which for a business is usually somebody's
            // personal inbox.
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['required', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:2048'],
            'links' => ['nullable', 'array', 'max:12'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'poster' => ['nullable', 'string', 'max:2048'],
            'gallery' => ['nullable', 'array', 'max:8'],
            'gallery.*' => ['string', 'max:2048'],
        ]);

        // The phone belongs on the ACCOUNT too, not only on the application.
        //
        // These were two separate stores that never spoke: an organizer typed
        // their number into the application, and their user profile — which is
        // what the admin user page, the CSV export and the admin search all
        // read — stayed empty. So the admin saw "Phone —" for someone who had
        // plainly given one.
        //
        // The application does not overwrite a number they have already set on
        // their account; it only fills a gap.
        if (! $request->user()->phone && ! empty($data['phone'])) {
            $request->user()->forceFill(['phone' => $data['phone']])->save();
        }

        unset($data['links']);

        if ($links !== null) {
            $data['website'] = $links['website'];
            $data['socials'] = $links['socials'] ?: null;
        }

        $profile = $request->user()->organizerProfile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            // A fresh submission/appeal starts a new review cycle → unlock edits again
            // until the superadmin re-opens it.
            [...$data, 'status' => 'pending', 'submitted_at' => now(), 'review_reason' => null, 'review_opened_at' => null],
        );

        // Confirm receipt by email (non-fatal).
        try {
            Mail::to($request->user()->email)->send(new OrganizerApplicationReceivedMail($profile->load('user')));
        } catch (\Throwable $e) {
            report($e);
        }

        // An application sits waiting for a superadmin, so it is the one that
        // most needs surfacing — it used to email only the applicant.
        PlatformAlert::raise(
            type: 'organizer',
            title: 'New organizer application',
            body: $data['business_name'].' applied to host events.',
            url: '/admin/organizers/'.$profile->id,
            details: array_filter([
                'Business' => $data['business_name'],
                'Applicant' => $request->user()->name,
                'Email' => $data['email'] ?? $request->user()->email,
                'Account email' => $request->user()->email,
                'Phone' => $data['phone'] ?? null,
                'Website' => $data['website'] ?? null,
                ...collect($data['socials'] ?? [])->mapWithKeys(fn ($url, $platform) => [SocialLinks::label($platform) => $url])->all(),
            ]),
            level: 'warning', // it is blocking someone until it is reviewed
        );

        return redirect()->route('host.pending')->with('success', 'Application submitted — we’ll be in touch by email or phone.');
    }

    public function pending(Request $request)
    {
        $profile = $request->user()->organizerProfile;

        if (! $profile || $profile->status !== 'pending') {
            return redirect()->route($profile?->status === 'approved' ? 'host.events.index' : 'host.apply');
        }

        return inertia('host/pending', [
            'submitted_at' => Dates::display($profile->submitted_at, 'j M Y'),
            // Drives whether the "Edit application" button shows or the locked notice.
            'editable' => $profile->isEditableByApplicant(),
        ]);
    }
}
