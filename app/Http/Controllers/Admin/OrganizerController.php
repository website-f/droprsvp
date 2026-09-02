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
        $status = in_array($request->query('status'), ['pending', 'appealed', 'approved', 'rejected', 'all'], true) ? $request->query('status') : 'pending';

        // An "appeal" is a rejected organizer who re-submitted: back to pending, but
        // with reviewed_at set from the earlier rejection. First-time pending has none.
        $applications = OrganizerProfile::whereNotNull('status')->with('user:id,name,email')
            ->when($status === 'pending', fn ($q) => $q->where('status', 'pending')->whereNull('reviewed_at'))
            ->when($status === 'appealed', fn ($q) => $q->where('status', 'pending')->whereNotNull('reviewed_at'))
            ->when(in_array($status, ['approved', 'rejected'], true), fn ($q) => $q->where('status', $status))
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
                'is_appeal' => $p->status === 'pending' && $p->reviewed_at !== null,
                'reason' => $p->review_reason,
                'submitted_at' => optional($p->submitted_at)->format('j M Y'),
            ]);

        return inertia('admin/organizers/index', [
            'applications' => $applications,
            'filters' => ['status' => $status],
            'counts' => [
                'pending' => OrganizerProfile::where('status', 'pending')->whereNull('reviewed_at')->count(),
                'appealed' => OrganizerProfile::where('status', 'pending')->whereNotNull('reviewed_at')->count(),
                'approved' => OrganizerProfile::where('status', 'approved')->count(),
                'rejected' => OrganizerProfile::where('status', 'rejected')->count(),
            ],
        ]);
    }

    /** Full application detail — reviewed before approving/rejecting. */
    public function show(OrganizerProfile $organizer)
    {
        $organizer->load('user:id,name,email,created_at');

        return inertia('admin/organizers/show', [
            'application' => [
                'id' => $organizer->id,
                'name' => $organizer->user?->name,
                'email' => $organizer->user?->email,
                'member_since' => optional($organizer->user?->created_at)->format('j M Y'),
                'business_name' => $organizer->business_name,
                'website' => $organizer->website,
                'phone' => $organizer->phone,
                'bio' => $organizer->bio,
                'poster' => $organizer->poster,
                'gallery' => $organizer->gallery ?? [],
                'status' => $organizer->status,
                'reason' => $organizer->review_reason,
                'submitted_at' => optional($organizer->submitted_at)->format('j M Y, g:i A'),
                'reviewed_at' => optional($organizer->reviewed_at)->format('j M Y, g:i A'),
            ],
        ]);
    }

    public function approve(OrganizerProfile $organizer)
    {
        $organizer->update(['status' => 'approved', 'review_reason' => null, 'reviewed_at' => now()]);
        $this->notify($organizer, true);

        return redirect()->route('admin.organizers.index')->with('flash_success', "{$organizer->user?->name} approved.");
    }

    public function reject(Request $request, OrganizerProfile $organizer)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $organizer->update(['status' => 'rejected', 'review_reason' => $data['reason'], 'reviewed_at' => now()]);
        $this->notify($organizer, false);

        return redirect()->route('admin.organizers.index')->with('flash_success', "{$organizer->user?->name} rejected — they can re-apply.");
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
