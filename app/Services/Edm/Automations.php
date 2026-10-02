<?php

namespace App\Services\Edm;

use App\Models\EdmAutomation;
use App\Models\EdmAutomationStep;
use App\Models\EdmEnrollment;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Models\EmailSend;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Support\CheckoutFunnel;
use App\Support\Dates;
use App\Support\Edm\Consent;
use App\Support\Edm\Renderer;
use App\Support\SeoTemplate;
use App\Support\Url;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Marketing automation: sequences that send themselves.
 *
 * Every five minutes `edm:automations`:
 *
 *   1. ENROLS people for each active sequence's trigger —
 *        event_reminder      ticket holders of an upcoming event (anchor: start)
 *        post_event          ticket holders of an event that just ended (anchor: end)
 *        abandoned_checkout  people who filled in checkout but never paid (anchor: when)
 *        welcome             people who just subscribed to the list (anchor: when)
 *      once per person per reason (unique enrollment), so a sequence never
 *      repeats itself for the same event, order or subscription;
 *
 *   2. ADVANCES everyone whose next email is due: checks they still may be
 *      mailed and still qualify (not since unsubscribed, not since bought the
 *      tickets they abandoned…), evaluates the step's conditions, and queues
 *      the email as an ordinary send on the step's own hidden campaign — so
 *      the throttle, delivery, tracking and statistics are the campaign
 *      machinery's, unchanged.
 *
 * A step whose moment has already passed when someone is enrolled (bought
 * tickets two days before the event: the "3 days before" reminder) is skipped,
 * never sent late.
 *
 * When an organizer runs their own sequence for a trigger, DropRSVP's
 * platform-wide one leaves their events alone, so nobody gets two reminders.
 */
class Automations
{
    /** A step more than this late is skipped rather than sent. */
    private const GRACE_MINUTES = 120;

    public const TRIGGER_LABELS = [
        'event_reminder' => 'Event reminders',
        'post_event' => 'After the event',
        'abandoned_checkout' => 'Abandoned checkout',
        'welcome' => 'Welcome new subscribers',
    ];

    /** Tokens a step may use, with a sample value for previews. */
    public const TOKENS = [
        'event_name' => 'Rooftop Jazz Night',
        'event_date' => 'Sat, 10 Oct 2026',
        'event_time' => '8pm',
        'event_venue' => 'The Roof, Kuala Lumpur',
        'event_url' => '#',
        'review_url' => '#',
        'organizer_name' => 'BoardLah Entertainment',
        'next_events_url' => '#',
    ];

    public const CONDITIONS = [
        'subscribed' => 'Is subscribed to your list (needed for anything promotional)',
        'not_purchased' => 'Has not bought tickets since',
        'checked_in' => 'Checked in at the event',
        'not_checked_in' => 'Did not check in',
        'opened_previous' => 'Opened the previous email',
        'not_opened_previous' => 'Did not open the previous email',
        'clicked_previous' => 'Clicked in the previous email',
    ];

    // ---- recipes -------------------------------------------------------------

    /**
     * A new sequence, ready to switch on: the recipe's steps, timings and
     * written emails for its trigger.
     */
    public static function create(string $trigger, ?int $organizerId, ?int $by, ?string $name = null): EdmAutomation
    {
        return DB::transaction(function () use ($trigger, $organizerId, $by, $name) {
            $automation = EdmAutomation::create([
                'organizer_id' => $organizerId,
                'trigger' => $trigger,
                'name' => $name ?: self::TRIGGER_LABELS[$trigger],
                'settings' => ['event_ids' => []],
                'created_by' => $by,
            ]);

            foreach (self::recipe($trigger) as $i => $step) {
                self::addStep($automation, $step + ['position' => $i]);
            }

            return $automation->load('steps.campaign');
        });
    }

