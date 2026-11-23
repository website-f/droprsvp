<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Support\Dates;
use App\Support\HtmlSanitizer;
use App\Support\RegionVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HelpController extends Controller
{
    public function index()
    {
        return inertia('admin/cms/help/index', [
            // Main articles; their region versions are tabs inside each.
            'articles' => HelpArticle::main()->with('versions:id,parent_id,regions')->orderBy('category')->orderBy('sort')->get()
                ->map(fn ($a) => [
                    'id' => $a->id, 'slug' => $a->slug, 'title' => $a->title, 'category' => $a->category, 'status' => $a->status,
                    'updated_at' => Dates::display($a->updated_at, 'j M Y'),
                    'regions' => $a->regions ? RegionVersions::names($a->regions) : null,
                    'versions' => $a->versions->map(fn ($v) => RegionVersions::names((array) $v->regions))->values(),
                ]),
        ]);
    }

    public function create()
    {
        return inertia('admin/cms/help/form', ['article' => null, 'categories' => $this->categories(), 'regionOptions' => RegionVersions::options()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $article = new HelpArticle($this->attrs($data));
        $article->regions = $data['regions'] ?? null;
        $article->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], null);
        $article->save();

        return redirect()->route('admin.cms.help.index')->with('success', 'Help article created.');
    }

    public function edit(HelpArticle $help)
    {
        return inertia('admin/cms/help/form', [
            'article' => [
                'id' => $help->id, 'title' => $help->title, 'slug' => $help->slug, 'category' => $help->category, 'excerpt' => $help->excerpt,
                'body' => $help->body, 'status' => $help->status, 'sort' => $help->sort,
                // Region versions: which this is, and the tabs for all of them.
                'parent_id' => $help->parent_id, 'address' => $help->addressSlug(),
                'regions' => array_values((array) $help->regions), 'versions' => RegionVersions::tabs($help),
            ],
            'categories' => $this->categories(),
            'regionOptions' => RegionVersions::options(),
        ]);
    }

    /** A region version of an article: a draft copy for the regions picked. */
    public function addVersion(Request $request, HelpArticle $help)
    {
        abort_if($help->parent_id !== null, 404);
        $data = $request->validate(RegionVersions::rules(required: true));
        RegionVersions::assertFree($help, $data['regions']);

        $version = $help->replicate(['slug', 'regions', 'parent_id', 'status', 'published_at']);
        $version->parent_id = $help->id;
        $version->regions = $data['regions'];
        $version->slug = null;
        $version->status = 'draft';
        $version->save();

        return redirect()->route('admin.cms.help.edit', $version->id)
            ->with('success', 'Version for '.RegionVersions::names($data['regions']).' created as a draft — edit it, then publish.');
    }

    public function update(Request $request, HelpArticle $help)
    {
        $data = $this->validated($request, $help->id);
        $help->fill($this->attrs($data));

        if ($help->parent_id) {
            $regions = $request->validate(RegionVersions::rules(required: true))['regions'];
            RegionVersions::assertFree($help->parent, $regions, $help->id);
            $help->regions = $regions;
        } else {
            $help->regions = $data['regions'] ?? null;
            $help->slug = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title'], $help->id);
        }
        $help->save();

        return $help->parent_id
            ? redirect()->route('admin.cms.help.edit', $help->id)->with('success', 'Version for '.RegionVersions::names((array) $help->regions).' saved.')
            : redirect()->route('admin.cms.help.index')->with('success', 'Help article updated.');
    }

    public function destroy(HelpArticle $help)
    {
        $main = $help->parent_id;
        $help->delete();

        if ($main) {
            return redirect()->route('admin.cms.help.edit', $main)->with('success', 'Version deleted — those regions show the main article again.');
        }

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
            ...RegionVersions::rules(),
        ]);
    }

    private function attrs(array $data): array
    {
        return [
            'title' => $data['title'],
            'category' => $data['category'],
            'excerpt' => $data['excerpt'] ?? null,
            'body' => HtmlSanitizer::clean($data['body'] ?? null),
            'sort' => $data['sort'] ?? 0,
            'status' => ($data['publish'] ?? false) ? 'published' : 'draft',
            'published_at' => ($data['publish'] ?? false) ? now() : null,
        ];
    }

    private function categories(): array
    {
        return HelpArticle::query()->distinct()->orderBy('category')->pluck('category')->filter()->values()->all();
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
