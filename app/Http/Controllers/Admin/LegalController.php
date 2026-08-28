<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Support\LegalDefaults;
use Illuminate\Http\Request;

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
        return inertia('admin/site/legal', ['pages' => $this->pages()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'pages' => ['required', 'array'],
            'pages.*.slug' => ['required', 'string'],
            'pages.*.title' => ['required', 'string', 'max:180'],
            'pages.*.body' => ['nullable', 'string'],
        ]);

        foreach ($data['pages'] as $row) {
            if (! isset(LegalDefaults::PAGES[$row['slug']])) {
                continue; // only the known legal pages may be edited here
            }

            $page = CmsPage::firstOrNew(['slug' => $row['slug']]);
            $page->title = $row['title'];
            $page->body = $row['body'] ?? '';
            if ($page->status !== 'published') {
                $page->status = 'published';
                $page->published_at = now();
            }
            $page->save();
        }

        return back()->with('success', 'Legal pages saved.');
    }

    /** Ensure both legal pages exist (creating from defaults) and return them. */
    private function pages(): array
    {
        $out = [];
        foreach (LegalDefaults::PAGES as $slug => $title) {
            $page = CmsPage::firstOrCreate(
                ['slug' => $slug],
                ['title' => $title, 'body' => LegalDefaults::body($slug), 'status' => 'published', 'published_at' => now()],
            );
            $out[] = ['slug' => $page->slug, 'title' => $page->title, 'body' => $page->body ?? '', 'url' => '/'.$page->slug];
        }

        return $out;
    }
}
