<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
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

        return redirect()->route('admin.cms.pages.index')->with('success', 'Page updated.');
    }

    public function destroy(CmsPage $page)
    {
        $page->delete();

        return redirect()->route('admin.cms.pages.index')->with('success', 'Page deleted.');
    }

    // ---- helpers -----------------------------------------------------------

    private function validated(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('cms_pages', 'slug')->ignore($ignoreId)],
            'body' => ['nullable', 'string'],
            'publish' => ['boolean'],
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
        return [
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'status' => ($data['publish'] ?? false) ? 'published' : 'draft',
            'published_at' => ($data['publish'] ?? false) ? now() : null,
        ];
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
            'body' => $page->body,
            'status' => $page->status,
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
