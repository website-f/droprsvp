<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailConsent;
use App\Models\EmailSuppression;
use App\Support\Dates;
use App\Support\Edm\Consent;
use App\Support\Edm\Settings;
use App\Support\Edm\Throttle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Email marketing: who is on the list, and how mail goes out.
 *
 * There is deliberately no "add subscriber" or CSV import. Consent has to be
 * given by the person — at checkout, sign-up, settings, or the re-permission
 * email — and an imported list is exactly where spam complaints and dead
 * addresses come from, both of which land on the shared server's reputation.
 */
class EdmAudienceController extends Controller
{
    public function subscribers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['subscribed', 'unsubscribed'], true) ? $request->query('status') : 'subscribed';

        $rows = EmailConsent::query()
            ->where('scope', Consent::PLATFORM)
            ->where('status', $status)
            ->when($q !== '', fn ($x) => $x->where('email', 'like', '%'.strtolower($q).'%'))
            ->with('user:id,name')
            ->latest('updated_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (EmailConsent $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'name' => $c->user?->name,
                'source' => $c->source,
                'since' => Dates::display($status === 'subscribed' ? $c->consented_at : $c->unsubscribed_at, 'j M Y, g:ia'),
                'suppressed' => false,
            ]);

        // One lookup for the page, not one per row.
        $suppressed = EmailSuppression::whereIn('email', collect($rows->items())->pluck('email'))->pluck('email')->flip();
        $rows->through(fn (array $r) => [...$r, 'suppressed' => $suppressed->has($r['email'])]);

        $base = EmailConsent::where('scope', Consent::PLATFORM);

        return Inertia::render('admin/edm/subscribers', [
            'subscribers' => $rows,
            'filters' => ['q' => $q, 'status' => $status],
            'counts' => [
                'subscribed' => (clone $base)->where('status', 'subscribed')->count(),
                'unsubscribed' => (clone $base)->where('status', 'unsubscribed')->count(),
                'suppressed' => EmailSuppression::count(),
            ],
            'sources' => (clone $base)->where('status', 'subscribed')
                ->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source'),
        ]);
    }

    /** Take someone off the list at their request (an email, a phone call). */
    public function unsubscribe(Request $request, EmailConsent $consent): RedirectResponse
    {
        abort_unless($consent->scope === Consent::PLATFORM, 404);

        Consent::revoke($consent->email, 'admin', null, $request->ip());

        return back()->with('flash_success', "{$consent->email} is unsubscribed.");
    }

    /** Never mail this address again, from any list (a dead or hostile inbox). */
    public function suppress(Request $request, EmailConsent $consent): RedirectResponse
    {
        abort_unless($consent->scope === Consent::PLATFORM, 404);

        Consent::suppress($consent->email, 'manual', 'Suppressed by '.$request->user()->name);

        return back()->with('flash_success', "{$consent->email} will not be emailed again.");
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = EmailConsent::where('scope', Consent::PLATFORM)
            ->where('status', 'subscribed')
            ->with('user:id,name')
            ->orderBy('email')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Email', 'Name', 'Source', 'Consented at', 'IP']);
            foreach ($rows as $c) {
                fputcsv($out, [$c->email, $c->user?->name, $c->source, Dates::display($c->consented_at, 'Y-m-d H:i'), $c->ip]);
            }
            fclose($out);
        }, 'droprsvp-subscribers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function settings()
    {
        return Inertia::render('admin/edm/settings', [
            'settings' => Settings::all(),
            'throttle' => Throttle::status(),
            'fromAddress' => config('edm.from.address'),
            'mailer' => [
                'transport' => config('mail.mailers.'.config('edm.mailer', 'edm').'.transport'),
                'host' => config('mail.mailers.'.config('edm.mailer', 'edm').'.host'),
            ],
            'warmup' => config('edm.warmup'),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hourly_limit' => ['required', 'integer', 'min:1', 'max:100000'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:191'],
            'postal_address' => ['nullable', 'string', 'max:255'],
        ]);

        Settings::save($data);

        return back()->with('flash_success', 'Email marketing settings saved.');
    }
}
