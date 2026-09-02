<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Central, superadmin-editable platform settings — fees, tax and general config.
 * Everything that used to be a hard-coded constant lives here and is dynamic.
 */
class SettingsController extends Controller
{
    public function index()
    {
        return inertia('admin/settings', [
            'settings' => [
                'fee_percent' => (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent')),
                'boost_price' => (float) Setting::get('boost_price', config('droprsvp.boost_price')),
                'boost_days' => (int) Setting::get('boost_days', config('droprsvp.boost_days')),
                'premium_price' => (float) Setting::get('premium_price', config('droprsvp.premium_price')),
                'premium_days' => (int) Setting::get('premium_days', config('droprsvp.premium_days')),
                'tax_percent' => (float) Setting::get('tax_percent', config('droprsvp.tax_percent')),
                'tax_label' => (string) Setting::get('tax_label', config('droprsvp.tax_label')),
                'tax_inclusive' => (bool) Setting::get('tax_inclusive', false),
                'support_email' => (string) Setting::get('support_email', ''),
                'checkout_required' => self::checkoutRequired(),
                'ticketing_modes' => self::ticketingModes(),
                'receipt_style' => self::receiptStyle(),
                'trending_keywords' => (string) Setting::get('trending_keywords', ''),
            ],
        ]);
    }

    /**
     * Superadmin-editable receipt / invoice template style. Everything the PDF
     * template themes with — accent colour, footer note and an optional logo.
     *
     * @return array{accent: string, footer_note: string, show_logo: bool, logo: string}
     */
    public static function receiptStyle(): array
    {
        $saved = Setting::getArray('receipt_style', []);

        return [
            'accent' => (string) ($saved['accent'] ?? '#27272a'),
            'footer_note' => (string) ($saved['footer_note'] ?? 'powered by DropRSVP'),
            'show_logo' => (bool) ($saved['show_logo'] ?? false),
            'logo' => (string) ($saved['logo'] ?? ''),
        ];
    }

    /** A sample receipt rendered with the CURRENT saved style, so admins can preview the template. */
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

        return Pdf::loadView('receipts.pdf', ['receipt' => $receipt, 'style' => Receipt::style()])->stream('receipt-preview.pdf');
    }

    /**
     * Which ticketing modes organizers may choose when building an event. General
     * admission is the always-available baseline; reserved seating and table
     * management can be switched off platform-wide. Superadmins always see all.
     *
     * @return array{general: bool, reserved: bool, tables: bool}
     */
    public static function ticketingModes(): array
    {
        $defaults = ['general' => true, 'reserved' => true, 'tables' => true];
        $saved = Setting::getArray('ticketing_modes', []);
        $out = [];
        foreach ($defaults as $mode => $default) {
            $out[$mode] = (bool) ($saved[$mode] ?? $default);
        }
        $out['general'] = true; // never disable the baseline

        return $out;
    }

    /** Which checkout buyer fields are required (name + email are always required). */
    public static function checkoutRequired(): array
    {
        $defaults = ['phone' => true, 'gender' => false, 'age_band' => false, 'city' => false, 'source' => false, 'notes' => false];
        $saved = Setting::getArray('checkout_required', []);
        $out = [];
        foreach ($defaults as $field => $default) {
            $out[$field] = (bool) ($saved[$field] ?? $default);
        }

        return $out;
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'boost_price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'boost_days' => ['required', 'integer', 'min:1', 'max:365'],
            'premium_price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'premium_days' => ['required', 'integer', 'min:1', 'max:365'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['nullable', 'string', 'max:20'],
            'tax_inclusive' => ['boolean'],
            'support_email' => ['nullable', 'email', 'max:180'],
            'trending_keywords' => ['nullable', 'string', 'max:1000'],
            'receipt_style.accent' => ['nullable', 'string', 'max:20'],
            'receipt_style.footer_note' => ['nullable', 'string', 'max:120'],
            'receipt_style.show_logo' => ['boolean'],
            'receipt_style.logo' => ['nullable', 'string', 'max:2048'],
        ]);

        Setting::put('platform_fee_percent', $data['fee_percent']);
        Setting::put('boost_price', $data['boost_price']);
        Setting::put('boost_days', $data['boost_days']);
        Setting::put('premium_price', $data['premium_price']);
        Setting::put('premium_days', $data['premium_days']);
        Setting::put('tax_percent', $data['tax_percent']);
        Setting::put('tax_label', $data['tax_label'] ?? 'SST');
        Setting::put('tax_inclusive', $request->boolean('tax_inclusive') ? '1' : '0');
        Setting::put('support_email', $data['support_email'] ?? '');
        Setting::put('trending_keywords', $data['trending_keywords'] ?? '');

        // Per-field checkout requirements.
        $cr = [];
        foreach (['phone', 'gender', 'age_band', 'city', 'source', 'notes'] as $field) {
            $cr[$field] = $request->boolean("checkout_required.{$field}");
        }
        Setting::putArray('checkout_required', $cr);

        // Which ticketing modes organizers may use (general is always on).
        Setting::putArray('ticketing_modes', [
            'general' => true,
            'reserved' => $request->boolean('ticketing_modes.reserved'),
            'tables' => $request->boolean('ticketing_modes.tables'),
        ]);

        // Receipt / invoice template style.
        Setting::putArray('receipt_style', [
            'accent' => $request->input('receipt_style.accent') ?: '#27272a',
            'footer_note' => $request->input('receipt_style.footer_note') ?: 'powered by DropRSVP',
            'show_logo' => $request->boolean('receipt_style.show_logo'),
            'logo' => $request->input('receipt_style.logo') ?: '',
        ]);

        return back()->with('flash_success', 'Settings saved.');
    }
}
