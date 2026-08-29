<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Setting;
use App\Support\Cities;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    /**
     * Autocomplete suggestions for the global search box. Combines admin-curated
     * "trending" keywords + system-derived hot categories (both shown with a
     * fire icon) with live type-ahead matches from our own events/categories/cities.
     */
    public function suggest(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%'.$q.'%';
        $has = fn (string $s) => $q === '' || Str::contains(Str::lower($s), Str::lower($q));

        // 🔥 Admin-curated trending keywords.
        $adminHot = collect(preg_split('/,/', (string) Setting::get('trending_keywords', ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($k) => trim($k))->filter()
            ->filter(fn ($k) => $has($k))
            ->map(fn ($k) => ['label' => $k, 'url' => '/en-my/all?q='.urlencode($k), 'source' => 'trending']);

        // 🔥 System hot: categories with the most upcoming published events.
        $topCatIds = Event::published()
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as c')->groupBy('category_id')->orderByDesc('c')->limit(4)->pluck('category_id');
        $systemHot = EventCategory::whereIn('id', $topCatIds)->get(['name', 'slug'])
            ->filter(fn ($c) => $has($c->name))
            ->map(fn ($c) => ['label' => $c->name, 'url' => '/en-my/all/'.$c->slug, 'source' => 'system']);

        $hot = $adminHot->concat($systemHot)->unique('label')->take(6)->values();

        // Live type-ahead matches (only once the user has typed something).
        $events = $q === '' ? collect() : Event::published()->where('title', 'like', $like)->limit(6)->get(['title', 'slug'])
            ->map(fn ($e) => ['label' => $e->title, 'url' => '/en-my/e/'.$e->slug]);
        $categories = $q === '' ? collect() : EventCategory::where('name', 'like', $like)->limit(5)->get(['name', 'slug'])
            ->map(fn ($c) => ['label' => $c->name, 'url' => '/en-my/all/'.$c->slug]);
        $cities = $q === '' ? collect() : collect(Cities::all())
            ->filter(fn ($c) => Str::contains(Str::lower($c['name']), Str::lower($q)))
            ->take(5)->map(fn ($c) => ['label' => $c['name'], 'url' => '/en-my/'.$c['slug']]);

        return response()->json([
            'hot' => $hot,
            'events' => $events->values(),
            'categories' => $categories->values(),
            'cities' => $cities->values(),
        ]);
    }
}
