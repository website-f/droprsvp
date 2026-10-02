<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailBounce;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Models\EmailSend;
use App\Models\EmailSuppression;
use App\Support\Analytics;
use App\Support\AnalyticsWindow;
use App\Support\Dates;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use App\Support\Edm\Health\Readiness;
use App\Support\Edm\Throttle;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * EDM → Overview: the list, the mail that went to it, and whether the setup is
 * healthy — one page to open first.
 */
class EdmDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $w = AnalyticsWindow::fromRequest($request);
        $platform = fn () => EmailConsent::where('scope', Consent::PLATFORM);

        $joined = $platform()->whereBetween('consented_at', [$w['from'], $w['to']])->where('status', 'subscribed')
            ->selectRaw('DATE(consented_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');
        $left = $platform()->whereBetween('unsubscribed_at', [$w['from'], $w['to']])
            ->selectRaw('DATE(unsubscribed_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');

        $platformSends = fn () => EmailSend::query()->whereHas('campaign', fn ($q) => $q->whereNull('organizer_id'));
        $sent = $platformSends()->whereBetween('sent_at', [$w['from'], $w['to']])
            ->selectRaw('DATE(sent_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');
        $bounced = EmailBounce::query()->whereBetween('created_at', [$w['from'], $w['to']])->where('type', '!=', 'complaint')
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');

        // Rates over sends in the window, from the sends themselves rather than
        // campaign counters, so a campaign straddling the window counts fairly.
        $windowSends = $platformSends()->whereBetween('sent_at', [$w['from'], $w['to']]);
        $sentCount = (clone $windowSends)->count();
        $opened = (clone $windowSends)->whereNotNull('opened_at')->count();
        $clicked = (clone $windowSends)->whereNotNull('clicked_at')->count();
        $bouncedCount = (clone $windowSends)->where('status', 'bounced')->count();
        $unsubs = (clone $windowSends)->whereNotNull('unsubscribed_at')->count();
        $rate = fn ($n) => $sentCount > 0 ? round(100 * $n / $sentCount, 1) : null;

        $checklist = Readiness::checklist();

        return Inertia::render('admin/edm/overview', [
            'kpis' => [
                'subscribers' => $platform()->where('status', 'subscribed')->count(),
                'joined' => (int) $joined->sum(),
                'left' => (int) $left->sum(),
                'suppressed' => EmailSuppression::count(),
                'sent' => $sentCount,
                'open_rate' => $rate($opened),
                'click_rate' => $rate($clicked),
                'bounce_rate' => $rate($bouncedCount),
                'unsub_rate' => $rate($unsubs),
                'never_asked' => Audience::repermissionCount(),
            ],
            'growth' => Analytics::bucketed($w, fn ($d) => [
                'joined' => (int) ($joined[$d->toDateString()] ?? 0),
                'left' => (int) ($left[$d->toDateString()] ?? 0),
            ], ['joined' => 0, 'left' => 0]),
            'volume' => Analytics::bucketed($w, fn ($d) => [
                'sent' => (int) ($sent[$d->toDateString()] ?? 0),
                'bounced' => (int) ($bounced[$d->toDateString()] ?? 0),
            ], ['sent' => 0, 'bounced' => 0]),
            'sources' => $platform()->where('status', 'subscribed')
                ->selectRaw('source, COUNT(*) as c')->groupBy('source')->pluck('c', 'source')
                ->map(fn ($c, $s) => ['name' => self::SOURCE_LABELS[$s] ?? ucfirst((string) $s), 'value' => (int) $c])
                ->sortByDesc('value')->values(),
            'campaigns' => EmailCampaign::whereNull('organizer_id')
                ->whereIn('status', ['sending', 'paused', 'scheduled', 'sent'])
                ->orderByRaw("CASE status WHEN 'sending' THEN 0 WHEN 'paused' THEN 1 WHEN 'scheduled' THEN 2 ELSE 3 END")
                ->latest('updated_at')->limit(6)->get()
                ->map(fn (EmailCampaign $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'status' => $c->status,
                    'recipients' => $c->recipients_count,
                    'sent' => $c->sent_count,
                    'open_rate' => $c->sent_count ? round(100 * $c->opened_count / $c->sent_count, 1) : null,
                    'click_rate' => $c->sent_count ? round(100 * $c->clicked_count / $c->sent_count, 1) : null,
                    'when' => Dates::display($c->status === 'scheduled' ? $c->scheduled_at : ($c->started_at ?? $c->updated_at), 'j M, g:ia'),
                ]),
            'throttle' => Throttle::status(),
            'health' => [
                'pass' => count(array_filter($checklist, fn ($i) => $i['status'] === 'pass')),
                'total' => count($checklist),
                'issues' => array_values(array_filter($checklist, fn ($i) => $i['status'] !== 'pass')),
            ],
            'filters' => ['period' => $w['period'], 'from' => $w['from_date'], 'to' => $w['to_date'], 'periodLabel' => $w['label']],
        ]);
    }

    public const SOURCE_LABELS = [
        'checkout' => 'At checkout',
        'register' => 'At sign-up',
        'settings' => 'In settings',
        'repermission' => 'Re-permission email',
        'admin' => 'Added by admin',
    ];
}
