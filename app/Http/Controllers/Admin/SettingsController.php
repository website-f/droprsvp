<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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
                'trending_keywords' => (string) Setting::get('trending_keywords', ''),
            ],
        ]);
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

        return back()->with('flash_success', 'Settings saved.');
    }
}
