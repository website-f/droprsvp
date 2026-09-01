<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventCategory;
use App\Models\Setting;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EventCategoryController extends Controller
{
    public function index()
    {
        return inertia('admin/categories/index', [
            'categories' => EventCategory::withCount('events')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (EventCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'icon' => $c->icon,
                    'blurb' => $c->blurb,
                    'color' => $c->color,
                    'sort_order' => $c->sort_order,
                    'events_count' => $c->events_count,
                    'content' => $c->content,
                ]),
            'browseSeo' => SiteContent::discoverSeo(),
        ]);
    }

    /** SEO title + description for the /en-my/all browse page. */
    public function saveBrowseSeo(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:320'],
        ]);

        Setting::putArray('discover_seo', $data);

        return back()->with('success', 'Browse page SEO saved.');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        EventCategory::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'sort_order' => (int) EventCategory::max('sort_order') + 1,
        ]);

        return back()->with('success', 'Category added.');
    }

    /** Persist a new category ordering (ids in the desired order). */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        foreach (array_values($data['ids']) as $i => $id) {
            EventCategory::whereKey($id)->update(['sort_order' => $i]);
        }

        return back()->with('success', 'Order updated.');
    }

    public function update(Request $request, EventCategory $category)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['nullable', 'string', 'max:60', Rule::unique('event_categories', 'slug')->ignore($category->id)],
            'icon' => ['nullable', 'string', 'max:40'],
            'blurb' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'content' => ['nullable', 'string', 'max:8000'],
        ]);

        $category->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['slug'] ?? $data['name']) ?: $category->slug,
            'icon' => $data['icon'] ?? null,
            'blurb' => $data['blurb'] ?? null,
            'color' => $data['color'] ?? null,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
            'content' => $data['content'] ?? null,
        ]);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(EventCategory $category)
    {
        // Events keep working — their category_id is set to null (nullOnDelete).
        $category->delete();

        return back()->with('success', 'Category removed.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while (EventCategory::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
