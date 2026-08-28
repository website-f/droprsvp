<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CmsPageController extends Controller
{
    public function index()
    {
        return inertia('admin/cms/pages/index', [
            'pages' => CmsPage::latest()->get()->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'slug' => $p->slug, 'status' => $p->status,
                'updated_at' => $p->updated_at->format('j M Y'),
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
        $page->load('seo');

        return inertia('admin/cms/pages/form', ['page' => $this->formPayload($page)]);
    }

    public function update(Request $request, CmsPage $page)
    {
        $data = $this->validated($request, $page->id);

        $page->fill($this->pageAttributes($data));
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
        return inertia('admin/cms/pages/builder', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'data' => $page->puck_data,
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

        $page->update([
            'puck_data' => $data['data'],
            // A plain-text snapshot powers search, excerpts and SEO fallbacks.
            'body' => $this->plainTextFromPuck($data['data']),
            'layout' => null,
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
            // Page content is owned by the Puck builder (saveBuilder); this form
            // only edits metadata, so it never touches puck_data/body.
            'publish' => ['boolean'],
            'add_to_menu' => ['boolean'],
            'seo' => ['array'],
            'seo.seo_title' => ['nullable', 'string', 'max:70'],
            'seo.meta_description' => ['nullable', 'string', 'max:320'],
            'seo.focus_keyphrase' => ['nullable', 'string', 'max:120'],
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
        // Note: puck_data/body are NOT touched here — page content is owned by
        // the Drop Builder (saveBuilder). This form only edits metadata, so
        // saving it never wipes the built content.
        return [
            'title' => $data['title'],
            'status' => ($data['publish'] ?? false) ? 'published' : 'draft',
            'published_at' => ($data['publish'] ?? false) ? now() : null,
        ];
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
            'layout' => $page->layout,
            'status' => $page->status,
            'builder_edited_at' => optional($page->builder_edited_at)->diffForHumans(),
            'in_menu' => MenuItem::where('location', 'header')->where('url', '/'.$page->slug)->exists(),
            'seo' => [
                'seo_title' => $page->seo?->seo_title,
                'meta_description' => $page->seo?->meta_description,
                'focus_keyphrase' => $page->seo?->focus_keyphrase,
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
