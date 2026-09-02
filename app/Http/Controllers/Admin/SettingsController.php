<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SiteContent;
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
                'announcement' => SiteContent::announcement(),
                'trending_keywords' => (string) Setting::get('trending_keywords', ''),
            ],
        ]);
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
            'announcement.active' => ['boolean'],
            'announcement.style' => ['nullable', 'in:banner,modal'],
            'announcement.level' => ['nullable', 'in:info,success,warning'],
            'announcement.title' => ['nullable', 'string', 'max:120'],
            'announcement.body' => ['nullable', 'string', 'max:500'],
            'announcement.cta_label' => ['nullable', 'string', 'max:40'],
            'announcement.cta_url' => ['nullable', 'string', 'max:512'],
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

        // Site-wide announcement (landing banner / modal). Bump version so an edit
        // re-shows for users who already dismissed the previous one.
        Setting::putArray('announcement', [
            'active' => $request->boolean('announcement.active'),
            'style' => in_array($request->input('announcement.style'), ['banner', 'modal'], true) ? $request->input('announcement.style') : 'banner',
            'level' => in_array($request->input('announcement.level'), ['info', 'success', 'warning'], true) ? $request->input('announcement.level') : 'info',
            'title' => (string) $request->input('announcement.title', ''),
            'body' => (string) $request->input('announcement.body', ''),
            'cta_label' => (string) $request->input('announcement.cta_label', ''),
            'cta_url' => (string) $request->input('announcement.cta_url', ''),
            'version' => SiteContent::announcement()['version'] + 1,
        ]);
        SiteContent::forgetAnnouncement();

        return back()->with('flash_success', 'Settings saved.');
    }
}