    /** @return list<array{delay_value: int, delay_unit: string, delay_direction: string, conditions: list<string>, marketing: bool, subject: string, preheader: string, heading: string, body: string, button: ?array{0: string, 1: string}}> */
    public static function recipe(string $trigger): array
    {
        $step = fn (int $value, string $unit, string $dir, string $subject, string $preheader, string $heading, string $body, ?array $button = null, array $conditions = [], bool $marketing = false) => [
            'delay_value' => $value, 'delay_unit' => $unit, 'delay_direction' => $dir, 'conditions' => $conditions, 'marketing' => $marketing,
            'subject' => $subject, 'preheader' => $preheader, 'heading' => $heading, 'body' => $body, 'button' => $button,
        ];

        return match ($trigger) {
            'event_reminder' => [
                $step(3, 'days', 'before', '{{event_name}} is in 3 days', 'Here is everything you need for the day.', 'See you in 3 days',
                    '<p>Hi {{first_name}},</p><p><strong>{{event_name}}</strong> is coming up on <strong>{{event_date}}</strong> at {{event_time}}, at {{event_venue}}.</p><p>Your tickets are in your account and in the confirmation email. Show the QR code at the door.</p>',
                    ['Event details', '{{event_url}}']),
                $step(1, 'days', 'before', 'Tomorrow: {{event_name}}', 'Doors, venue and your tickets.', 'See you tomorrow',
                    '<p>Hi {{first_name}},</p><p>Just a reminder that <strong>{{event_name}}</strong> is tomorrow, {{event_date}} at {{event_time}}.</p><p>Venue: {{event_venue}}. Have your QR code ready — it makes the door quick for everyone.</p>',
                    ['Event details', '{{event_url}}']),
            ],
            'post_event' => [
                $step(1, 'days', 'after', 'Thanks for coming to {{event_name}}', 'Tell us how it went?', 'Thanks for coming!',
                    '<p>Hi {{first_name}},</p><p>Thank you for joining <strong>{{event_name}}</strong>. We hope you had a great time.</p><p>A quick review helps others find good events — and helps {{organizer_name}} make the next one better.</p>',
                    ['Leave a review', '{{review_url}}'], ['checked_in']),
                $step(7, 'days', 'after', 'What’s on next from {{organizer_name}}', 'New dates you might like.', 'Coming up next',
                    '<p>Hi {{first_name}},</p><p>Since you came to {{event_name}}, here is what {{organizer_name}} has coming up next.</p>',
                    ['See upcoming events', '{{next_events_url}}'], ['subscribed'], true),
            ],
            'abandoned_checkout' => [
                $step(1, 'hours', 'after', 'You left your tickets for {{event_name}}', 'They are not reserved for long.', 'Still want to come?',
                    '<p>Hi {{first_name}},</p><p>You started booking <strong>{{event_name}}</strong> ({{event_date}}) but did not finish. If something went wrong at payment, you can pick up where you left off.</p>',
                    ['Get my tickets', '{{event_url}}'], ['not_purchased']),
                $step(1, 'days', 'after', 'Still thinking about {{event_name}}?', 'Tickets are still available — for now.', 'Last call',
                    '<p>Hi {{first_name}},</p><p>Tickets for <strong>{{event_name}}</strong> on {{event_date}} are still available. We would love to see you there.</p><p>If you have a question, just reply to this email.</p>',
                    ['Book now', '{{event_url}}'], ['not_purchased']),
            ],
            'welcome' => [
                $step(0, 'minutes', 'after', 'You’re on the list, {{first_name}}', 'Here is what to expect from us.', 'Welcome!',
                    '<p>Hi {{first_name}},</p><p>Thanks for signing up. We will email you about events worth your time — not every day, and you can unsubscribe at the bottom of any email.</p>',
                    ['Browse events', '{{next_events_url}}'], [], true),
                $step(3, 'days', 'after', 'A few picks to start with', 'Events happening soon.', 'Happening soon',
                    '<p>Hi {{first_name}},</p><p>Here are a few things happening soon that people are booking.</p>',
                    ['See what’s on', '{{next_events_url}}'], ['subscribed'], true),
            ],
            default => [],
        };
    }

    /** Add a step (and its hidden campaign) to a sequence. */
    public static function addStep(EdmAutomation $automation, array $s): EdmAutomationStep
    {
        $design = $s['design'] ?? self::design($s['heading'] ?? 'Hello', $s['body'] ?? '<p>Hi {{first_name}},</p>', $s['button'] ?? null);

        $campaign = EmailCampaign::create([
            'organizer_id' => $automation->organizer_id,
            'kind' => 'automation',
            'name' => mb_substr($automation->name.' · step '.(($s['position'] ?? 0) + 1), 0, 160),
            'subject' => (string) ($s['subject'] ?? ''),
            'preheader' => $s['preheader'] ?? null,
            'design' => $design,
            'audience' => ['marketing' => (bool) ($s['marketing'] ?? false)],
            'status' => $automation->isActive() ? 'sending' : 'paused',
            'started_at' => now(),
        ]);
        self::freeze($campaign);

        return EdmAutomationStep::create([
            'automation_id' => $automation->id,
            'campaign_id' => $campaign->id,
            'position' => (int) ($s['position'] ?? $automation->steps()->count()),
            'delay_value' => (int) ($s['delay_value'] ?? 1),
            'delay_unit' => $s['delay_unit'] ?? 'days',
            'delay_direction' => $s['delay_direction'] ?? 'after',
            'conditions' => array_values(array_intersect((array) ($s['conditions'] ?? []), array_keys(self::CONDITIONS))),
        ]);
    }

