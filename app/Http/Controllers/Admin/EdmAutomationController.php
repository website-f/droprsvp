<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CampaignMail;
use App\Models\EdmAutomation;
use App\Models\EdmAutomationStep;
use App\Models\EdmEnrollment;
use App\Models\EmailCampaign;
use App\Models\Event;
use App\Services\Edm\Automations;
use App\Services\Edm\CampaignSender;
use App\Support\Dates;
use App\Support\Edm\Consent;
use App\Support\Edm\Renderer;
use App\Support\Edm\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Automations: email sequences that send themselves. Scope-aware like the
 * campaign controller — DropRSVP's own here, an organizer's in the host panel.
 */
class EdmAutomationController extends Controller
{
    protected function scopeId(): ?int
    {
        return null;
    }

    protected function base(): string
    {
        return '/admin/edm';
    }

    protected function page(string $name): string
    {
        return 'admin/edm/'.$name;
    }

    public function index()
    {
        $automations = EdmAutomation::where('organizer_id', $this->scopeId())
            ->with('steps.campaign:id,sent_count,opened_count,clicked_count')
            ->withCount([
                'enrollments as active_count' => fn ($q) => $q->where('status', 'active'),
                'enrollments as total_count',
            ])
            ->latest('id')
            ->get()
            ->map(function (EdmAutomation $a) {
                $sent = $a->steps->sum(fn ($s) => $s->campaign?->sent_count ?? 0);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'trigger' => $a->trigger,
                    'status' => $a->status,
                    'steps' => $a->steps->count(),
                    'timeline' => $a->steps->map(fn ($s) => $s->timingLabel())->all(),
                    'active' => $a->active_count,
                    'enrolled' => $a->total_count,
                    'sent' => $sent,
                    'open_rate' => $sent ? round(100 * $a->steps->sum(fn ($s) => $s->campaign?->opened_count ?? 0) / $sent, 1) : null,
                    'click_rate' => $sent ? round(100 * $a->steps->sum(fn ($s) => $s->campaign?->clicked_count ?? 0) / $sent, 1) : null,
                ];
            });

