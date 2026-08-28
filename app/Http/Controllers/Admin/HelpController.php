<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HelpController extends Controller
{
    public function index()
    {
        return inertia('admin/cms/help/index', [
            'articles' => HelpArticle::orderBy('category')->orderBy('sort')->get()
                ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'category' => $a->category, 'status' => $a->status, 'updated_at' => $a->updated_at->format('j M Y')]),
        ]);
    }

    public function create()
    {
        return inertia('admin/cms/help/form', ['article' => null, 'categories' => $this->categories()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $article = new HelpArticle($this->attrs($data));
        $article->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], null);
        $article->save();

        return redirect()->route('admin.cms.help.index')->with('success', 'Help article created.');
    }

    public function edit(HelpArticle $help)
    {
        return inertia('admin/cms/help/form', [
            'article' => ['id' => $help->id, 'title' => $help->title, 'slug' => $help->slug, 'category' => $help->category, 'excerpt' => $help->excerpt, 'body' => $help->body, 'status' => $help->status, 'sort' => $help->sort],
            'categories' => $this->categories(),
        ]);
    }

    public function update(Request $request, HelpArticle $help)
    {
        $data = $this->validated($request, $help->id);
        $help->fill($this->attrs($data));
        $help->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], $help->id);
        $help->save();

        return redirect()->route('admin.cms.help.index')->with('success', 'Help article updated.');
    }

    public function destroy(HelpArticle $help)
    {
        $help->delete();

        return redirect()->route('admin.cms.help.index')->with('success', 'Help article deleted.');
    }

    private function validated(Request $request, ?int $ignore): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('help_articles', 'slug')->ignore($ignore)],
            'category' => ['required', 'string', 'max:80'],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string'],
            'sort' => ['nullable', 'integer'],
            'publish' => ['boolean'],
        ]);
    }

    private function attrs(array $data): array
    {
        return [
            'title' => $data['title'],
            'category' => $data['category'],
            'excerpt' => $data['excerpt'] ?? null,
            'body' => $data['body'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'status' => ($data['publish'] ?? false) ? 'published' : 'draft',
            'published_at' => ($data['publish'] ?? false) ? now() : null,
        ];
    }

    private function categories(): array
    {
        return HelpArticle::query()->distinct()->orderBy('category')->pluck('category')->all();
    }

    private function uniqueSlug(string $from, ?int $ignore): string
    {
        $base = Str::slug($from) ?: 'article';
        $slug = $base;
        $i = 2;
        while (HelpArticle::where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
