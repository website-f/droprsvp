<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\MenuItem;
use App\Support\Dates;
use App\Support\HtmlSanitizer;
use App\Support\PageHtml;
use App\Support\PostCards;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CmsPageController extends Controller
{
    /** Pages edited elsewhere (Privacy/Terms → the Legal editor), hidden from the generic list. */
    private const SYSTEM_SLUGS = ['privacy-policy', 'terms'];

    public function index()
    {
        return inertia('admin/cms/pages/index', [
            'pages' => CmsPage::whereNotIn('slug', self::SYSTEM_SLUGS)->latest()->get()->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'slug' => $p->slug, 'status' => $p->status,
                'updated_at' => Dates::display($p->updated_at, 'j M Y'),
            ]),
        ]);
    }

    public function create()
    {
        return inertia('admin/cms/pages/form', ['page' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $page = new CmsPage($this->pageAttributes($data));
        $page->author_id = $request->user()->id;
        $page->html_trusted = $this->trusts($request);
        $page->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], null);
        $page->save();
        $page->seo()->create($this->seoAttributes($data));
        $this->syncMenu($data, $page);

        // "Save & open Drop Builder" from the new-page form.
        if ($request->boolean('open_builder')) {
            return redirect()->route('admin.cms.pages.builder', $page->id);
        }

        return redirect()->route('admin.cms.pages.index')->with('success', 'Page created.');
    }

    public function edit(CmsPage $page)
    {
        if (in_array($page->slug, self::SYSTEM_SLUGS, true)) {
            return redirect()->route('admin.site.legal');
        }

        $page->load('seo');

        return inertia('admin/cms/pages/form', ['page' => $this->formPayload($page)]);
    }

    public function update(Request $request, CmsPage $page)
    {
        $data = $this->validated($request, $page->id);
        $wasHtml = $page->usesHtml();

        $page->fill($this->pageAttributes($data));
        $page->html_trusted = $this->trusts($request);

        // Back to the builder: body is the builder's text snapshot again, not
        // the pasted page's (which stays in `html` in case they switch back).
        if ($wasHtml && ! $page->usesHtml()) {
            $page->body = $this->plainTextFromPuck($page->puck_data ?? []);
        }
        $page->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], $page->id);
        $page->save();
        $page->seo()->updateOrCreate([], $this->seoAttributes($data));
        $this->syncMenu($data, $page);

        return redirect()->route('admin.cms.pages.index')->with('success', 'Page updated.');
    }

    public function destroy(CmsPage $page)
    {
        $page->delete();

        return redirect()->route('admin.cms.pages.index')->with('success', 'Page deleted.');
    }

    /** Full-screen Puck "Drop Builder". */
    public function builder(CmsPage $page)
    {
        if (in_array($page->slug, self::SYSTEM_SLUGS, true)) {
            return redirect()->route('admin.site.legal');
        }

        return inertia('admin/cms/pages/builder', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'data' => $page->puck_data,
                'posts' => PostCards::recent(),
            ],
        ]);
    }

    public function saveBuilder(Request $request, CmsPage $page)
    {
        $data = $request->validate([
            'data' => ['required', 'array'],
            'data.content' => ['array'],
            'data.root' => ['array'],
        ]);

        // Strip active-content (scripts, event handlers, js: URLs) from any HTML
        // an author placed in the builder before it's stored/rendered raw —
        // except the HTML block's code, kept as written like a pasted page and,
        // like one, only ever run inside the sandboxed frame.
        $clean = $this->withHtmlBlocksKept($data['data'], fn (array $tree) => HtmlSanitizer::cleanTree($tree));

        $page->update([
            'puck_data' => $clean,
            // A plain-text snapshot powers search, excerpts and SEO fallbacks.
            'body' => $this->plainTextFromPuck($clean),
            'layout' => null,
            // Saving from the builder means the builder is what this page uses;
            // a pasted HTML version, if any, stays put for switching back.
            'editor' => 'builder',
            'html_trusted' => $this->trusts($request),
            'builder_edited_at' => now(),
        ]);

        // The builder saves via fetch and stays put; only non-JS clients redirect.
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'edited_at' => $page->builder_edited_at->diffForHumans()]);
        }

        return redirect()->route('admin.cms.pages.edit', $page->id)->with('success', 'Saved from the Drop Builder.');
    }

    /** Superadmin preview of a page (even a draft) exactly as visitors see it. */
    public function preview(CmsPage $page)
    {
        return inertia('public/page', [
            'page' => [
                'title' => $page->title,
                'body' => $page->body,
                'layout' => $page->layout,
                'puck' => $page->puck_data,
                'editor' => $page->editor,
                'html' => $page->usesHtml() ? $page->html : null,
                'html_layout' => $page->html_layout,
                'html_trusted' => $page->html_trusted,
                'posts' => PostCards::recent(),
            ],
            'seo' => ['title' => $page->title],
            'preview' => true,
        ]);
    }

    // ---- helpers -----------------------------------------------------------

    private function validated(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('cms_pages', 'slug')->ignore($ignoreId)],
            // Builder content is owned by the Puck builder (saveBuilder) and this
            // form never touches puck_data. HTML mode is edited right here: a
            // whole document pasted in, styles and all.
            'editor' => ['sometimes', 'in:builder,html'],
            'html' => ['nullable', 'string', 'max:'.PageHtml::MAX],
            // Partial HTML in the site's styling, or a full page in its own;
            // empty means work it out from the HTML.
            'html_layout' => ['nullable', 'in:content,full'],
            'publish' => ['boolean'],
            'add_to_menu' => ['boolean'],
            'seo' => ['array'],
            'seo.seo_title' => ['nullable', 'string', 'max:70'],
            'seo.meta_description' => ['nullable', 'string', 'max:320'],
            'seo.focus_keyphrase' => ['nullable', 'string', 'max:120'],
            'seo.meta_keywords' => ['nullable', 'string', 'max:500'],
            'seo.canonical_url' => ['nullable', 'url', 'max:2048'],
            'seo.robots_index' => ['boolean'],
            'seo.robots_follow' => ['boolean'],
            'seo.og_title' => ['nullable', 'string', 'max:120'],
            'seo.og_description' => ['nullable', 'string', 'max:320'],
            'seo.og_image' => ['nullable', 'string', 'max:2048'],
        ]);
    }

    private function pageAttributes(array $data): array
    {
        // Note: puck_data is NOT touched here — builder content is owned by
        // the Drop Builder (saveBuilder), so saving this form never wipes it.
        $attributes = [
            'title' => $data['title'],
            'status' => ($data['publish'] ?? false) ? 'published' : 'draft',
            'published_at' => ($data['publish'] ?? false) ? now() : null,
        ];

        if (isset($data['editor'])) {
            $attributes['editor'] = $data['editor'];
        }

        // A pasted document, stored as written — scripts included, so they can
        // run. That is safe because of where it is shown: every path that puts
        // it in the site's own page strips scripts first, and the only place
        // they run is the sandboxed frame (resources/js/components/
        // sandboxed-html.tsx), which cannot reach the site. Its readable text
        // goes in `body` for crawlers and the meta description, rebuilt
        // without scripts or handlers by PageHtml::content().
        if (($data['editor'] ?? null) === 'html') {
            $html = (string) ($data['html'] ?? '');
            $attributes['html'] = $html;
            $attributes['html_layout'] = $data['html_layout'] ?? null;
            $attributes['body'] = PageHtml::content($html);
        }

        return $attributes;
    }

    /**
     * Whoever saves a page decides whether its scripts may run in the page
     * itself: a superadmin's do, anyone else's stay in the sealed frame. Set on
     * every save, so a page last touched by staff is never trusted on the
     * strength of an earlier superadmin save.
     */
    private function trusts(Request $request): bool
    {
        return (bool) $request->user()?->hasRole('superadmin');
    }

    /**
     * Run $clean over a Puck tree with every HTML block's code set aside, then
     * put that code back as written. Blocks can sit in zones as well as the top
     * level, so the whole tree is walked.
     */
    private function withHtmlBlocksKept(array $tree, callable $clean): array
    {
        $kept = [];

        $stash = function (array $node) use (&$stash, &$kept): array {
            if (($node['type'] ?? null) === 'Html' && is_string($node['props']['html'] ?? null)) {
                $kept[] = $node['props']['html'];
                $node['props']['html'] = '%%HTMLBLOCK'.(count($kept) - 1).'%%';
            }

            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $stash($value);
                }
            }

            return $node;
        };

        $restore = function ($node) use (&$restore, &$kept) {
            if (is_string($node) && preg_match('/^%%HTMLBLOCK(\d+)%%$/', $node, $m)) {
                return $kept[(int) $m[1]] ?? '';
            }

            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    $node[$key] = $restore($value);
                }
            }

            return $node;
        };

        return $restore($clean($stash($tree)));
    }

    /** Flatten a Puck document into readable plain text for search/excerpts/SEO. */
    private function plainTextFromPuck(array $data): string
    {
        // Keys that hold human-readable copy (everything else — ids, urls,
        // icons, alignment flags — is skipped).
        $textKeys = ['title', 'subtitle', 'text', 'label', 'caption', 'q', 'a'];

        $walk = function ($node) use (&$walk, $textKeys): array {
            $out = [];
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    if (is_array($value)) {
                        $out = array_merge($out, $walk($value));
                    } elseif (is_string($value) && in_array($key, $textKeys, true) && trim($value) !== '') {
                        $out[] = trim($value);
                    } elseif ($key === 'html' && is_string($value) && trim($value) !== '') {
                        // An HTML block: its words, not its markup or CSS.
                        $words = trim((string) preg_replace('/\s+/', ' ', strip_tags(
                            (string) preg_replace('#<\s*(style|script|head)\b[^>]*>.*?<\s*/\s*\1\s*>#is', ' ', $value)
                        )));

                        if ($words !== '') {
                            $out[] = $words;
                        }
                    }
                }
            }

            return $out;
        };

        return trim(implode(' ', $walk($data['content'] ?? [])));
    }

    /** When "add to menu" is ticked on a published page, ensure a header link exists. */
    private function syncMenu(array $data, CmsPage $page): void
    {
        if (! ($data['publish'] ?? false) || ! ($data['add_to_menu'] ?? false)) {
            return;
        }

        MenuItem::firstOrCreate(
            ['location' => 'header', 'url' => '/'.$page->slug],
            ['label' => $page->title, 'sort' => (int) MenuItem::where('location', 'header')->max('sort') + 1],
        );
    }

    private function seoAttributes(array $data): array
    {
        $seo = $data['seo'] ?? [];

        return [
            'seo_title' => $seo['seo_title'] ?? null,
            'meta_description' => $seo['meta_description'] ?? null,
            'focus_keyphrase' => $seo['focus_keyphrase'] ?? null,
            'meta_keywords' => $seo['meta_keywords'] ?? null,
            'canonical_url' => $seo['canonical_url'] ?? null,
            'robots_index' => $seo['robots_index'] ?? true,
            'robots_follow' => $seo['robots_follow'] ?? true,
            'og_title' => $seo['og_title'] ?? null,
            'og_description' => $seo['og_description'] ?? null,
            'og_image' => $seo['og_image'] ?? null,
        ];
    }

    private function uniqueSlug(string $from, ?int $ignoreId): string
    {
        $base = Str::slug($from) ?: 'page';
        $slug = $base;
        $i = 2;
        while (CmsPage::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function formPayload(CmsPage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'puck' => $page->puck_data,
            'editor' => $page->editor,
            'html' => $page->html,
            'html_layout' => $page->html_layout,
            'html_trusted' => $page->html_trusted,
            'posts' => PostCards::recent(),
            'layout' => $page->layout,
            'status' => $page->status,
            'builder_edited_at' => optional($page->builder_edited_at)->diffForHumans(),
            'in_menu' => MenuItem::where('location', 'header')->where('url', '/'.$page->slug)->exists(),
            'seo' => [
                'seo_title' => $page->seo?->seo_title,
                'meta_description' => $page->seo?->meta_description,
                'focus_keyphrase' => $page->seo?->focus_keyphrase,
                'meta_keywords' => $page->seo?->meta_keywords,
                'canonical_url' => $page->seo?->canonical_url,
                'robots_index' => $page->seo?->robots_index ?? true,
                'robots_follow' => $page->seo?->robots_follow ?? true,
                'og_title' => $page->seo?->og_title,
                'og_description' => $page->seo?->og_description,
                'og_image' => $page->seo?->og_image,
            ],
        ];
    }
}
