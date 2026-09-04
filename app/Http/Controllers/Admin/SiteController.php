<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Cities;
use App\Support\HtmlSanitizer;
use App\Support\ReceiptTemplate;
use App\Support\SiteContent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function landing()
    {
        return inertia('admin/site/landing', [
            'sections' => SiteContent::landing(),
            'cities' => Cities::all(),
        ]);
    }

    public function saveLanding(Request $request)
    {
        $data = $request->validate([
            'hero' => ['array'],
            'hero.style' => ['nullable', 'in:classic,banners'],
            'hero.autoplay' => ['boolean'],
            'hero.interval' => ['nullable', 'integer', 'min:2', 'max:15'],
            'hero.banners' => ['array', 'max:8'],
            'hero.banners.*.image' => ['nullable', 'string', 'max:2048'],
            'hero.banners.*.heading' => ['nullable', 'string', 'max:120'],
            'hero.banners.*.subheading' => ['nullable', 'string', 'max:200'],
            'hero.banners.*.cta_label' => ['nullable', 'string', 'max:60'],
            'hero.banners.*.cta_url' => ['nullable', 'string', 'max:2048'],
            'hero.banners.*.align' => ['nullable', 'in:left,center,right'],
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
            'featured_organizers' => ['array'],
            'featured_organizers.enabled' => ['boolean'],
            'featured_organizers.heading' => ['nullable', 'string', 'max:120'],
            'featured_organizers.subheading' => ['nullable', 'string', 'max:200'],
            'contact' => ['array'],
            'contact.enabled' => ['boolean'],
            'contact.heading' => ['nullable', 'string', 'max:120'],
            'contact.subheading' => ['nullable', 'string', 'max:300'],
            'showcase' => ['array'],
            'showcase.enabled' => ['boolean'],
            'seo_text' => ['array'],
            'seo_text.enabled' => ['boolean'],
            'seo_text.heading' => ['nullable', 'string', 'max:150'],
            'seo_text.body' => ['nullable', 'string', 'max:20000'],
        ]);

        // The SEO text block is authored on its own page — keep the stored value as the
        // source of truth so saving other landing sections never clobbers it.
        $data['seo_text'] = SiteContent::landing()['seo_text'];

        Setting::putArray('landing_sections', $data);

        return back()->with('success', 'Landing sections saved.');
    }

    public function homeSeo()
    {
        return inertia('admin/site/home-seo', ['seo' => SiteContent::homeSeo()]);
    }

    public function saveHomeSeo(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:320'],
            'keywords' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::putArray('home_seo', $data);

        return back()->with('success', 'Homepage SEO saved.');
    }

    /** Dedicated rich-text editor for the home-page SEO text block. */
    public function seoText()
    {
        $seo = SiteContent::landing()['seo_text'] ?? ['enabled' => false, 'heading' => '', 'body' => ''];

        return inertia('admin/site/seo-text', ['seo' => $seo]);
    }

    public function saveSeoText(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'heading' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        // Merge into the existing landing sections (body is admin-authored HTML → sanitize).
        $sections = SiteContent::landing();
        $sections['seo_text'] = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'heading' => $data['heading'] ?? '',
            'body' => HtmlSanitizer::clean($data['body'] ?? '') ?? '',
        ];
        Setting::putArray('landing_sections', $sections);

        return back()->with('success', 'SEO text block saved.');
    }

    /** The /en-my/all events-page hero banner + foot-of-page SEO text block. */
    public function eventsPage()
    {
        return inertia('admin/site/events-page', ['data' => SiteContent::eventsPage()]);
    }

    public function saveEventsPage(Request $request)
    {
        $data = $request->validate([
            'hero' => ['array'],
            'hero.enabled' => ['boolean'],
            'hero.heading' => ['nullable', 'string', 'max:120'],
            'hero.subheading' => ['nullable', 'string', 'max:200'],
            'hero.image' => ['nullable', 'string', 'max:2048'],
            'hero.cta_label' => ['nullable', 'string', 'max:60'],
            'hero.cta_url' => ['nullable', 'string', 'max:2048'],
            'hero.align' => ['nullable', 'in:left,center,right'],
            'seo_text' => ['array'],
            'seo_text.enabled' => ['boolean'],
            'seo_text.heading' => ['nullable', 'string', 'max:150'],
            'seo_text.body' => ['nullable', 'string', 'max:20000'],
        ]);

        // The SEO body is admin-authored HTML → sanitize before storing.
        $data['seo_text']['body'] = HtmlSanitizer::clean($data['seo_text']['body'] ?? '') ?? '';
        Setting::putArray('events_page', $data);

        return back()->with('success', 'Events page saved.');
    }

    public function branding()
    {
        return inertia('admin/site/branding', ['branding' => SiteContent::branding()]);
    }

    public function saveBranding(Request $request)
    {
        $data = $request->validate([
            'logo_full' => ['nullable', 'string', 'max:2048'],
            'logo_mark' => ['nullable', 'string', 'max:2048'],
            'header_height' => ['required', 'integer', 'min:20', 'max:96'],
            'sidebar_height' => ['required', 'integer', 'min:20', 'max:80'],
            'footer_height' => ['required', 'integer', 'min:16', 'max:80'],
            'auth_height' => ['required', 'integer', 'min:16', 'max:72'],
            'invert_dark' => ['boolean'],
        ]);

        Setting::putArray('branding', $data);
        SiteContent::forgetBranding();

        return back()->with('success', 'Branding saved.');
    }

    public function footer()
    {
        return inertia('admin/site/footer', ['data' => SiteContent::footer()]);
    }

    public function saveFooter(Request $request)
    {
        $data = $request->validate([
            'data' => ['required', 'array'],
            'data.content' => ['array'],
            'data.root' => ['array'],
        ]);

        Setting::putArray('footer', $data['data']);
        SiteContent::forgetFooter();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Footer saved.');
    }

    /** The receipt / invoice template editor — branding, content and layout. */
    public function receipt()
    {
        return inertia('admin/site/receipt', ['template' => ReceiptTemplate::get()]);
    }

    public function saveReceipt(Request $request)
    {
        $data = $request->validate([
            'accent' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'show_logo' => ['boolean'],
            'logo_align' => ['nullable', 'in:left,right'],
            'title' => ['nullable', 'string', 'max:60'],
            'header_note' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'footer_note' => ['nullable', 'string', 'max:120'],
            'show_status' => ['boolean'],
            'show_context' => ['boolean'],
            'show_seller_detail' => ['boolean'],
            'show_tax' => ['boolean'],
        ]);

        ReceiptTemplate::save($data);

        return back()->with('success', 'Receipt template saved.');
    }

    /** A sample receipt rendered with the current saved template, for previewing the PDF. */
    public function receiptPreview()
    {
        $receipt = [
            'seller' => ['name' => config('app.name', 'DropRSVP'), 'detail' => 'Sample organizer · Kuala Lumpur'],
            'title' => 'Receipt', 'number' => 'DRSVP-SAMPLE', 'date' => now()->format('j M Y'), 'status' => 'paid',
            'party_label' => 'Billed to', 'party' => ['name' => 'Jane Doe', 'detail' => 'jane@example.com'],
            'context' => 'Sample Event 2026',
            'items' => [
                ['description' => 'General Admission', 'qty' => 2, 'unit' => 50.0, 'total' => 100.0],
                ['description' => 'VIP Table', 'qty' => 1, 'unit' => 150.0, 'total' => 150.0],
            ],
            'currency' => 'MYR', 'subtotal' => 250.0, 'tax' => 15.0, 'total' => 265.0,
        ];

        return Pdf::loadView('receipts.pdf', ['receipt' => $receipt, 'style' => ReceiptTemplate::resolved()])->stream('receipt-preview.pdf');
    }
}