        return Inertia::render($this->page('automations/index'), [
            'base' => $this->base(),
            'automations' => $automations,
            'triggers' => collect(Automations::TRIGGER_LABELS)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'description' => self::TRIGGER_HELP[$key],
                'steps' => collect(Automations::recipe($key))->map(fn ($s) => (new EdmAutomationStep($s))->timingLabel())->all(),
                'exists' => $automations->contains('trigger', $key),
            ])->values(),
            'lastRun' => Cache::get('edm.automations.last'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trigger' => ['required', Rule::in(EdmAutomation::TRIGGERS)],
            'name' => ['nullable', 'string', 'max:160'],
        ]);

        $a = Automations::create($data['trigger'], $this->scopeId(), $request->user()->id, $data['name'] ?? null);

        return redirect($this->base().'/automations/'.$a->id)->with('flash_success', 'Sequence created with suggested emails. Review them, then switch it on.');
    }

    public function show(EdmAutomation $automation)
    {
        $this->own($automation);
        $automation->load('steps.campaign');

        $counts = $automation->enrollments()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return Inertia::render($this->page('automations/show'), [
            'base' => $this->base(),
            'automation' => [
                'id' => $automation->id,
                'name' => $automation->name,
                'trigger' => $automation->trigger,
                'trigger_label' => Automations::TRIGGER_LABELS[$automation->trigger] ?? $automation->trigger,
                'trigger_help' => self::TRIGGER_HELP[$automation->trigger] ?? '',
                'anchor' => self::ANCHOR[$automation->trigger] ?? 'the trigger',
                'status' => $automation->status,
                'event_ids' => array_values(array_map('intval', (array) ($automation->settings['event_ids'] ?? []))),
                'activated_at' => Dates::display($automation->activated_at, 'j M Y, g:ia'),
            ],
            'steps' => $automation->steps->map(fn (EdmAutomationStep $s) => [
                'id' => $s->id,
                'position' => $s->position,
                'timing' => $s->timingLabel(),
                'delay_value' => $s->delay_value,
                'delay_unit' => $s->delay_unit,
                'delay_direction' => $s->delay_direction,
                'conditions' => (array) $s->conditions,
                'subject' => $s->campaign->subject,
                'preheader' => $s->campaign->preheader,
                'marketing' => (bool) ($s->campaign->audience['marketing'] ?? false),
                'stats' => [
                    'queued' => $s->campaign->recipients_count,
                    'sent' => $s->campaign->sent_count,
                    'opened' => $s->campaign->opened_count,
                    'clicked' => $s->campaign->clicked_count,
                    'bounced' => $s->campaign->bounced_count,
                    'unsubscribed' => $s->campaign->unsubscribed_count,
                    'skipped' => $s->skipped_count,
                    'open_rate' => $s->campaign->sent_count ? round(100 * $s->campaign->opened_count / $s->campaign->sent_count, 1) : null,
                    'click_rate' => $s->campaign->sent_count ? round(100 * $s->campaign->clicked_count / $s->campaign->sent_count, 1) : null,
                ],
            ]),
            'enrollmentCounts' => ['active' => (int) ($counts['active'] ?? 0), 'completed' => (int) ($counts['completed'] ?? 0), 'exited' => (int) ($counts['exited'] ?? 0)],
            'enrollments' => $automation->enrollments()->latest('id')->limit(25)->get()->map(fn (EdmEnrollment $e) => [
                'id' => $e->id,
                'email' => $e->email,
                'name' => $e->name,
                'status' => $e->status,
                'step' => $e->next_step + 1,
                'next' => Dates::display($e->next_at, 'j M, g:ia'),
                'reason' => $e->exit_reason,
                'when' => Dates::display($e->created_at, 'j M, g:ia'),
            ]),
            'conditions' => collect(Automations::CONDITIONS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'tokens' => array_keys(Automations::TOKENS),
            'events' => in_array($automation->trigger, ['event_reminder', 'post_event', 'abandoned_checkout'], true)
                ? Event::where('status', 'published')
                    ->when($this->scopeId(), fn ($q, $id) => $q->where('user_id', $id))
                    ->latest('starts_at')->limit(200)->get(['id', 'title', 'starts_at', 'timezone'])
                    ->map(fn (Event $e) => ['value' => (string) $e->id, 'label' => $e->title, 'hint' => $e->starts_at?->setTimezone($e->timezone ?: Dates::tz())->format('j M Y')])
                : [],
            'testEmail' => request()->user()->email,
        ]);
    }

    public function update(Request $request, EdmAutomation $automation): RedirectResponse
    {
        $this->own($automation);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'event_ids' => ['array'],
            'event_ids.*' => ['integer'],
        ]);

        // Only events this workspace owns.
        $ids = Event::whereIn('id', $data['event_ids'] ?? [])
            ->when($this->scopeId(), fn ($q, $id) => $q->where('user_id', $id))
            ->pluck('id')->all();

        $automation->update(['name' => $data['name'], 'settings' => [...((array) $automation->settings), 'event_ids' => $ids]]);

        return back()->with('flash_success', 'Saved.');
    }

    public function activate(EdmAutomation $automation): RedirectResponse
    {
        $this->own($automation);

        foreach ($automation->steps()->with('campaign')->get() as $step) {
            if (trim((string) $step->campaign->subject) === '') {
                return back()->with('flash_error', 'Every email needs a subject line before the sequence can run.');
            }
        }

        if ($automation->steps()->count() === 0) {
            return back()->with('flash_error', 'Add at least one email first.');
        }

        Automations::activate($automation);

        return back()->with('flash_success', 'Switched on. It picks people up from now — nobody already past the trigger is emailed retroactively.');
    }

    public function pause(EdmAutomation $automation): RedirectResponse
    {
        $this->own($automation);
        Automations::pause($automation);

        return back()->with('flash_success', 'Paused. Queued emails wait; nobody new is enrolled until you switch it back on.');
    }

    public function destroy(EdmAutomation $automation): RedirectResponse
    {
        $this->own($automation);
        Automations::pause($automation);

        $campaignIds = $automation->steps()->pluck('campaign_id');
        $automation->delete();
        // Keep the history of steps that sent something; drop the rest.
        EmailCampaign::whereIn('id', $campaignIds)->whereDoesntHave('sends')->delete();

        return redirect($this->base().'/automations')->with('flash_success', 'Sequence deleted.');
    }

    // ---- steps -----------------------------------------------------------------

    public function addStep(EdmAutomation $automation): RedirectResponse
    {
        $this->own($automation);
        $last = $automation->steps()->get()->last();

        Automations::addStep($automation, [
            'position' => $automation->steps()->count(),
            'delay_value' => $last ? max(1, $last->delay_value) : 1,
            'delay_unit' => $last?->delay_unit ?? 'days',
            'delay_direction' => $last?->delay_direction === 'before' ? 'before' : 'after',
            'subject' => 'A note from {{organizer_name}}',
            'heading' => 'Hello again',
            'body' => '<p>Hi {{first_name}},</p><p>Write your message here.</p>',
        ]);
        Automations::reorder($automation);

        return back()->with('flash_success', 'Email added. Set its timing and content.');
    }

    public function updateStep(Request $request, EdmAutomation $automation, EdmAutomationStep $step): RedirectResponse
    {
        $this->ownStep($automation, $step);

        $data = $request->validate([
            'delay_value' => ['required', 'integer', 'min:0', 'max:1000'],
            'delay_unit' => ['required', Rule::in(array_keys(EdmAutomationStep::UNITS))],
            'delay_direction' => ['required', Rule::in($automation->trigger === 'event_reminder' ? ['before', 'after'] : ['after'])],
            'conditions' => ['array'],
            'conditions.*' => [Rule::in(array_keys(Automations::CONDITIONS))],
            'subject' => ['required', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'marketing' => ['boolean'],
        ]);

        $step->update([
            'delay_value' => $data['delay_value'],
            'delay_unit' => $data['delay_unit'],
            'delay_direction' => $data['delay_direction'],
            'conditions' => array_values(array_unique($data['conditions'] ?? [])),
        ]);

        // Promotional content needs consent: marking a step as marketing also
        // limits it to subscribers.
        $marketing = (bool) ($data['marketing'] ?? false) || in_array('subscribed', $data['conditions'] ?? [], true);

        $step->campaign->update([
            'subject' => $data['subject'],
            'preheader' => $data['preheader'] ?? null,
            'audience' => ['marketing' => $marketing],
        ]);
        Automations::freeze($step->campaign->fresh());
        Automations::reorder($automation);

        return back()->with('flash_success', 'Email saved.');
    }

    public function destroyStep(EdmAutomation $automation, EdmAutomationStep $step): RedirectResponse
    {
        $this->ownStep($automation, $step);

        $campaign = $step->campaign;
        $step->delete();
        if ($campaign && ! $campaign->sends()->exists()) {
            $campaign->delete();
        }

        // Anyone waiting for a later step keeps their place.
        Automations::reorder($automation);
        EdmEnrollment::where('automation_id', $automation->id)->where('status', 'active')
            ->where('next_step', '>=', $automation->steps()->count())
            ->update(['status' => 'completed', 'completed_at' => now(), 'next_at' => null]);

        return back()->with('flash_success', 'Email removed.');
    }

    public function stepEditor(EdmAutomation $automation, EdmAutomationStep $step)
    {
        $this->ownStep($automation, $step);

        return Inertia::render('admin/edm/campaigns/editor', [
            'campaign' => ['id' => $step->campaign_id, 'name' => $automation->name.' · '.$step->timingLabel(), 'editable' => true],
            'design' => $step->campaign->design ?: EdmCampaignController::starterDesign(),
            'events' => EdmCampaignController::eventChoices($this->scopeId()),
            'saveUrl' => $this->base().'/automations/'.$automation->id.'/steps/'.$step->id.'/design',
            'backUrl' => $this->base().'/automations/'.$automation->id,
            'backLabel' => 'Automation',
            'kind' => 'template',
        ]);
    }

    public function saveStepDesign(Request $request, EdmAutomation $automation, EdmAutomationStep $step): JsonResponse
    {
        $this->ownStep($automation, $step);

        $data = $request->validate([
            'design' => ['required', 'array'],
            'design.content' => ['present', 'array', 'max:80'],
            'design.root' => ['nullable', 'array'],
        ]);

        $step->campaign->update(['design' => [
            'root' => $data['design']['root'] ?? ['props' => []],
            'content' => array_values($data['design']['content']),
        ]]);
        Automations::freeze($step->campaign->fresh());

        return response()->json(['ok' => true]);
    }

    public function stepPreview(EdmAutomation $automation, EdmAutomationStep $step): Response
    {
        $this->ownStep($automation, $step);

        $html = Renderer::render((array) $step->campaign->design, CampaignSender::renderContext($step->campaign))['html'];

        return response($this->sample($html), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "script-src 'none'; frame-ancestors 'self'",
        ]);
    }

    public function stepTest(Request $request, EdmAutomation $automation, EdmAutomationStep $step): RedirectResponse
    {
        $this->ownStep($automation, $step);

        $emails = collect(preg_split('/[\s,;]+/', (string) $request->validate(['emails' => ['required', 'string', 'max:500']])['emails']))
            ->map(fn ($e) => Consent::normalise($e))->filter()->unique()->values();

        if ($emails->isEmpty() || $emails->count() > 5) {
            throw ValidationException::withMessages(['emails' => 'Enter between one and five email addresses.']);
        }

        $campaign = $step->campaign;
        $rendered = Renderer::render((array) $campaign->design, CampaignSender::renderContext($campaign));

        foreach ($emails as $email) {
            try {
                Mail::mailer(config('edm.mailer', 'edm'))->to($email)->send(new CampaignMail(
                    subjectLine: '[Test] '.$this->sample((string) $campaign->subject),
                    htmlBody: $this->sample($rendered['html']),
                    textBody: $this->sample($rendered['text']),
                    unsubscribeUrl: url('/settings/notifications'),
                    fromAddress: (string) config('edm.from.address'),
                    fromName: CampaignSender::senderName($campaign),
                    replyToAddress: Settings::get('reply_to') ?: null,
                    campaignTag: 'test-a'.$automation->id,
                ));
            } catch (\Throwable $e) {
                report($e);

                throw ValidationException::withMessages(['emails' => 'The mail server refused the test: '.mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return back()->with('flash_success', 'Test sent to '.$emails->implode(', ').' with sample event details.');
    }

    // ---- helpers -----------------------------------------------------------------

    protected function own(EdmAutomation $automation): void
    {
        $scope = $this->scopeId();

        abort_unless($scope === null ? $automation->organizer_id === null : (int) $automation->organizer_id === $scope, 404);
    }

    protected function ownStep(EdmAutomation $automation, EdmAutomationStep $step): void
    {
        $this->own($automation);
        abort_unless((int) $step->automation_id === (int) $automation->id, 404);
    }

    /** Fill the tokens with sample values for previews and tests. */
    private function sample(string $content): string
    {
        $name = request()->user()?->name;
        $first = $name ? strtok(trim($name), ' ') : 'there';
        $content = preg_replace('/\{\{click:\d+\}\}/', '#', $content) ?? $content;

        $values = ['first_name' => $first ?: 'there', 'name' => $name ?: 'there', 'email' => request()->user()?->email ?? 'reader@example.com',
            'unsubscribe_url' => '#', 'subscribe_url' => '#', 'view_url' => '#'] + Automations::TOKENS;

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $values) ? e($values[$m[1]]) : $m[0], $content) ?? $content;
    }

    public const TRIGGER_HELP = [
        'event_reminder' => 'Reminds ticket holders before an event: by default 3 days and 1 day before. Account holders who turned off event reminders are skipped.',
        'post_event' => 'Follows up after an event: a thank-you with a review request for those who checked in, then what is on next (subscribers only).',
        'abandoned_checkout' => 'Emails people who filled in checkout but did not pay: an hour later, then a day later. Stops the moment they buy.',
        'welcome' => 'Greets people who join your list: a welcome straight away, then a few picks three days later.',
    ];

    private const ANCHOR = [
        'event_reminder' => 'the event starts',
        'post_event' => 'the event ends',
        'abandoned_checkout' => 'they left checkout',
        'welcome' => 'they subscribed',
    ];
}
