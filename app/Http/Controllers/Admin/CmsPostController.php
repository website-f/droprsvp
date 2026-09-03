<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsCategory;
use App\Models\CmsPost;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CmsPostController extends Controller
{
    public function index()
    {
        return inertia('admin/cms/posts/index', [
            'posts' => CmsPost::with('category:id,name')->latest()->get()->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'slug' => $p->slug, 'status' => $p->status,
                'category' => $p->category?->name, 'updated_at' => $p->updated_at->format('j M Y'),
            ]),
        ]);
    }

    public function create()
    {
        return inertia('admin/cms/posts/form', ['post' => null, 'categories' => $this->categories()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $post = new CmsPost($this->attributes($data));
        $post->author_id = $request->user()->id;
        $post->category_id = $this->resolveCategory($data['category'] ?? null);
        $post->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], null);
        $post->save();
        $post->seo()->create($this->seoAttributes($data));

        return redirect()->route('admin.cms.posts.index')->with('success', 'Post created.');
    }

    public function edit(CmsPost $post)
    {
        $post->load(['seo', 'category']);

        return inertia('admin/cms/posts/form', ['post' => $this->formPayload($post), 'categories' => $this->categories()]);
    }

    public function update(Request $request, CmsPost $post)
    {
        $data = $this->validated($request, $post->id);

        $post->fill($this->attributes($data));
        $post->category_id = $this->resolveCategory($data['category'] ?? null);
        $post->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], $post->id);
        $post->save();
        $post->seo()->updateOrCreate([], $this->seoAttributes($data));

        return redirect()->route('admin.cms.posts.index')->with('success', 'Post updated.');
    }

    public function destroy(CmsPost $post)
    {
        $post->delete();

        return redirect()->route('admin.cms.posts.index')->with('success', 'Post deleted.');
    }

    // ---- helpers -----------------------------------------------------------

    private function validated(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('cms_posts', 'slug')->ignore($ignoreId)],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'string', 'max:2048'],
            'category' => ['nullable', 'string', 'max:80'],
            'publish' => ['boolean'],
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

    private function attributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'excerpt' => $data['excerpt'] ?? null,
            'body' => \App\Support\HtmlSanitizer::clean($data['body'] ?? null),
            'cover_image' => $data['cover_image'] ?? null,
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
            'meta_keywords' => $seo['meta_keywords'] ?? null,
            'canonical_url' => $seo['canonical_url'] ?? null,
            'robots_index' => $seo['robots_index'] ?? true,
            'robots_follow' => $seo['robots_follow'] ?? true,
            'og_title' => $seo['og_title'] ?? null,
            'og_description' => $seo['og_description'] ?? null,
            'og_image' => $seo['og_image'] ?? null,
        ];
    }

    private function resolveCategory(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return CmsCategory::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id;
    }

    private function uniqueSlug(string $from, ?int $ignoreId): string
    {
        $base = Str::slug($from) ?: 'post';
        $slug = $base;
        $i = 2;
        while (CmsPost::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function categories(): array
    {
        return CmsCategory::orderBy('name')->pluck('name')->all();
    }

    private function formPayload(CmsPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'cover_image' => $post->cover_image,
            'category' => $post->category?->name,
            'status' => $post->status,
            'seo' => [
                'seo_title' => $post->seo?->seo_title,
                'meta_description' => $post->seo?->meta_description,
                'focus_keyphrase' => $post->seo?->focus_keyphrase,
                'meta_keywords' => $post->seo?->meta_keywords,
                'canonical_url' => $post->seo?->canonical_url,
                'robots_index' => $post->seo?->robots_index ?? true,
                'robots_follow' => $post->seo?->robots_follow ?? true,
                'og_title' => $post->seo?->og_title,
                'og_description' => $post->seo?->og_description,
                'og_image' => $post->seo?->og_image,
            ],
        ];
    }
}