    public static function design(string $heading, string $body, ?array $button): array
    {
        $content = [
            ['type' => 'Heading', 'props' => ['id' => 'Heading-1', 'text' => $heading, 'level' => 'h1', 'align' => 'left']],
            ['type' => 'Text', 'props' => ['id' => 'Text-1', 'align' => 'left', 'html' => $body]],
        ];

        if ($button) {
            $content[] = ['type' => 'Button', 'props' => ['id' => 'Button-1', 'label' => $button[0], 'url' => $button[1], 'align' => 'center', 'color' => '']];
        }

        return ['root' => ['props' => ['brandColor' => Renderer::DEFAULT_BRAND, 'backgroundColor' => '#f3f4f6', 'showLogo' => true]], 'content' => $content];
    }

    /** Render a step's design into its campaign, registering tracked links. */
    public static function freeze(EmailCampaign $campaign): void
    {
        CampaignSender::freezeContent($campaign);
    }

    /** Re-order steps by when they fire, so position always matches time. */
    public static function reorder(EdmAutomation $automation): void
    {
        $automation->load('steps');

        $automation->steps
            ->sortBy(fn (EdmAutomationStep $s) => [$s->offsetMinutes(), $s->id])
            ->values()
            ->each(fn (EdmAutomationStep $s, int $i) => $s->position === $i ?: $s->forceFill(['position' => $i])->save());
    }

    // ---- on / off --------------------------------------------------------------

    public static function activate(EdmAutomation $automation): void
    {
        self::reorder($automation);
        $automation->forceFill(['status' => 'active', 'activated_at' => $automation->activated_at ?? now()])->save();

        foreach ($automation->steps()->with('campaign')->get() as $step) {
            self::freeze($step->campaign);
            $step->campaign->forceFill(['status' => 'sending', 'paused_reason' => null])->save();
        }
    }

    public static function pause(EdmAutomation $automation): void
    {
        $automation->forceFill(['status' => 'paused'])->save();

        EmailCampaign::whereIn('id', $automation->steps()->pluck('campaign_id'))
            ->update(['status' => 'paused', 'paused_reason' => 'Automation paused.']);
    }

    /** Pressed "unsubscribe" on an automated email: stop that workspace's sequences for them. */
    public static function optOut(string $email, ?int $organizerId, ?string $ip = null): void
    {
        Consent::revokeAutomations($email, $organizerId, $ip);

        EdmEnrollment::where('email', Consent::normalise($email))
            ->where('status', 'active')
            ->whereIn('automation_id', EdmAutomation::where('organizer_id', $organizerId)->select('id'))
            ->update(['status' => 'exited', 'exit_reason' => 'Unsubscribed', 'completed_at' => now()]);
    }

    // ---- the run -----------------------------------------------------------------

    /** @return array{enrolled: int, queued: int, skipped: int, exited: int} */
    public static function run(): array
    {
        $summary = ['enrolled' => 0, 'queued' => 0, 'skipped' => 0, 'exited' => 0];

        foreach (EdmAutomation::where('status', 'active')->with('steps')->get() as $automation) {
            if ($automation->steps->isEmpty()) {
                continue;
            }

            $summary['enrolled'] += match ($automation->trigger) {
                'event_reminder' => self::enrollEventAttendees($automation, upcoming: true),
                'post_event' => self::enrollEventAttendees($automation, upcoming: false),
                'abandoned_checkout' => self::enrollAbandoned($automation),
                'welcome' => self::enrollSubscribers($automation),
                default => 0,
            };
        }

        foreach (self::process() as $k => $v) {
            $summary[$k] += $v;
        }

        return $summary;
    }

