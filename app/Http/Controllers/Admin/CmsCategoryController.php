<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Post (blog) categories — managed alongside event categories on the tabbed admin page. */
class CmsCategoryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        CmsCategory::firstOrCreate(['slug' => $this->uniqueSlug($data['name'])], ['name' => $data['name']]);

        return back()->with('success', 'Post category added.');
    }

    public function update(Request $request, CmsCategory $cmsCategory)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['nullable', 'string', 'max:80', Rule::unique('cms_categories', 'slug')->ignore($cmsCategory->id)],
        ]);

        $cmsCategory->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['slug'] ?? $data['name']) ?: $cmsCategory->slug,
        ]);

        return back()->with('success', 'Post category updated.');
    }

    public function destroy(CmsCategory $cmsCategory)
    {
        // Posts keep working — their category_id is set to null (nullOnDelete).
        $cmsCategory->delete();

        return back()->with('success', 'Post category removed.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while (CmsCategory::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
