<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\MenuItem;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index()
    {
        $items = MenuItem::where('location', 'header')
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'label', 'url', 'new_tab', 'sort']);

        // Published pages the admin can add to the menu in one click.
        $pages = CmsPage::query()
            ->where('status', 'published')
            ->orderBy('title')
            ->get(['title', 'slug'])
            ->map(fn ($p) => ['label' => $p->title, 'url' => '/'.$p->slug])
            ->all();

        // Handy built-in destinations.
        $builtins = [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Events', 'url' => '/events'],
            ['label' => 'Blog', 'url' => '/blog'],
        ];

        return inertia('admin/cms/menu/index', [
            'items' => $items,
            'pages' => $pages,
            'builtins' => $builtins,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2048'],
            'new_tab' => ['boolean'],
        ]);

        MenuItem::create([
            'location' => 'header',
            'label' => $data['label'],
            'url' => $data['url'],
            'new_tab' => $data['new_tab'] ?? false,
            'sort' => (int) MenuItem::where('location', 'header')->max('sort') + 1,
        ]);

        return back()->with('success', 'Menu item added.');
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2048'],
            'new_tab' => ['boolean'],
        ]);

        $menuItem->update($data);

        return back()->with('success', 'Menu item updated.');
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();

        return back()->with('success', 'Menu item removed.');
    }

    /** Persist a new order (array of ids, top-to-bottom). */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:menu_items,id'],
        ]);

        foreach ($data['ids'] as $i => $id) {
            MenuItem::where('id', $id)->update(['sort' => $i]);
        }

        return back()->with('success', 'Menu order saved.');
    }
}
