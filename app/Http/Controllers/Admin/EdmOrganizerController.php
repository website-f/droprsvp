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
use App\Support\Edm\Audience;
use App\Support\Edm\OrganizerRules;
use App\Support\Edm\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            // A search reaches every organizer, so one who has never used EDM
            // can be switched on (invitation mode) or given their own rules.
            ->when($q !== '', fn ($c) => $c->merge(User::role('organizer')
                ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                    ->orWhereHas('organizerProfile', fn ($p) => $p->where('business_name', 'like', "%{$q}%")))
                ->limit(50)->pluck('id')))
            ->unique()->values();

        $accounts = EdmAccount::whereIn('organizer_id', $ids)->get()->keyBy('organizer_id');

        $users = User::with('organizerProfile:id,user_id,business_name')
            ->whereIn('id', $ids)
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('organizerProfile', fn ($p) => $p->where('business_name', 'like', "%{$q}%"))))
            ->get(['id', 'name', 'email', 'slug', 'premium_until']);

        $subscribers = EmailConsent::whereIn('organizer_id', $ids)->where('status', 'subscribed')
            ->selectRaw('organizer_id, COUNT(*) as c')->groupBy('organizer_id')->pluck('c', 'organizer_id');
        $campaigns = EmailCampaign::whereIn('organizer_id', $ids)->where('kind', '!=', 'automation')
            ->selectRaw('organizer_id, COUNT(*) as c')->groupBy('organizer_id')->pluck('c', 'organizer_id');
        $credits = EdmCreditEntry::whereIn('organizer_id', $ids)->where('pool', 'credits')
            ->selectRaw('organizer_id, SUM(delta) as c')->groupBy('organizer_id')->pluck('c', 'organizer_id');
        $domains = EdmSendingDomain::whereIn('organizer_id', $ids)->where('status', 'verified')
            ->get(['organizer_id', 'domain'])->groupBy('organizer_id');

        $rows = $users->map(function (User $u) use ($accounts, $subscribers, $campaigns, $credits, $domains) {
            $account = $accounts->get($u->id);
            $rates = OrganizerGuard::rates($u->id);
            $rules = OrganizerRules::for($u->id);
            $access = OrganizerRules::access($u);

            return [
                'access' => $account?->access ?? 'inherit',
                'allowed' => $access['allowed'],
                'blocked_reason' => $access['allowed'] ? null : $access['reason'],
                'overrides' => OrganizerRules::overrides($u->id),
                'rules' => array_intersect_key($rules, OrganizerRules::LIMITS + OrganizerRules::FEATURES),
                'usage' => OrganizerRules::usage($u->id, $rules),
                'reachable' => Audience::reachable($u->id),
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

        $global = OrganizerRules::global();

        return Inertia::render('admin/edm/organizers', [
            'organizers' => $rows,
            'global' => [
                'enabled' => $global['enabled'],
                'access' => $global['access'],
                ...array_intersect_key($global, OrganizerRules::LIMITS + OrganizerRules::FEATURES),
            ],
            'filters' => ['q' => $q, 'filter' => $filter],
            'limits' => OrganizerRules::global()['guard'],
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

    /** One organizer's access, and the limits and switches where they differ from everyone's. */
    public function rules(Request $request, User $organizer): RedirectResponse
    {
        $limits = [];
        foreach (OrganizerRules::LIMITS as $key => [, $max]) {
            $limits["overrides.{$key}"] = ['nullable', 'integer', 'min:0', "max:{$max}"];
        }
        foreach (array_keys(OrganizerRules::FEATURES) as $key) {
            $limits["overrides.{$key}"] = ['nullable', 'boolean'];
        }

        $data = $request->validate([
            'access' => ['required', 'in:inherit,enabled,disabled'],
            'overrides' => ['array'],
            ...$limits,
        ]);

        $overrides = (array) ($data['overrides'] ?? []);
        foreach (array_keys(OrganizerRules::FEATURES) as $key) {
            // A missing / null switch follows the global rule; booleans arrive as 0/1 from forms.
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $overrides[$key] = (bool) $overrides[$key];
            }
        }

        OrganizerRules::saveFor($organizer->id, $data['access'], $overrides);

        return back()->with('flash_success', 'Rules saved for '.($organizer->organizerProfile?->business_name ?: $organizer->name).'.');
    }

    /** EDM → Organizer rules: what every organizer sends under. */
    public function globalRules()
    {
        $rules = OrganizerRules::global();
        $usingIt = EmailCampaign::whereNotNull('organizer_id')->where('started_at', '>=', now()->subDays(30))->distinct()->count('organizer_id');

        return Inertia::render('admin/edm/organizer-rules', [
            'rules' => $rules,
            'stats' => [
                'organizers' => User::role('organizer')->count(),
                'sending30' => $usingIt,
                'custom' => EdmAccount::where(fn ($q) => $q->whereNotNull('rules')->orWhere('access', '!=', 'inherit'))->count(),
                'platform_hourly' => (int) Settings::get('hourly_limit'),
            ],
        ]);
    }

    public function saveGlobalRules(Request $request): RedirectResponse
    {
        $rules = [
            'enabled' => ['required', 'boolean'],
            'access' => ['required', 'in:'.implode(',', OrganizerRules::ACCESS_MODES)],
            'premium_allowance' => ['required', 'integer', 'min:0', 'max:10000000'],
            'free_allowance' => ['required', 'integer', 'min:0', 'max:10000000'],
            'packs' => ['array', 'max:6'],
            'packs.*.key' => ['nullable', 'string', 'max:40'],
            'packs.*.name' => ['required', 'string', 'max:40'],
            'packs.*.credits' => ['required', 'integer', 'min:1', 'max:10000000'],
            'packs.*.price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'guard.min_sent' => ['required', 'integer', 'min:1', 'max:1000000'],
            'guard.bounce_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'guard.unsubscribe_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'guard.complaint_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
        foreach (OrganizerRules::LIMITS as $key => [, $max]) {
            $rules[$key] = ['required', 'integer', 'min:0', "max:{$max}"];
        }
        foreach (array_keys(OrganizerRules::FEATURES) as $key) {
            $rules[$key] = ['required', 'boolean'];
        }

        $data = $request->validate($rules);

        // Rates are typed as percentages; stored as fractions.
        $data['guard'] = [
            'min_sent' => (int) $data['guard']['min_sent'],
            'bounce_rate' => (float) $data['guard']['bounce_rate'] / 100,
            'unsubscribe_rate' => (float) $data['guard']['unsubscribe_rate'] / 100,
            'complaint_rate' => (float) $data['guard']['complaint_rate'] / 100,
        ];

        // Pack keys are what a purchase records, so a renamed pack keeps its key.
        $data['packs'] = collect($data['packs'] ?? [])->values()->map(fn ($p, $i) => [
            'key' => Str::slug($p['key'] ?? '') ?: 'pack-'.($i + 1),
            'name' => $p['name'],
            'credits' => (int) $p['credits'],
            'price' => round((float) $p['price'], 2),
        ])->unique('key')->values()->all();

        foreach (array_keys(OrganizerRules::FEATURES) as $key) {
            $data[$key] = (bool) $data[$key];
        }
        $data['enabled'] = (bool) $data['enabled'];

        OrganizerRules::saveGlobal($data);

        return back()->with('flash_success', 'Organizer email rules saved. They apply from the next minute’s sending.');
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
            Credits::grant($organizer->id, (int) $data['credits'], 'adjust', ($data['note'] ?? null) ?: 'Adjusted by '.$request->user()->name, by: $request->user()->id);
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
