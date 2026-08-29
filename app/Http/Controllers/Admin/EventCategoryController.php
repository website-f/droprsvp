<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventCategory;
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
                    'sort_order' => $c->sort_order,
                    'events_count' => $c->events_count,
                ]),
        ]);
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

    public function update(Request $request, EventCategory $category)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['nullable', 'string', 'max:60', Rule::unique('event_categories', 'slug')->ignore($category->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $category->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['slug'] ?? $data['name']) ?: $category->slug,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
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
