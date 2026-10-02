<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CampaignMail;
use App\Models\EdmSendingDomain;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Models\EmailTemplate;
use App\Models\Event;
use App\Models\EventCategory;
use App\Services\Edm\CampaignSender;
use App\Support\Dates;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use App\Support\Edm\Renderer;
use App\Support\Edm\Settings;
use App\Support\Edm\SpamCheck;
use App\Support\Edm\StarterTemplates;
use App\Support\Edm\Throttle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Campaigns, for whichever workspace this is: DropRSVP's own (EDM in the admin
 * panel), or one organizer's (Email marketing in the host panel, which extends
 * this class and overrides the four workspace methods below).
 *
 * One controller, so the two cannot drift: the same validation, the same
 * sender, the same send-time checks. Everything is scoped by organizer_id —
 * null for the platform, the organizer's id otherwise.
 */
class EdmCampaignController extends Controller
{
    public function __construct(protected CampaignSender $sender) {}

    // ---- the workspace -----------------------------------------------------

    /** Whose campaigns these are: null = DropRSVP's own. */
    protected function scopeId(): ?int
    {
        return null;
    }

    /** URL root of this workspace. */
    protected function base(): string
    {
        return '/admin/edm';
    }

    /** The Inertia page for $name in this workspace. */
    protected function page(string $name): string
    {
        return 'admin/edm/'.$name;
    }