    /** Ticket holders of events starting soon (reminders) or just ended (follow-up). */
    private static function enrollEventAttendees(EdmAutomation $a, bool $upcoming): int
    {
        if ($upcoming) {
            // Far enough ahead to catch the earliest "before" step.
            $lead = max(0, -$a->steps->min(fn ($s) => $s->offsetMinutes())) + 60;
            $events = Event::where('status', 'published')
                ->whereBetween('starts_at', [now(), now()->addMinutes($lead)]);
        } else {
            $events = Event::where('status', 'published')
                ->where(fn ($q) => $q
                    ->whereBetween('ends_at', [now()->subDays(7), now()])
                    ->orWhere(fn ($w) => $w->whereNull('ends_at')->whereBetween('starts_at', [now()->subDays(7)->subHours(3), now()->subHours(3)])));
        }

        $events = self::scopeEvents($events, $a)->get();
        $count = 0;

        foreach ($events as $event) {
            $anchor = $upcoming ? $event->starts_at : ($event->ends_at ?? $event->starts_at->copy()->addHours(3));

            $orders = Order::where('event_id', $event->id)->where('status', 'paid')->whereNotNull('buyer_email')
                ->with('user:id,email,notification_preferences')
                ->get(['id', 'buyer_email', 'buyer_name', 'user_id']);

            foreach ($orders->unique(fn ($o) => strtolower($o->buyer_email)) as $order) {
                // An account holder who turned off event reminders gets none.
                if ($upcoming && $order->user && ! ($order->user->notificationSettings()['event_reminders'] ?? true)) {
                    continue;
                }

                $count += self::enroll($a, $order->buyer_email, $order->buyer_name, $order->user_id, "event:{$event->id}", ['event_id' => $event->id, 'order_id' => $order->id], $anchor) ? 1 : 0;
            }
        }

        return $count;
    }

    /** People who filled in checkout (or are signed in) and never paid. */
    private static function enrollAbandoned(EdmAutomation $a): int
    {
        $since = max($a->activated_at?->getTimestamp() ?? 0, now()->subDays(3)->getTimestamp());

        $orders = Order::query()
            ->whereNull('paid_at')
            ->whereIn('status', ['pending', 'cancelled', 'failed'])
            ->whereBetween('created_at', [Carbon::createFromTimestamp($since), now()->subMinutes(CheckoutFunnel::HOLD_MINUTES)])
            ->whereIn('event_id', self::scopeEvents(Event::query(), $a)->select('id'))
            ->with('user:id,email,name', 'event:id,status,starts_at')
            ->latest('id')
            ->limit(500)
            ->get();

        $count = 0;

        foreach ($orders as $order) {
            $email = $order->buyer_email ?: $order->user?->email;

            // Nothing to recover for an event that has passed or been pulled.
            if (! $email || ! $order->event || $order->event->status !== 'published' || ($order->event->starts_at && $order->event->starts_at->isPast())) {
                continue;
            }

            if (self::hasPaid($email, $order->event_id)) {
                continue;
            }

            $count += self::enroll($a, $email, $order->buyer_name ?: $order->user?->name, $order->user_id, "event:{$order->event_id}", ['event_id' => $order->event_id, 'order_id' => $order->id], $order->created_at) ? 1 : 0;
        }

        return $count;
    }

    /** People who joined the list since the sequence was switched on. */
    private static function enrollSubscribers(EdmAutomation $a): int
    {
        $count = 0;

        EmailConsent::where('scope', Consent::scope($a->organizer_id))
            ->where('status', 'subscribed')
            ->where('consented_at', '>=', $a->activated_at ?? now())
            ->with('user:id,name')
            ->limit(1000)
            ->get()
            ->each(function (EmailConsent $c) use ($a, &$count) {
                $count += self::enroll($a, $c->email, $c->user?->name, $c->user_id, 'welcome', [], $c->consented_at) ? 1 : 0;
            });

        return $count;
    }

