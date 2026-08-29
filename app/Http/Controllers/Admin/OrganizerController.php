<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrganizerApplicationMail;
use App\Models\OrganizerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OrganizerController extends Controller
{
    /** Superadmin — organizer/vendor applications to review. */
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected'], true) ? $request->query('status') : 'pending';

        $applications = OrganizerProfile::whereNotNull('status')->with('user:id,name,email')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'pending' then 0 when 'rejected' then 1 else 2 end")
            ->orderByDesc('submitted_at')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (OrganizerProfile $p) => [
                'id' => $p->id,
                'name' => $p->user?->name,
                'email' => $p->user?->email,
                'business_name' => $p->business_name,
                'website' => $p->website,
                'phone' => $p->phone,
                'bio' => $p->bio,
                'poster' => $p->poster,
                'gallery' => $p->gallery ?? [],
                'status' => $p->status,
                'reason' => $p->review_reason,
                'submitted_at' => optional($p->submitted_at)->format('j M Y'),
            ]);

        return inertia('admin/organizers/index', [
            'applications' => $applications,
            'filters' => ['status' => $status],
            'counts' => [
                'pending' => OrganizerProfile::where('status', 'pending')->count(),
                'approved' => OrganizerProfile::where('status', 'approved')->count(),
                'rejected' => OrganizerProfile::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function approve(OrganizerProfile $organizer)
    {
        $organizer->update(['status' => 'approved', 'review_reason' => null, 'reviewed_at' => now()]);
        $this->notify($organizer, true);

        return back()->with('flash_success', "{$organizer->user?->name} approved.");
    }

    public function reject(Request $request, OrganizerProfile $organizer)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $organizer->update(['status' => 'rejected', 'review_reason' => $data['reason'], 'reviewed_at' => now()]);
        $this->notify($organizer, false);

        return back()->with('flash_success', "{$organizer->user?->name} rejected — they can re-apply.");
    }

    /** Email the applicant (non-fatal — works once SMTP is configured). */
    private function notify(OrganizerProfile $organizer, bool $approved): void
    {
        $email = $organizer->user?->email;
        if (! $email) {
            return;
        }
        try {
            Mail::to($email)->send(new OrganizerApplicationMail($organizer, $approved));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
