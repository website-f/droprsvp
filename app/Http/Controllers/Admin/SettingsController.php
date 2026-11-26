<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Setting;
use App\Services\Payments\Airwallex;
use App\Services\Payments\AirwallexGateway;
use App\Support\CountryRates;
use App\Support\PlatformFee;
use App\Support\RegionCatalog;
use App\Support\Regions;
use App\Support\RolePermissions;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                'fee_percent' => PlatformFee::percent(),
                'fee_flat' => PlatformFee::flat(),
                'boost_price' => (float) Setting::get('boost_price', config('droprsvp.boost_price')),
                'boost_days' => (int) Setting::get('boost_days', config('droprsvp.boost_days')),
                'premium_price' => (float) Setting::get('premium_price', config('droprsvp.premium_price')),
                'premium_days' => (int) Setting::get('premium_days', config('droprsvp.premium_days')),
                'tax_percent' => (float) Setting::get('tax_percent', config('droprsvp.tax_percent')),
                'tax_label' => (string) Setting::get('tax_label', config('droprsvp.tax_label')),
                'tax_inclusive' => (bool) Setting::get('tax_inclusive', false),
                'support_email' => (string) Setting::get('support_email', ''),
                'ticketing_modes' => self::ticketingModes(),
                'announcement' => SiteContent::announcement(),
                'trending_keywords' => (string) Setting::get('trending_keywords', ''),
                'organizer_auto_approve' => (bool) Setting::get('organizer_auto_approve', false),
            ],
            // Regions: what is on, the default, every country that could be,
            // and how many events each region already has.
            'regions' => [
                'enabled' => array_column(Regions::enabled(), 'country'),
                'default' => Regions::default()['country'],
            ],
            'regionCatalog' => collect(RegionCatalog::COUNTRIES)->map(fn ($c, $code) => [
                'country' => $code, 'name' => $c[0], 'currency' => $c[1], 'timezone' => $c[2], 'area' => $c[3], 'flag' => Regions::flag($code),
            ])->values(),
            'regionAreas' => RegionCatalog::AREAS,
            'regionEvents' => Event::query()->selectRaw('region, count(*) as n')->groupBy('region')->pluck('n', 'region'),
            // Fees, tax and currency per country, on top of the defaults above.
            'countryRates' => CountryRates::raw(),
            // Airwallex (payments outside Malaysia). Secrets never leave the server.
            'airwallex' => Airwallex::forForm(),
            'airwallexWebhook' => route('webhooks.airwallex'),
            // Role → admin-section permission matrix (superadmin edits this).
            'rolePermissions' => RolePermissions::matrix(),
            'permissionSections' => RolePermissions::sectionList(),
        ]);
    }

    /**
     * Save which regions the site runs in, and the default one. At least one
     * must stay on, and the default must be one of them. Turning a region off
     * does not touch its events: their pages move to the default region's
     * address, and turning it back on restores them.
     */
    public function saveRegions(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'array', 'min:1', 'max:300'],
            'enabled.*' => ['string', 'size:2', Rule::in(array_keys(RegionCatalog::COUNTRIES))],
            'default' => ['required', 'string', 'size:2', Rule::in((array) $request->input('enabled', []))],
        ], [
            'enabled.required' => 'Keep at least one region on.',
            'enabled.min' => 'Keep at least one region on.',
            'default.in' => 'The default region has to be one that is on.',
        ]);

        Setting::putArray('regions', [
            'enabled' => array_values(array_unique(array_map('strtoupper', $data['enabled']))),
            'default' => strtoupper($data['default']),
        ]);
        Regions::forget();

        return back()->with('flash_success', count($data['enabled']).' region'.(count($data['enabled']) === 1 ? '' : 's').' on · '.Regions::default()['name'].' is the default.');
    }

    /**
     * Fees, tax and currency per country: one set for "all other countries"
     * and any number of countries of their own (added one by one, or the same
     * rates applied to several at once). Blank fields inherit.
     */
    public function saveCountryRates(Request $request)
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);

        $rate = fn (string $p) => [
            "{$p}.fee_percent" => ['nullable', 'numeric', 'min:0', 'max:100'],
            "{$p}.fee_flat" => ['nullable', 'numeric', 'min:0', 'max:100000'],
            "{$p}.tax_percent" => ['nullable', 'numeric', 'min:0', 'max:100'],
            "{$p}.tax_label" => ['nullable', 'string', 'max:20'],
            "{$p}.currency" => ['nullable', 'string', 'size:3', 'alpha'],
            "{$p}.gateway" => ['nullable', 'in:auto,chip,airwallex'],
        ];

        $data = $request->validate([
            'foreign' => ['array'],
            ...$rate('foreign'),
            'countries' => ['array', 'max:300'],
            'countries.*' => ['array'],
            ...$rate('countries.*'),
        ]);

        $unknown = array_diff(array_map('strtoupper', array_keys($data['countries'] ?? [])), array_keys(RegionCatalog::COUNTRIES));
        if ($unknown) {
            throw ValidationException::withMessages(['countries' => 'Unknown country: '.implode(', ', $unknown).'.']);
        }
        if (isset(($data['countries'] ?? [])[CountryRates::HOME]['gateway']) && $data['countries'][CountryRates::HOME]['gateway'] === 'airwallex') {
            throw ValidationException::withMessages(['countries' => 'Malaysia always pays through CHIP.']);
        }

        CountryRates::save($data);

        return back()->with('flash_success', 'Country rates saved.');
    }

    /** Airwallex credentials (encrypted at rest; blank secrets keep the saved ones). */
    public function saveAirwallex(Request $request)
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);

        $data = $request->validate([
            'enabled' => ['boolean'],
            'env' => ['required', 'in:sandbox,production'],
            'client_id' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:500'],
            'api_version' => ['nullable', 'string', 'max:20', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'clear_api_key' => ['boolean'],
            'clear_webhook_secret' => ['boolean'],
        ]);

        Airwallex::save($data);
        CountryRates::forget();

        if ($request->boolean('enabled') && ! Airwallex::configured()) {
            return back()->with('flash_warning', 'Saved, but Airwallex stays off until the client ID, API key and account ID are all filled in.');
        }

        return back()->with('flash_success', Airwallex::configured()
            ? 'Airwallex saved — events outside Malaysia now take payment through it.'
            : 'Airwallex settings saved.');
    }

    /** Sign in to Airwallex with the saved keys, to check them. */
    public function testAirwallex(Request $request, AirwallexGateway $gateway)
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);

        $c = Airwallex::config();
        if ($c['client_id'] === '' || $c['api_key'] === '') {
            return back()->with('flash_error', 'Save a client ID and API key first.');
        }

        $result = $gateway->test();

        return back()->with($result['ok'] ? 'flash_success' : 'flash_error', $result['message']);
    }

    /** Save the role → section permission matrix. Superadmin only. */
    public function savePermissions(Request $request)
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.staff' => ['array'],
            'permissions.staff.*' => ['string'],
        ]);

        RolePermissions::save($data['permissions'] ?? []);

        return back()->with('flash_success', 'User permissions updated.');
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

    public function update(Request $request)
    {
        $data = $request->validate([
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'fee_flat' => ['required', 'numeric', 'min:0', 'max:100000'],
            'boost_price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'boost_days' => ['required', 'integer', 'min:1', 'max:365'],
            'premium_price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'premium_days' => ['required', 'integer', 'min:1', 'max:365'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['nullable', 'string', 'max:20'],
            'tax_inclusive' => ['boolean'],
            'support_email' => ['nullable', 'email', 'max:180'],
            'trending_keywords' => ['nullable', 'string', 'max:1000'],
            'organizer_auto_approve' => ['boolean'],
            'announcement.active' => ['boolean'],
            'announcement.style' => ['nullable', 'in:banner,modal'],
            'announcement.level' => ['nullable', 'in:info,success,warning'],
            'announcement.title' => ['nullable', 'string', 'max:120'],
            'announcement.body' => ['nullable', 'string', 'max:500'],
            'announcement.cta_label' => ['nullable', 'string', 'max:40'],
            'announcement.cta_url' => ['nullable', 'string', 'max:512'],
        ]);

        Setting::put('platform_fee_percent', $data['fee_percent']);
        Setting::put('platform_fee_flat', $data['fee_flat']);
        Setting::put('boost_price', $data['boost_price']);
        Setting::put('boost_days', $data['boost_days']);
        Setting::put('premium_price', $data['premium_price']);
        Setting::put('premium_days', $data['premium_days']);
        Setting::put('tax_percent', $data['tax_percent']);
        Setting::put('tax_label', $data['tax_label'] ?? 'SST');
        Setting::put('tax_inclusive', $request->boolean('tax_inclusive') ? '1' : '0');
        CountryRates::forget();
        Setting::put('support_email', $data['support_email'] ?? '');
        Setting::put('trending_keywords', $data['trending_keywords'] ?? '');
        Setting::put('organizer_auto_approve', $request->boolean('organizer_auto_approve') ? '1' : '0');

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