    /** Events a sequence covers: the organizer's own, or — for DropRSVP's — everyone else's. */
    private static function scopeEvents($query, EdmAutomation $a)
    {
        $ids = array_filter(array_map('intval', (array) ($a->settings['event_ids'] ?? [])));

        return $query
            ->when($a->organizer_id, fn ($q) => $q->where('user_id', $a->organizer_id))
            // The platform's sequence steps aside for organizers running their own.
            ->when(! $a->organizer_id, fn ($q) => $q->whereNotIn('user_id', EdmAutomation::where('trigger', $a->trigger)
                ->where('status', 'active')->whereNotNull('organizer_id')->select('organizer_id')))
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids));
    }

    private static function enroll(EdmAutomation $a, string $email, ?string $name, ?int $userId, string $key, array $context, CarbonInterface $anchor): bool
    {
        $email = Consent::normalise($email);

        if (! $email || Consent::isSuppressed($email) || Consent::optedOutOfAutomations($email, $a->organizer_id)) {
            return false;
        }

        if (EdmEnrollment::where('automation_id', $a->id)->where('email', $email)->where('context_key', $key)->exists()) {
            return false;
        }

        // The first step that is not already too late.
        $steps = $a->steps->values();
        $index = $steps->search(fn (EdmAutomationStep $s) => $anchor->copy()->addMinutes($s->offsetMinutes())->gte(now()->subMinutes(self::GRACE_MINUTES)));

        try {
            EdmEnrollment::create([
                'automation_id' => $a->id,
                'email' => $email,
                'name' => $name ? mb_substr($name, 0, 160) : null,
                'user_id' => $userId,
                'context_key' => $key,
                'context' => $context,
                'anchor_at' => $anchor,
                'status' => $index === false ? 'completed' : 'active',
                'next_step' => $index === false ? $steps->count() : $index,
                'next_at' => $index === false ? null : $anchor->copy()->addMinutes($steps[$index]->offsetMinutes()),
                'exit_reason' => $index === false ? 'Enrolled after the last email was due' : null,
                'completed_at' => $index === false ? now() : null,
            ]);
        } catch (QueryException) {
            return false; // a concurrent run enrolled them first
        }

        return true;
    }

    /** Send whatever is due. @return array{queued: int, skipped: int, exited: int} */
    public static function process(int $limit = 500): array
    {
        $summary = ['queued' => 0, 'skipped' => 0, 'exited' => 0];

        $due = EdmEnrollment::where('status', 'active')
            ->where('next_at', '<=', now())
            ->whereIn('automation_id', EdmAutomation::where('status', 'active')->select('id'))
            ->with('automation.steps.campaign')
            ->orderBy('next_at')
            ->limit($limit)
            ->get();

        foreach ($due as $enrollment) {
            $a = $enrollment->automation;
            $steps = $a->steps->values();
            $step = $steps[$enrollment->next_step] ?? null;

            if (! $step) {
                self::finish($enrollment, 'completed');

                continue;
            }

            if ($reason = self::exitReason($enrollment)) {
                self::finish($enrollment, 'exited', $reason);
                $summary['exited']++;

                continue;
            }

            if (self::qualifies($enrollment, $step, $steps) && self::queue($enrollment, $step)) {
                $summary['queued']++;
            } else {
                $step->increment('skipped_count');
                $summary['skipped']++;
            }

            $next = $steps[$enrollment->next_step + 1] ?? null;

            if ($next) {
                $enrollment->forceFill([
                    'next_step' => $enrollment->next_step + 1,
                    'next_at' => $enrollment->anchor_at->copy()->addMinutes($next->offsetMinutes()),
                ])->save();
            } else {
                self::finish($enrollment, 'completed');
            }
        }

        return $summary;
    }

    /** Reasons to stop the sequence for this person entirely. */
    private static function exitReason(EdmEnrollment $e): ?string
    {
        $a = $e->automation;

        if (Consent::isSuppressed($e->email) || Consent::optedOutOfAutomations($e->email, $a->organizer_id)) {
            return 'Unsubscribed or suppressed';
        }

        $eventId = (int) ($e->context['event_id'] ?? 0);
        $event = $eventId ? Event::find($eventId) : null;

        if ($eventId && (! $event || $event->status === 'cancelled')) {
            return 'Event cancelled';
        }

        if ($a->trigger === 'abandoned_checkout' && self::hasPaid($e->email, $eventId)) {
            return 'Bought tickets';
        }

        if ($a->trigger === 'event_reminder') {
            $order = Order::find($e->context['order_id'] ?? 0);

            if (! $order || $order->status !== 'paid') {
                return 'Order refunded or cancelled';
            }
        }

        return null;
    }

    private static function qualifies(EdmEnrollment $e, EdmAutomationStep $step, $steps): bool
    {
        $eventId = (int) ($e->context['event_id'] ?? 0);
        $previous = $steps[$e->next_step - 1] ?? null;
        $previousSend = $previous
            ? EmailSend::where('campaign_id', $previous->campaign_id)->where('dedupe', (string) $e->id)->first()
            : null;

        foreach ((array) $step->conditions as $condition) {
            $ok = match ($condition) {
                'subscribed' => Consent::isSubscribed($e->email, $e->automation->organizer_id),
                'not_purchased' => ! self::hasPaid($e->email, $eventId, $e->anchor_at),
                'checked_in' => self::checkedIn($e),
                'not_checked_in' => ! self::checkedIn($e),
                'opened_previous' => (bool) $previousSend?->opened_at,
                'not_opened_previous' => $previousSend !== null && $previousSend->opened_at === null,
                'clicked_previous' => (bool) $previousSend?->clicked_at,
                default => true,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function queue(EdmEnrollment $e, EdmAutomationStep $step): bool
    {
        $campaign = $step->campaign;
        $marketing = (bool) ($campaign->audience['marketing'] ?? false);

        if (! Consent::mayEmailAutomation($e->email, $campaign->organizer_id, $marketing)) {
            return false;
        }

        // Organizers pay for automated mail like any other: no credits, no send.
        if ($campaign->organizer_id && ! Credits::spend((int) $campaign->organizer_id, 1, $campaign->id, "Automation “{$e->automation->name}”")) {
            return false;
        }

        try {
            EmailSend::create([
                'campaign_id' => $campaign->id,
                'user_id' => $e->user_id,
                'email' => $e->email,
                'name' => $e->name,
                'context' => self::context($e),
                'dedupe' => (string) $e->id,
                'token' => Str::random(40),
                'status' => 'queued',
            ]);
        } catch (QueryException) {
            return false; // already queued for this enrollment
        }

        $campaign->increment('recipients_count');

        return true;
    }

    /** The {{tokens}} for this person's email, fresh at send time. */
    public static function context(EdmEnrollment $e): array
    {
        $event = ($id = $e->context['event_id'] ?? null) ? Event::with('user.organizerProfile')->find($id) : null;
        $organizer = $event?->user ?? ($e->automation->organizer_id ? User::find($e->automation->organizer_id) : null);
        $next = $organizer?->slug ? Url::slash(Url::to('o', $organizer->slug)) : Url::slash(Url::to('all'));

        if (! $event) {
            return array_filter([
                'organizer_name' => $organizer ? SeoTemplate::organizerName($organizer) : config('edm.from.name', 'DropRSVP'),
                'next_events_url' => $next,
            ]);
        }

        $tz = $event->timezone ?: Dates::tz();
        $starts = $event->starts_at?->copy()->setTimezone($tz);
        $url = Url::slash(Url::to('e', $event->slug));

        return array_filter([
            'event_name' => $event->title,
            'event_date' => $starts?->format('D, j M Y'),
            'event_time' => $starts ? ($starts->format('i') === '00' ? $starts->format('ga') : $starts->format('g.ia')) : null,
            'event_venue' => $event->is_online ? 'Online' : trim(($event->venue_name ?? '').($event->city ? ', '.$event->city : ''), ', '),
            'event_url' => $url,
            'review_url' => $url.'?tab=reviews',
            'organizer_name' => SeoTemplate::organizerName($event->user),
            'next_events_url' => $next,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function hasPaid(string $email, int $eventId, ?CarbonInterface $since = null): bool
    {
        return $eventId > 0 && Order::where('event_id', $eventId)
            ->whereNotNull('paid_at')
            ->whereRaw('LOWER(buyer_email) = ?', [strtolower($email)])
            ->when($since, fn ($q) => $q->where('paid_at', '>=', $since))
            ->exists();
    }

    private static function checkedIn(EdmEnrollment $e): bool
    {
        $orderId = (int) ($e->context['order_id'] ?? 0);

        return $orderId > 0 && DB::table('tickets')->where('order_id', $orderId)->whereNotNull('checked_in_at')->exists();
    }

    private static function finish(EdmEnrollment $e, string $status, ?string $reason = null): void
    {
        $e->forceFill(['status' => $status, 'exit_reason' => $reason, 'completed_at' => now(), 'next_at' => null])->save();
    }
}
