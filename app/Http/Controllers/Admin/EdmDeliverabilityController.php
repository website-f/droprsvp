<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EdmCheck;
use App\Models\EmailBounce;
use App\Support\Dates;
use App\Support\Edm\Health\BlocklistCheck;
use App\Support\Edm\Health\DomainAuth;
use App\Support\Edm\Health\Readiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * EDM → Deliverability: will campaign mail reach inboxes? The setup checklist,
 * the sending domain's DNS, blocklists, and the bounce log in one place.
 */
class EdmDeliverabilityController extends Controller
{
    public function index(Request $request, DomainAuth $auth)
    {
        $type = in_array($request->query('type'), ['hard', 'soft', 'blocked', 'complaint'], true) ? $request->query('type') : '';
        $c = config('edm.bounces');

        return Inertia::render('admin/edm/deliverability', [
            'checklist' => Readiness::checklist(),
            // Live: three TXT lookups, quick enough to run on every visit, and
            // the page is where someone comes right after editing DNS.
            'dns' => $auth->check(),
            'domain' => DomainAuth::domain(),
            'blocklists' => EdmCheck::where('kind', 'blocklist')->orderBy('target')->orderBy('name')->get()
                ->map(fn (EdmCheck $r) => [
                    'target' => $r->target,
                    'list' => $r->name,
                    'label' => BlocklistCheck::IP_LISTS[$r->name] ?? BlocklistCheck::DOMAIN_LISTS[$r->name] ?? $r->name,
                    'status' => $r->status,
                    'detail' => $r->detail,
                    'checked' => Dates::display($r->checked_at, 'j M, g:ia'),
                    'since' => Dates::display($r->changed_at, 'j M Y'),
                ]),
            'sendingIps' => BlocklistCheck::sendingIps(),
            'bounceSetup' => [
                'configured' => ($c['enabled'] ?? false) && ! empty($c['host']) && ! empty($c['username']) && ! empty($c['password']),
                'host' => $c['host'] ?? null,
                'port' => $c['port'] ?? null,
                'username' => $c['username'] ?? null,
                'folder' => $c['folder'] ?? 'INBOX',
                'last' => Cache::get('edm.bounces.last'),
                'pipe' => 'php '.base_path('artisan').' edm:bounces --stdin',
            ],
            'bounceTotals' => EmailBounce::where('created_at', '>=', now()->subDays(30))
                ->selectRaw('type, COUNT(*) as c')->groupBy('type')->pluck('c', 'type'),
            'bounces' => EmailBounce::query()
                ->when($type !== '', fn ($q) => $q->where('type', $type))
                ->with('campaign:id,name')
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (EmailBounce $b) => [
                    'id' => $b->id,
                    'email' => $b->email,
                    'type' => $b->type,
                    'code' => $b->status_code,
                    'diagnostic' => $b->diagnostic,
                    'campaign' => $b->campaign ? ['id' => $b->campaign->id, 'name' => $b->campaign->name] : null,
                    'when' => Dates::display($b->created_at, 'j M, g:ia'),
                ]),
            'filters' => ['type' => $type],
        ]);
    }

    /** Re-run the DNS and blocklist checks now. */
    public function check(): RedirectResponse
    {
        Artisan::call('edm:health');

        $listed = EdmCheck::where('kind', 'blocklist')->where('status', 'listed')->count();

        return back()->with($listed ? 'flash_error' : 'flash_success', $listed
            ? "Checked. Listed on {$listed} blocklist(s) — see below."
            : 'Checked. Not listed on any blocklist we check.');
    }

    /** Read the bounce mailbox now rather than waiting for the next run. */
    public function readBounces(): RedirectResponse
    {
        $code = Artisan::call('edm:bounces');
        $last = Cache::get('edm.bounces.last');

        if ($code !== 0 || ($last['error'] ?? null)) {
            return back()->with('flash_error', 'Could not read the bounce mailbox: '.($last['error'] ?? trim(Artisan::output())));
        }

        return back()->with('flash_success', 'Read '.($last['fetched'] ?? 0).' message(s): '.($last['bounces'] ?? 0).' bounce(s), '.($last['suppressed'] ?? 0).' suppressed.');
    }
}
