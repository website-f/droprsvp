<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Services\Edm\CampaignSender;
use App\Support\Dates;
use App\Support\Edm\Audience;
use App\Support\Edm\Renderer;
use App\Support\Edm\Settings;
use App\Support\Edm\StarterTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * The saved-template library: reusable email designs, separate from
 * campaigns. A campaign is one send; a template is where many start.
 *
 * Built-in starters (StarterTemplates) sit beside the saved ones. They are
 * read-only; "Customise" copies one into the library.
 */
class EdmTemplateController extends Controller
{
    /** Whose library this is: null = DropRSVP's own; the host panel overrides it. */
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
        return Inertia::render($this->page('templates/index'), [
            'base' => $this->base(),
            'templates' => EmailTemplate::query()
                ->where('organizer_id', $this->scopeId())
                ->with('creator:id,name')
                ->latest('updated_at')
                ->get()
                ->map(fn (EmailTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'subject' => $t->subject,
                    'blocks' => count((array) ($t->design['content'] ?? [])),
                    'updated_at' => Dates::display($t->updated_at, 'j M Y'),
                    'creator' => $t->creator?->name,
                ]),
            'starters' => collect(StarterTemplates::all())->map(fn ($s) => [
                'key' => $s['key'],
                'name' => $s['name'],
                'description' => $s['description'],
                'subject' => $s['subject'],
                'blocks' => count($s['design']['content']),
            ])->values(),
        ]);
    }

    /** New template: blank, a copy of a starter, or a campaign saved as a template. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'starter' => ['nullable', 'string', 'max:40'],
            'campaign_id' => ['nullable', 'integer'],
        ]);

        $design = EdmCampaignController::starterDesign();
        $subject = null;
        $preheader = null;

        if (! empty($data['starter']) && ($starter = StarterTemplates::find($data['starter']))) {
            [$design, $subject, $preheader] = [$starter['design'], $starter['subject'], $starter['preheader']];
        }

        if (! empty($data['campaign_id'])) {
            $campaign = EmailCampaign::where('organizer_id', $this->scopeId())->findOrFail($data['campaign_id']);
            [$design, $subject, $preheader] = [$campaign->design ?: $design, $campaign->subject, $campaign->preheader];
        }

        $template = EmailTemplate::create([
            'organizer_id' => $this->scopeId(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'subject' => $subject,
            'preheader' => $preheader,
            'design' => $design,
            'created_by' => $request->user()->id,
        ]);

        // Saved from a campaign: stay there. Otherwise straight into the builder.
        return ! empty($data['campaign_id'])
            ? back()->with('flash_success', "Saved as template “{$template->name}”.")
            : redirect($this->base().'/templates/'.$template->id.'/editor');
    }

    public function update(Request $request, EmailTemplate $template): RedirectResponse
    {
        $this->own($template);

        $template->update($request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
        ]));

        return back()->with('flash_success', 'Template saved.');
    }

    public function editor(EmailTemplate $template)
    {
        $this->own($template);

        return Inertia::render('admin/edm/campaigns/editor', [
            'campaign' => ['id' => $template->id, 'name' => $template->name, 'editable' => true],
            'design' => $template->design ?: EdmCampaignController::starterDesign(),
            'events' => EdmCampaignController::eventChoices($this->scopeId()),
            'saveUrl' => $this->base().'/templates/'.$template->id.'/design',
            'backUrl' => $this->base().'/templates',
            'backLabel' => 'Templates',
            'kind' => 'template',
        ]);
    }

    public function saveDesign(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->own($template);

        $data = $request->validate([
            'design' => ['required', 'array'],
            'design.content' => ['present', 'array', 'max:80'],
            'design.root' => ['nullable', 'array'],
        ]);

        $template->update(['design' => [
            'root' => $data['design']['root'] ?? ['props' => []],
            'content' => array_values($data['design']['content']),
        ]]);

        return response()->json(['ok' => true]);
    }

    /** The template rendered as an email, for the gallery thumbnails and preview. */
    public function preview(EmailTemplate $template): Response
    {
        $this->own($template);

        return $this->html($template->design, (string) $template->subject, $template->preheader);
    }

    public function starterPreview(string $key): Response
    {
        $starter = StarterTemplates::find($key);
        abort_unless($starter, 404);

        return $this->html($starter['design'], $starter['subject'], $starter['preheader']);
    }

    /** Start a campaign from a saved template or a starter. */
    public function use(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'starter' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:160'],
        ]);

        if (! empty($data['template_id'])) {
            $t = EmailTemplate::where('organizer_id', $this->scopeId())->findOrFail($data['template_id']);
            $source = ['name' => $t->name, 'subject' => $t->subject, 'preheader' => $t->preheader, 'design' => $t->design];
        } else {
            $source = StarterTemplates::find((string) ($data['starter'] ?? '')) ?? abort(404);
        }

        $campaign = EmailCampaign::create([
            'organizer_id' => $this->scopeId(),
            'name' => $data['name'] ?: $source['name'],
            'subject' => (string) ($source['subject'] ?? ''),
            'preheader' => $source['preheader'] ?? null,
            'from_name' => $this->scopeId() === null ? Settings::get('from_name') : null,
            'design' => $source['design'],
            'audience' => Audience::normalise([]),
            'created_by' => $request->user()->id,
        ]);

        return redirect($this->base().'/campaigns/'.$campaign->id)->with('flash_success', 'Campaign created from the template. Pick the events and audience, then send a test.');
    }

    public function duplicate(Request $request, EmailTemplate $template): RedirectResponse
    {
        $this->own($template);

        EmailTemplate::create([
            'organizer_id' => $template->organizer_id,
            'name' => mb_substr($template->name.' (copy)', 0, 160),
            'description' => $template->description,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'design' => $template->design,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('flash_success', 'Template copied.');
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        $this->own($template);
        $template->delete();

        // Campaigns made from it keep their own copy of the design.
        return redirect($this->base().'/templates')->with('flash_success', 'Template deleted. Campaigns made from it are unaffected.');
    }

    private function own(EmailTemplate $template): void
    {
        $scope = $this->scopeId();

        abort_unless($scope === null ? $template->organizer_id === null : (int) $template->organizer_id === $scope, 404);
    }

    private function html(array $design, string $subject, ?string $preheader): Response
    {
        $html = Renderer::render($design, CampaignSender::renderContext(new EmailCampaign([
            'organizer_id' => $this->scopeId(),
            'subject' => $subject,
            'preheader' => $preheader,
        ])))['html'];

        $name = request()->user()?->name;
        $first = $name ? strtok(trim($name), ' ') : 'there';
        $html = preg_replace('/\{\{click:\d+\}\}/', '#', $html) ?? $html;
        $html = strtr($html, [
            '{{first_name}}' => e($first ?: 'there'),
            '{{name}}' => e($name ?: 'there'),
            '{{email}}' => e(request()->user()?->email ?? 'reader@example.com'),
            '{{unsubscribe_url}}' => '#',
            '{{subscribe_url}}' => '#',
            '{{view_url}}' => '#',
        ]);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "script-src 'none'; frame-ancestors 'self'",
        ]);
    }
}
