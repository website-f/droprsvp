<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Mail\OrganizerApplicationReceivedMail;
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

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'poster' => ['nullable', 'string', 'max:2048'],
            'gallery' => ['nullable', 'array', 'max:8'],
            'gallery.*' => ['string', 'max:2048'],
        ]);

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

        return redirect()->route('host.pending')->with('success', 'Application submitted — we’ll be in touch by email or phone.');
    }

    public function pending(Request $request)
    {
        $profile = $request->user()->organizerProfile;

        if (! $profile || $profile->status !== 'pending') {
            return redirect()->route($profile?->status === 'approved' ? 'host.events.index' : 'host.apply');
        }

        return inertia('host/pending', [
            'submitted_at' => optional($profile->submitted_at)->format('j M Y'),
            // Drives whether the "Edit application" button shows or the locked notice.
            'editable' => $profile->isEditableByApplicant(),
        ]);
    }
}
