<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Mail\OrganizerApplicationReceivedMail;
use App\Support\Dates;
use App\Support\PlatformAlert;
use App\Support\WebsiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
                'website' => $profile?->website,
                'phone' => $profile?->phone,
                'bio' => $profile?->bio,
                'poster' => $profile?->poster,
                'gallery' => $profile?->gallery ?? [],
                'status' => $profile?->status,
                'reason' => $profile?->review_reason,
                // A pending-but-still-editable application is being resubmitted/edited.
                'editing' => (bool) ($profile && $profile->status === 'pending'),
            ],
        ]);
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

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:2048'],
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
                'Email' => $request->user()->email,
                'Phone' => $data['phone'] ?? null,
                'Website' => $data['website'] ?? null,
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
