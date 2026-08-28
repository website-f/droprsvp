<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SiteContent;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function landing()
    {
        return inertia('admin/site/landing', ['sections' => SiteContent::landing()]);
    }

    public function saveLanding(Request $request)
    {
        $data = $request->validate([
            'organizer' => ['array'],
            'organizer.enabled' => ['boolean'],
            'organizer.heading' => ['nullable', 'string', 'max:120'],
            'organizer.body' => ['nullable', 'string', 'max:600'],
            'organizer.cta_label' => ['nullable', 'string', 'max:60'],
            'organizer.cta_url' => ['nullable', 'string', 'max:2048'],
            'organizer.image' => ['nullable', 'string', 'max:2048'],
            'event_time' => ['array'],
            'event_time.enabled' => ['boolean'],
            'event_time.heading' => ['nullable', 'string', 'max:120'],
            'event_time.items' => ['array'],
            'event_time.items.*.label' => ['nullable', 'string', 'max:40'],
            'event_time.items.*.value' => ['nullable', 'string', 'max:40'],
            'nearby_cities' => ['array'],
            'nearby_cities.enabled' => ['boolean'],
            'nearby_cities.heading' => ['nullable', 'string', 'max:120'],
            'nearby_cities.cities' => ['array'],
            'nearby_cities.cities.*' => ['nullable', 'string', 'max:60'],
        ]);

        Setting::putArray('landing_sections', $data);

        return back()->with('success', 'Landing sections saved.');
    }

    public function footer()
    {
        return inertia('admin/site/footer', ['footer' => SiteContent::footer()]);
    }

    public function saveFooter(Request $request)
    {
        $data = $request->validate([
            'tagline' => ['nullable', 'string', 'max:400'],
            'copyright' => ['nullable', 'string', 'max:200'],
            'columns' => ['array', 'max:4'],
            'columns.*.title' => ['nullable', 'string', 'max:60'],
            'columns.*.links' => ['array', 'max:10'],
            'columns.*.links.*.label' => ['nullable', 'string', 'max:60'],
            'columns.*.links.*.url' => ['nullable', 'string', 'max:2048'],
        ]);

        Setting::putArray('footer', $data);
        SiteContent::forgetFooter();

        return back()->with('success', 'Footer saved.');
    }
}