    /** Extra props a workspace adds to its pages (credits, domains…). */
    protected function extra(string $page, ?EmailCampaign $campaign = null): array
    {
        return [];
    }

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['draft', 'scheduled', 'active', 'sent'], true) ? $request->query('status') : '';
        $q = trim((string) $request->query('q', ''));

        $campaigns = EmailCampaign::query()
            ->where('organizer_id', $this->scopeId())
            // Automation steps live under Automations, not here.
            ->where('kind', '!=', 'automation')
            ->when($status === 'active', fn ($x) => $x->whereIn('status', ['sending', 'paused']))
            ->when($status === 'sent', fn ($x) => $x->whereIn('status', ['sent', 'cancelled']))
            ->when(in_array($status, ['draft', 'scheduled'], true), fn ($x) => $x->where('status', $status))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('subject', 'like', "%{$q}%")))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EmailCampaign $c) => $this->summary($c));

        $last30 = EmailCampaign::where('organizer_id', $this->scopeId())->where('kind', '!=', 'automation')->where('started_at', '>=', now()->subDays(30));

        return Inertia::render($this->page('campaigns/index'), [
            ...$this->extra('index'),
            'base' => $this->base(),
            'campaigns' => $campaigns,
            'stats' => [
                'subscribers' => EmailConsent::where('scope', Consent::scope($this->scopeId()))->where('status', 'subscribed')->count(),
                'sent_30d' => (int) (clone $last30)->sum('sent_count'),
                'open_rate_30d' => $this->rate((clone $last30)->sum('opened_count'), (clone $last30)->sum('sent_count')),
                'click_rate_30d' => $this->rate((clone $last30)->sum('clicked_count'), (clone $last30)->sum('sent_count')),
            ],
            'throttle' => Throttle::status(),
            // Everyone with an account or a purchase who has never been asked.
            // (The platform's alone: organizers' lists only grow by opt-in.)
            'repermissionEligible' => $this->scopeId() === null ? Audience::repermissionCount() : 0,
            'filters' => ['status' => $status, 'q' => $q],
            'statusCounts' => EmailCampaign::where('organizer_id', $this->scopeId())->where('kind', '!=', 'automation')
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            // For "New campaign": start blank, from a starter, or a saved template.
            'starters' => collect(StarterTemplates::all())->map(fn ($t) => ['key' => $t['key'], 'name' => $t['name'], 'description' => $t['description']])->values(),
            'templates' => EmailTemplate::where('organizer_id', $this->scopeId())->latest('updated_at')->limit(50)->get(['id', 'name', 'description']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['nullable', 'in:standard,repermission'],
        ]);

        $repermission = ($data['kind'] ?? 'standard') === 'repermission' && $this->scopeId() === null;

        $campaign = EmailCampaign::create([
            'name' => $data['name'],
            'kind' => $repermission ? 'repermission' : 'standard',
            // The re-permission email comes written: it has one job, and the
            // wording of that job matters more than any design choice.
            'subject' => $repermission ? 'Can we keep you posted about events?' : '',
            'preheader' => $repermission ? 'One click and you are in. Ignore this and we will not ask again.' : null,
            'organizer_id' => $this->scopeId(),
            // Blank for an organizer: the sender name then follows their
            // business name (CampaignSender::senderName).
            'from_name' => $this->scopeId() === null ? Settings::get('from_name') : null,
            'design' => $repermission ? self::repermissionDesign() : self::starterDesign(),
            'audience' => $repermission ? null : Audience::normalise([]),
            'created_by' => $request->user()->id,
        ]);

        return redirect($this->base().'/campaigns/'.$campaign->id);
    }

    public function show(EmailCampaign $campaign)
    {
        $this->own($campaign);

        return Inertia::render($this->page('campaigns/show'), [
            ...$this->extra('show', $campaign),
            'base' => $this->base(),
            'campaign' => [
                ...$this->summary($campaign),
                'subject' => $campaign->subject,
                'preheader' => $campaign->preheader,
                'from_name' => $campaign->from_name,
                'from_address' => $campaign->from_address,
                'reply_to' => $campaign->reply_to,
                'audience' => Audience::normalise($campaign->audience),
                'has_content' => ! empty($campaign->design['content']),
                'paused_reason' => $campaign->paused_reason,
                'scheduled_at_local' => $campaign->scheduled_at?->setTimezone(Dates::tz())->format('Y-m-d\TH:i'),
            ],
            'audienceCount' => match (true) {
                ! $campaign->isEditable() => $campaign->recipients_count,
                $campaign->kind === 'repermission' => Audience::repermissionCount($campaign->id),
                default => Audience::count($campaign->audience, $this->scopeId()),
            },
            // For a re-permission email, the only result that matters.
            'confirmed' => $campaign->kind === 'repermission' ? $this->confirmed($campaign) : null,
            'options' => $this->audienceOptions(),
            'links' => $campaign->links()->orderByDesc('clicks')->limit(15)->get(['url', 'clicks', 'unique_clicks']),
            'throttle' => Throttle::status(),
            'fromAddress' => config('edm.from.address'),
            'senderName' => CampaignSender::senderName($campaign),
            'testEmail' => request()->user()->email,
            // Checked on every load of an unsent campaign, so it reflects the
            // last saved subject and design.
            'spam' => $campaign->isEditable() ? SpamCheck::forCampaign($campaign) : null,
        ]);
    }

    public function update(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);
        $this->editable($campaign);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:191'],
            'from_address' => ['nullable', 'email', 'max:191'],
            'audience' => ['nullable', 'array'],
        ]);

        // A From address on the organizer's own domain: only one they have
        // verified. Anything else is dropped back to the platform address.
        $data['sending_domain_id'] = null;
        if (! empty($data['from_address']) && $this->scopeId() !== null) {
            $host = strtolower(substr(strrchr($data['from_address'], '@') ?: '', 1));
            $domain = EdmSendingDomain::where('organizer_id', $this->scopeId())->where('domain', $host)->where('status', 'verified')->first();

            if (! $domain) {
                throw ValidationException::withMessages(['from_address' => "Verify {$host} under Sending domain before sending from it."]);
            }

            $data['sending_domain_id'] = $domain->id;
            $data['from_address'] = strtolower($data['from_address']);
        } else {
            $data['from_address'] = null;
        }

        $campaign->update([
            ...$data,
            'subject' => (string) ($data['subject'] ?? ''),
            // A re-permission email has a fixed audience; filters do not apply.
            'audience' => $campaign->kind === 'repermission' ? null : Audience::normalise($data['audience'] ?? []),
        ]);

        return back()->with('flash_success', 'Campaign saved.');
    }

    /** The full-screen builder. */
    public function editor(EmailCampaign $campaign)
    {
        $this->own($campaign);

        return Inertia::render('admin/edm/campaigns/editor', [
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name, 'editable' => $campaign->isEditable()],
            'design' => $campaign->design ?: self::starterDesign(),
            'events' => self::eventChoices($this->scopeId()),
            'saveUrl' => $this->base().'/campaigns/'.$campaign->id.'/design',
            'backUrl' => $this->base().'/campaigns/'.$campaign->id,
            'backLabel' => 'Campaign',
        ]);
    }

    public function saveDesign(Request $request, EmailCampaign $campaign): JsonResponse
    {
        $this->own($campaign);
        $this->editable($campaign);

        $data = $request->validate([
            'design' => ['required', 'array'],
            'design.content' => ['present', 'array', 'max:80'],
            'design.root' => ['nullable', 'array'],
        ]);

        $campaign->update(['design' => [
            'root' => $data['design']['root'] ?? ['props' => []],
            'content' => array_values($data['design']['content']),
        ]]);

        return response()->json(['ok' => true]);
    }

    /**
     * The email exactly as the renderer produces it — what an inbox gets, not
     * the editor's approximation of it. Shown in a sandboxed iframe.
     */
    public function preview(EmailCampaign $campaign): Response
    {
        $this->own($campaign);

        $html = $campaign->isEditable() || ! $campaign->html
            ? Renderer::render((array) $campaign->design, CampaignSender::renderContext($campaign))['html']
            : (string) $campaign->html;

        return response($this->sample($html, request()->user()?->name), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // The admin page frames this; nothing else should.
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "script-src 'none'; frame-ancestors 'self'",
        ]);
    }

    /**
     * Send a test copy, straight away and outside the throttle.
     *
     * To a handful of addresses typed by the admin, not to subscribers, so it
     * skips consent — but it does not touch any counter or the send table, and
     * its links go straight to their targets instead of through tracking.
     */
    public function test(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        $data = $request->validate([
            'emails' => ['required', 'string', 'max:500'],
        ]);

        $emails = collect(preg_split('/[\s,;]+/', $data['emails']))
            ->map(fn ($e) => Consent::normalise($e))
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty() || $emails->count() > 5) {
            throw ValidationException::withMessages(['emails' => 'Enter between one and five email addresses.']);
        }

        if (trim((string) $campaign->subject) === '') {
            throw ValidationException::withMessages(['emails' => 'Add a subject line first.']);
        }

        $rendered = Renderer::render((array) $campaign->design, CampaignSender::renderContext($campaign));

        foreach ($emails as $email) {
            try {
                Mail::mailer(config('edm.mailer', 'edm'))->to($email)->send(new CampaignMail(
                    subjectLine: '[Test] '.$this->sample($campaign->subject, $request->user()?->name),
                    htmlBody: $this->sample($rendered['html'], $request->user()?->name),
                    textBody: $this->sample($rendered['text'], $request->user()?->name),
                    unsubscribeUrl: url('/settings/notifications'),
                    fromAddress: (string) config('edm.from.address'),
                    fromName: CampaignSender::senderName($campaign),
                    replyToAddress: $campaign->reply_to ?: (Settings::get('reply_to') ?: null),
                    campaignTag: 'test-c'.$campaign->id,
                ));
            } catch (\Throwable $e) {
                report($e);

                throw ValidationException::withMessages(['emails' => 'The mail server refused the test: '.mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return back()->with('flash_success', 'Test sent to '.$emails->implode(', ').'.');
    }

    /** Live recipient count for the audience builder, before saving. */
    public function audienceCount(Request $request): JsonResponse
    {
        $data = $request->validate(['audience' => ['nullable', 'array']]);

        return response()->json(['count' => Audience::count($data['audience'] ?? [], $this->scopeId())]);
    }

    public function send(EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        return $this->attempt(fn () => $this->sender->start($campaign), 'Sending has started. Emails go out a few at a time within the hourly limit.');
    }

    public function schedule(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        $data = $request->validate(['at' => ['required', 'date_format:Y-m-d\TH:i']]);

        // Typed in Malaysian time, stored in UTC like every other timestamp.
        $at = Carbon::createFromFormat('Y-m-d\TH:i', $data['at'], Dates::tz())->utc();

        if ($at->isPast()) {
            throw ValidationException::withMessages(['at' => 'Pick a time in the future.']);
        }

        return $this->attempt(
            fn () => $this->sender->schedule($campaign, $at),
            'Scheduled for '.Dates::display($at, 'D j M Y, g:ia').'.',
        );
    }

    public function unschedule(EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        if ($campaign->status === 'scheduled') {
            $campaign->update(['status' => 'draft', 'scheduled_at' => null]);
        }

        return back()->with('flash_success', 'Back to draft.');
    }

    public function pause(EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);
        $this->sender->pause($campaign);

        return back()->with('flash_success', 'Paused. Nothing more goes out until you resume.');
    }

    public function resume(EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        return $this->attempt(fn () => $this->sender->resume($campaign), 'Resumed.');
    }

    public function cancel(EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);
        $this->sender->cancel($campaign);

        return back()->with('flash_success', 'Cancelled. Nothing more will be sent.');
    }

    public function duplicate(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        $copy = EmailCampaign::create([
            'organizer_id' => $campaign->organizer_id,
            'from_address' => $campaign->from_address,
            'sending_domain_id' => $campaign->sending_domain_id,
            'name' => mb_substr($campaign->name.' (copy)', 0, 160),
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            'from_name' => $campaign->from_name,
            'reply_to' => $campaign->reply_to,
            'design' => $campaign->design,
            'audience' => $campaign->audience,
            'created_by' => $request->user()->id,
        ]);

        return redirect($this->base().'/campaigns/'.$copy->id)->with('flash_success', 'Copied. This one has not been sent.');
    }

    public function destroy(EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        // Anything that has started sending is a record of mail that went out.
        if (! $campaign->isEditable()) {
            return back()->with('flash_error', 'A campaign that has been sent stays on record. Cancel it instead.');
        }

        $campaign->delete();

        return redirect($this->base().'/campaigns')->with('flash_success', 'Draft deleted.');
    }

    // ---- helpers ------------------------------------------------------------

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('flash_error', $e->getMessage());
        }

        return back()->with('flash_success', $success);
    }

    /** This controller handles DropRSVP's own list only. */
    private function own(EmailCampaign $campaign): void
    {
        $scope = $this->scopeId();

        abort_unless($scope === null ? $campaign->organizer_id === null : (int) $campaign->organizer_id === $scope, 404);
        // A step's hidden campaign is managed from its automation.
        abort_if($campaign->kind === 'automation', 404);
    }

    private function editable(EmailCampaign $campaign): void
    {
        if (! $campaign->isEditable()) {
            throw ValidationException::withMessages(['name' => 'This campaign has started sending and can no longer be changed.']);
        }
    }

    private function summary(EmailCampaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'kind' => $c->kind,
            'recipients' => $c->recipients_count,
            'sent' => $c->sent_count,
            'failed' => $c->failed_count,
            'opened' => $c->opened_count,
            'clicked' => $c->clicked_count,
            'bounced' => $c->bounced_count,
            'unsubscribed' => $c->unsubscribed_count,
            'open_rate' => $this->rate($c->opened_count, $c->sent_count),
            'click_rate' => $this->rate($c->clicked_count, $c->sent_count),
            'scheduled_at' => Dates::display($c->scheduled_at, 'D j M Y, g:ia'),
            'started_at' => Dates::display($c->started_at, 'j M Y, g:ia'),
            'finished_at' => Dates::display($c->finished_at, 'j M Y, g:ia'),
            'updated_at' => Dates::display($c->updated_at, 'j M Y'),
            'editable' => $c->isEditable(),
        ];
    }

    private function rate(int|float|string|null $part, int|float|string|null $whole): ?float
    {
        return (float) $whole > 0 ? round(100 * (float) $part / (float) $whole, 1) : null;
    }

    /** Fill merge tags with a sample, for previews and tests. Links stay direct. */
    private function sample(string $content, ?string $name): string
    {
        $first = $name ? strtok(trim($name), ' ') : 'there';

        $content = preg_replace('/\{\{click:\d+\}\}/', '#', $content) ?? $content;

        return strtr($content, [
            '{{first_name}}' => e($first ?: 'there'),
            '{{name}}' => e($name ?: 'there'),
            '{{email}}' => e(request()->user()?->email ?? 'reader@example.com'),
            '{{unsubscribe_url}}' => url('/settings/notifications'),
            '{{subscribe_url}}' => '#',
            '{{view_url}}' => '#',
        ]);
    }

    private function audienceOptions(): array
    {
        return [
            'cities' => EmailConsent::query()
                ->where('email_consents.scope', Consent::scope($this->scopeId()))
                ->where('email_consents.status', 'subscribed')
                ->join('users', 'users.id', '=', 'email_consents.user_id')
                ->whereNotNull('users.city')
                ->distinct()
                ->orderBy('users.city')
                ->pluck('users.city')
                ->values(),
            // Past events included: "everyone who came to X" is the point.
            // Ended is not a status here — it is a published event whose end
            // time has passed — so published covers both.
            'events' => Event::query()
                ->where('status', 'published')
                // An organizer targets by their own events only.
                ->when($this->scopeId(), fn ($q, $id) => $q->where('user_id', $id))
                ->latest('starts_at')
                ->limit(300)
                ->get(['id', 'title', 'starts_at', 'timezone'])
                ->map(fn (Event $e) => [
                    'value' => (string) $e->id,
                    'label' => $e->title,
                    'hint' => $e->starts_at?->setTimezone($e->timezone ?: Dates::tz())->format('j M Y'),
                ]),
            'categories' => EventCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name]),
        ];
    }

    /** Published, upcoming events the editor's event card can pick from. */
    public static function eventChoices(?int $organizerId = null): array
    {
        return Event::query()
            ->where('status', 'published')
            ->when($organizerId, fn ($q, $id) => $q->where('user_id', $id))
            ->notEnded()
            ->orderBy('starts_at')
            ->limit(300)
            ->get(['slug', 'title', 'starts_at', 'timezone', 'city', 'cover_image'])
            ->map(fn (Event $e) => [
                'value' => $e->slug,
                'label' => $e->title,
                'hint' => trim(($e->starts_at?->setTimezone($e->timezone ?: Dates::tz())->format('j M Y') ?? '').' · '.($e->city ?? ''), ' ·'),
                'image' => $e->cover_image,
            ])
            ->all();
    }

    /** How many people this re-permission email turned into subscribers. */
    private function confirmed(EmailCampaign $campaign): int
    {
        return EmailConsent::query()
            ->where('scope', Consent::PLATFORM)
            ->where('status', 'subscribed')
            ->where('source', 'repermission')
            ->whereIn('email', $campaign->sends()->select('email'))
            ->count();
    }

    /** The one-off "may we email you?" message. */
    public static function repermissionDesign(): array
    {
        return [
            'root' => ['props' => ['brandColor' => Renderer::DEFAULT_BRAND, 'backgroundColor' => '#f3f4f6', 'showLogo' => true]],
            'content' => [
                ['type' => 'Heading', 'props' => ['id' => 'Heading-1', 'text' => 'Want to hear about events near you?', 'level' => 'h1', 'align' => 'left']],
                ['type' => 'Text', 'props' => ['id' => 'Text-1', 'align' => 'left', 'html' => '<p>Hi {{first_name}},</p>'
                    .'<p>You have an account or bought tickets on DropRSVP. Now and then we would like to email you about upcoming events: gigs, workshops, meetups and the like.</p>'
                    .'<p>Only if you want us to. One click below and you are in.</p>']],
                ['type' => 'Button', 'props' => ['id' => 'Button-1', 'label' => 'Yes, keep me posted', 'url' => '{{subscribe_url}}', 'align' => 'center', 'color' => '']],
                ['type' => 'Text', 'props' => ['id' => 'Text-2', 'align' => 'center', 'html' => '<p>Not interested? Just ignore this email. This is the only time we will ask.</p>']],
            ],
        ];
    }

    /** A new campaign is not a blank page: a sensible skeleton to edit. */
    public static function starterDesign(): array
    {
        return [
            'root' => ['props' => ['brandColor' => Renderer::DEFAULT_BRAND, 'backgroundColor' => '#f3f4f6', 'showLogo' => true]],
            'content' => [
                ['type' => 'Heading', 'props' => ['id' => 'Heading-1', 'text' => 'Happening this week', 'level' => 'h1', 'align' => 'left']],
                ['type' => 'Text', 'props' => ['id' => 'Text-1', 'html' => '<p>Hi {{first_name}},</p><p>Here is what is on near you.</p>', 'align' => 'left']],
                ['type' => 'Button', 'props' => ['id' => 'Button-1', 'label' => 'Browse events', 'url' => url('/en-my/all/'), 'align' => 'center', 'color' => '']],
            ],
        ];
    }
}
