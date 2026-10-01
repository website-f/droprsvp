<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Models\Event;
use App\Models\EventCategory;
use App\Services\Edm\CampaignSender;
use App\Support\Dates;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use App\Support\Edm\Renderer;
use App\Support\Edm\Settings;
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
 * Admin > Email marketing: DropRSVP's own campaigns.
 *
 * Organizer workspaces (Phase 2) will reuse the same models and sender with
 * organizer_id set; everything here is scoped to organizer_id = null.
 */
class EdmCampaignController extends Controller
{
    public function __construct(private CampaignSender $sender) {}

    public function index(Request $request)
    {
        $campaigns = EmailCampaign::query()
            ->whereNull('organizer_id')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EmailCampaign $c) => $this->summary($c));

        $last30 = EmailCampaign::whereNull('organizer_id')->where('started_at', '>=', now()->subDays(30));

        return Inertia::render('admin/edm/campaigns/index', [
            'campaigns' => $campaigns,
            'stats' => [
                'subscribers' => EmailConsent::where('scope', Consent::PLATFORM)->where('status', 'subscribed')->count(),
                'sent_30d' => (int) (clone $last30)->sum('sent_count'),
                'open_rate_30d' => $this->rate((clone $last30)->sum('opened_count'), (clone $last30)->sum('sent_count')),
                'click_rate_30d' => $this->rate((clone $last30)->sum('clicked_count'), (clone $last30)->sum('sent_count')),
            ],
            'throttle' => Throttle::status(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);

        $campaign = EmailCampaign::create([
            'name' => $data['name'],
            'subject' => '',
            'from_name' => Settings::get('from_name'),
            'design' => self::starterDesign(),
            'audience' => Audience::normalise([]),
            'created_by' => $request->user()->id,
        ]);

        return to_route('admin.edm.campaigns.show', $campaign);
    }

    public function show(EmailCampaign $campaign)
    {
        $this->own($campaign);

        return Inertia::render('admin/edm/campaigns/show', [
            'campaign' => [
                ...$this->summary($campaign),
                'subject' => $campaign->subject,
                'preheader' => $campaign->preheader,
                'from_name' => $campaign->from_name,
                'reply_to' => $campaign->reply_to,
                'audience' => Audience::normalise($campaign->audience),
                'has_content' => ! empty($campaign->design['content']),
                'paused_reason' => $campaign->paused_reason,
                'scheduled_at_local' => $campaign->scheduled_at?->setTimezone(Dates::tz())->format('Y-m-d\TH:i'),
            ],
            'audienceCount' => $campaign->isEditable()
                ? Audience::count($campaign->audience)
                : $campaign->recipients_count,
            'options' => $this->audienceOptions(),
            'links' => $campaign->links()->orderByDesc('clicks')->limit(15)->get(['url', 'clicks', 'unique_clicks']),
            'throttle' => Throttle::status(),
            'fromAddress' => config('edm.from.address'),
            'testEmail' => request()->user()->email,
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
            'audience' => ['nullable', 'array'],
        ]);

        $campaign->update([
            ...$data,
            'subject' => (string) ($data['subject'] ?? ''),
            'audience' => Audience::normalise($data['audience'] ?? []),
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
            'events' => $this->eventChoices(),
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
            ? Renderer::render((array) $campaign->design, $this->context($campaign))['html']
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

        $rendered = Renderer::render((array) $campaign->design, $this->context($campaign));

        foreach ($emails as $email) {
            try {
                Mail::mailer(config('edm.mailer', 'edm'))->to($email)->send(new CampaignMail(
                    subjectLine: '[Test] '.$this->sample($campaign->subject, $request->user()?->name),
                    htmlBody: $this->sample($rendered['html'], $request->user()?->name),
                    textBody: $this->sample($rendered['text'], $request->user()?->name),
                    unsubscribeUrl: url('/settings/notifications'),
                    fromAddress: (string) config('edm.from.address'),
                    fromName: $campaign->from_name ?: (string) Settings::get('from_name'),
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

        return response()->json(['count' => Audience::count($data['audience'] ?? [])]);
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
        $this->sender->resume($campaign);

        return back()->with('flash_success', 'Resumed.');
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
            'name' => mb_substr($campaign->name.' (copy)', 0, 160),
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            'from_name' => $campaign->from_name,
            'reply_to' => $campaign->reply_to,
            'design' => $campaign->design,
            'audience' => $campaign->audience,
            'created_by' => $request->user()->id,
        ]);

        return to_route('admin.edm.campaigns.show', $copy)->with('flash_success', 'Copied. This one has not been sent.');
    }

    public function destroy(EmailCampaign $campaign): RedirectResponse
    {
        $this->own($campaign);

        // Anything that has started sending is a record of mail that went out.
        if (! $campaign->isEditable()) {
            return back()->with('flash_error', 'A campaign that has been sent stays on record. Cancel it instead.');
        }

        $campaign->delete();

        return to_route('admin.edm.campaigns.index')->with('flash_success', 'Draft deleted.');
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
        abort_unless($campaign->organizer_id === null, 404);
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

    private function context(EmailCampaign $campaign): array
    {
        $sender = $campaign->from_name ?: (string) Settings::get('from_name');

        return [
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            'sender' => $sender,
            'address' => (string) Settings::get('postal_address'),
            'reason' => 'You are receiving this because you opted in to emails from DropRSVP.',
        ];
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
            '{{view_url}}' => '#',
        ]);
    }

    private function audienceOptions(): array
    {
        return [
            'cities' => EmailConsent::query()
                ->where('email_consents.scope', Consent::PLATFORM)
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
    private function eventChoices(): array
    {
        return Event::query()
            ->where('status', 'published')
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
