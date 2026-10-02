<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EdmAccount;
use App\Models\EdmCreditEntry;
use App\Models\EdmSendingDomain;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Models\User;
use App\Services\Edm\Credits;
use App\Services\Edm\OrganizerGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * EDM → Organizers: every organizer's email usage, and the review screen for
 * the ones the guardrails suspended. Suspend, reinstate, grant credits, or set
 * a monthly allowance of their own.
 */
class EdmOrganizerController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('filter'), ['suspended', 'active'], true) ? $request->query('filter') : '';

        // Everyone who has used EDM: an account row, a campaign, a subscriber or credits.
        $ids = collect()
            ->merge(EdmAccount::pluck('organizer_id'))
            ->merge(EmailCampaign::whereNotNull('organizer_id')->distinct()->pluck('organizer_id'))
            ->merge(EmailConsent::whereNotNull('organizer_id')->distinct()->pluck('organizer_id'))
            ->merge(EdmCreditEntry::distinct()->pluck('organizer_id'))
            ->unique()->values();

        $accounts = EdmAccount::whereIn('organizer_id', $ids)->get()->keyBy('organizer_id');

        $users = User::with('organizerProfile:id,user_id,business_name')
            ->whereIn('id', $ids)
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('organizerProfile', fn ($p) => $p->where('business_name', 'like', "%{$q}%"))))
            ->get(['id', 'name', 'email', 'slug', 'premium_until']);

        $subscribers = EmailConsent::whereIn('organizer_id', $ids)->where('status', 'subscribed')
            ->selectRaw('organizer_id, COUNT(*) as c')->groupBy('organizer_id')->pluck('c', 'organizer_id');
        $campaigns = EmailCampaign::whereIn('organizer_id', $ids)
            ->selectRaw('organizer_id, COUNT(*) as c')->groupBy('organizer_id')->pluck('c', 'organizer_id');
        $credits = EdmCreditEntry::whereIn('organizer_id', $ids)->where('pool', 'credits')
            ->selectRaw('organizer_id, SUM(delta) as c')->groupBy('organizer_id')->pluck('c', 'organizer_id');
        $domains = EdmSendingDomain::whereIn('organizer_id', $ids)->where('status', 'verified')
            ->get(['organizer_id', 'domain'])->groupBy('organizer_id');

        $rows = $users->map(function (User $u) use ($accounts, $subscribers, $campaigns, $credits, $domains) {
            $account = $accounts->get($u->id);
            $rates = OrganizerGuard::rates($u->id);

            return [
                'id' => $u->id,
                'name' => $u->organizerProfile?->business_name ?: $u->name,
                'email' => $u->email,
                'premium' => $u->isPremium(),
                'status' => $account?->status ?? 'active',
                'reason' => $account?->suspended_reason,
                'suspended_at' => $account?->suspended_at?->diffForHumans(),
                'subscribers' => (int) ($subscribers[$u->id] ?? 0),
                'campaigns' => (int) ($campaigns[$u->id] ?? 0),
                'sent30' => $rates['sent'],
                'bounce_rate' => round(100 * $rates['bounce_rate'], 1),
                'unsub_rate' => round(100 * $rates['unsubscribe_rate'], 1),
                'complaints' => $rates['complaints'],
                'credits' => (int) ($credits[$u->id] ?? 0),
                'allowance' => Credits::allowance($u),
                'allowance_used' => Credits::allowanceUsed($u->id),
                'allowance_override' => $account?->monthly_allowance,
                'domains' => $domains->get($u->id)?->pluck('domain')->values() ?? [],
            ];
        })
            ->when($filter !== '', fn ($c) => $c->where('status', $filter === 'suspended' ? 'suspended' : 'active'))
            // Needs review first, then the busiest.
            ->sortBy([['status', 'desc'], ['sent30', 'desc']])
            ->values();

        return Inertia::render('admin/edm/organizers', [
            'organizers' => $rows,
            'filters' => ['q' => $q, 'filter' => $filter],
            'limits' => config('edm.organizers.guard'),
            'totals' => [
                'organizers' => $ids->count(),
                'suspended' => EdmAccount::where('status', 'suspended')->count(),
                'sent30' => DB::table('email_sends')->join('email_campaigns', 'email_campaigns.id', '=', 'email_sends.campaign_id')
                    ->whereNotNull('email_campaigns.organizer_id')->where('email_sends.sent_at', '>=', now()->subDays(30))->count(),
                'credits_sold' => (int) EdmCreditEntry::where('reason', 'purchase')->sum('delta'),
            ],
        ]);
    }

    public function suspend(Request $request, User $organizer): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        OrganizerGuard::suspend(EdmAccount::for($organizer->id), $data['reason'], $request->user()->id);

        return back()->with('flash_success', 'Email sending suspended. Their sending campaigns are paused.');
    }

    public function reinstate(Request $request, User $organizer): RedirectResponse
    {
        OrganizerGuard::reinstate(EdmAccount::for($organizer->id), $request->user()->id);

        return back()->with('flash_success', 'Reinstated. They can resume their campaigns; the guardrails give them a week before judging again.');
    }

    public function adjust(Request $request, User $organizer): RedirectResponse
    {
        $data = $request->validate([
            'credits' => ['nullable', 'integer', 'between:-1000000,1000000'],
            'note' => ['nullable', 'string', 'max:200'],
            'monthly_allowance' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reset_allowance' => ['boolean'],
        ]);

        if (! empty($data['credits'])) {
            Credits::grant($organizer->id, (int) $data['credits'], 'adjust', $data['note'] ?: 'Adjusted by '.$request->user()->name, by: $request->user()->id);
        }

        $account = EdmAccount::for($organizer->id);
        if (! empty($data['reset_allowance'])) {
            $account->update(['monthly_allowance' => null]);
        } elseif (array_key_exists('monthly_allowance', $data) && $data['monthly_allowance'] !== null) {
            $account->update(['monthly_allowance' => (int) $data['monthly_allowance']]);
        }

        return back()->with('flash_success', 'Saved.');
    }
}
