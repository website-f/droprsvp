<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Support\HtmlSanitizer;
use App\Support\LegalDefaults;
use App\Support\Regions;
use App\Support\RegionVersions;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Simple rich-text editor for the Privacy Policy + Terms pages. These are stored
 * as regular CMS pages (so they get SEO + live at /privacy-policy and /terms) but
 * are edited here rather than through the Puck builder — legal copy is long-form
 * text, not a visual layout.
 */
class LegalController extends Controller
{
    public function edit()
    {
        return inertia('admin/site/legal', ['pages' => $this->pages(), 'regionOptions' => RegionVersions::options()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'pages' => ['required', 'array'],
            'pages.*.slug' => ['required', 'string'],
            'pages.*.title' => ['required', 'string', 'max:180'],
            'pages.*.body' => ['nullable', 'string'],
            // Region versions: the Indonesian terms, the Singapore privacy policy…
            'pages.*.versions' => ['array'],
            'pages.*.versions.*.id' => ['nullable', 'integer'],
            'pages.*.versions.*.regions' => ['required', 'array', 'min:1'],
            'pages.*.versions.*.regions.*' => ['string', Rule::in(Regions::codes())],
            'pages.*.versions.*.title' => ['required', 'string', 'max:180'],
            'pages.*.versions.*.body' => ['nullable', 'string'],
        ], [
            'pages.*.versions.*.regions.required' => 'Pick at least one region for each version.',
            'pages.*.versions.*.regions.min' => 'Pick at least one region for each version.',
        ]);

        foreach ($data['pages'] as $i => $row) {
            if (! isset(LegalDefaults::PAGES[$row['slug']])) {
                continue; // only the known legal pages may be edited here
            }

            // A region in two versions of one page would be a coin toss.
            $all = collect($row['versions'] ?? [])->flatMap(fn ($v) => $v['regions']);
            if ($all->count() !== $all->unique()->count()) {
                throw ValidationException::withMessages(["pages.{$i}.versions" => 'A region can only be in one version of '.$row['title'].'.']);
            }

            $page = CmsPage::main()->firstOrNew(['slug' => $row['slug']]);
            $page->title = $row['title'];
            $page->body = HtmlSanitizer::clean($row['body'] ?? '');
            if ($page->status !== 'published') {
                $page->status = 'published';
                $page->published_at = now();
            }
            $page->save();

            $keep = [];
            foreach ($row['versions'] ?? [] as $v) {
                $version = ! empty($v['id']) ? $page->versions()->find($v['id']) : null;
                $version ??= new CmsPage(['parent_id' => $page->id, 'status' => 'published', 'published_at' => now()]);
                $version->fill(['title' => $v['title'], 'body' => HtmlSanitizer::clean($v['body'] ?? ''), 'regions' => $v['regions'], 'parent_id' => $page->id]);
                $version->slug = null;
                $version->status = 'published';
                $version->published_at ??= now();
                $version->save();
                $keep[] = $version->id;
            }

            // Versions removed in the editor: their regions get the main page.
            $page->versions()->whereNotIn('id', $keep)->get()->each->forceDelete();
        }

        return back()->with('success', 'Legal pages saved.');
    }

    /** Ensure both legal pages exist (creating from defaults) and return them. */
    private function pages(): array
    {
        $out = [];
        foreach (LegalDefaults::PAGES as $slug => $title) {
            $page = CmsPage::main()->firstOrCreate(
                ['slug' => $slug],
                ['title' => $title, 'body' => LegalDefaults::body($slug), 'status' => 'published', 'published_at' => now()],
            );
            $out[] = [
                'slug' => $page->slug, 'title' => $page->title, 'body' => $page->body ?? '', 'url' => Url::path($page->slug),
                'versions' => $page->versions()->get()->map(fn (CmsPage $v) => [
                    'id' => $v->id, 'regions' => array_values((array) $v->regions), 'title' => $v->title, 'body' => $v->body ?? '',
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
