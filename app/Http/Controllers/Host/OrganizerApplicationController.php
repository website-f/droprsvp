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
        if ($profile && $profile->status === 'pending') {
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
            ],
        ]);
    }

    public function submit(Request $request)
    {
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
            [...$data, 'status' => 'pending', 'submitted_at' => now(), 'review_reason' => null],
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
        ]);
    }
}
