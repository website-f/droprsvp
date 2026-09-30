<?php

namespace App\Support;

use App\Http\Controllers\Public\DiscoverController;
use Illuminate\Support\Str;

/**
 * Canonical list of Malaysian cities used for SEO-friendly discovery URLs
 * (/en-my/{city}/{category}). A controlled vocabulary keeps the URL space clean
 * and avoids thin, duplicate location pages.
 *
 * Cities are grouped BY STATE. A flat list asked people to find "Klang" among
 * twenty unrelated names with no hint that it sits in Selangor, and it left out
 * most of the country — an organizer in Kajang had nowhere to put their event.
 * Picking a state first narrows the second list to a handful, which is how
 * everyone expects a Malaysian address to be entered.
 *
 * SLUGS ARE UNCHANGED and must stay that way: they are Str::slug of the city
 * name, they are live in the sitemap and indexed by Google, and
 * /en-my/kuala-lumpur/ has to keep resolving. Adding a city is safe; renaming
 * one silently 404s a page that was ranking, so add a redirect if you ever must.
 *
 * @see DiscoverController  resolves {city} from a slug
 */
class Cities
{
    /** The special "any city" URL segment used for country-wide category pages. */
    public const ANY = 'all';

    /**
     * State => cities. Federal territories are listed as states because that is
     * how an address is written, not because of their constitutional status.
     *
     * @var array<string, string[]>
     */
    public const BY_STATE = [
        'Kuala Lumpur' => ['Kuala Lumpur'],
        'Selangor' => [
            'Shah Alam', 'Petaling Jaya', 'Subang Jaya', 'Klang', 'Kajang', 'Puchong',
            'Cyberjaya', 'Ampang', 'Rawang', 'Bangi', 'Semenyih', 'Sepang', 'Cheras', 'Selayang',
        ],
        'Putrajaya' => ['Putrajaya'],
        'Johor' => ['Johor Bahru', 'Iskandar Puteri', 'Batu Pahat', 'Muar', 'Kluang', 'Segamat', 'Kulai', 'Pontian'],
        'Penang' => ['George Town', 'Butterworth', 'Bayan Lepas', 'Bukit Mertajam', 'Balik Pulau'],
        'Perak' => ['Ipoh', 'Taiping', 'Teluk Intan', 'Sitiawan', 'Kampar'],
        'Kedah' => ['Alor Setar', 'Sungai Petani', 'Kulim', 'Langkawi'],
        'Kelantan' => ['Kota Bharu', 'Pasir Mas', 'Tanah Merah'],
        'Melaka' => ['Melaka', 'Alor Gajah', 'Jasin'],
        'Negeri Sembilan' => ['Seremban', 'Port Dickson', 'Nilai', 'Bahau'],
        'Pahang' => ['Kuantan', 'Temerloh', 'Bentong', 'Cameron Highlands', 'Genting Highlands'],
        'Perlis' => ['Kangar'],
        'Terengganu' => ['Kuala Terengganu', 'Kemaman', 'Dungun'],
        'Sabah' => ['Kota Kinabalu', 'Sandakan', 'Tawau', 'Lahad Datu'],
        'Sarawak' => ['Kuching', 'Miri', 'Sibu', 'Bintulu'],
        'Labuan' => ['Labuan'],
    ];

    /**
     * Approx city-centre coordinates [lat, lng] — used to show "~N km away".
     * A city without an entry simply doesn't show a distance, so it is better to
     * omit one than to guess at it.
     */
    public const COORDS = [
        'Kuala Lumpur' => [3.1390, 101.6869],
        'Petaling Jaya' => [3.1073, 101.6067],
        'Shah Alam' => [3.0733, 101.5185],
        'Subang Jaya' => [3.0438, 101.5810],
        'Klang' => [3.0449, 101.4455],
        'Kajang' => [2.9935, 101.7874],
        'Puchong' => [3.0199, 101.6167],
        'Putrajaya' => [2.9264, 101.6964],
        'Cyberjaya' => [2.9213, 101.6559],
        'Ampang' => [3.1488, 101.7617],
        'Rawang' => [3.3210, 101.5770],
        'Bangi' => [2.9439, 101.7710],
        'Semenyih' => [2.9558, 101.8430],
        'Sepang' => [2.6900, 101.7500],
        'Cheras' => [3.1050, 101.7500],
        'Selayang' => [3.2500, 101.6500],
        'George Town' => [5.4141, 100.3288],
        'Butterworth' => [5.3991, 100.3638],
        'Bayan Lepas' => [5.2945, 100.2759],
        'Bukit Mertajam' => [5.3630, 100.4660],
        'Johor Bahru' => [1.4927, 103.7414],
        'Iskandar Puteri' => [1.4200, 103.6300],
        'Batu Pahat' => [1.8548, 102.9325],
        'Muar' => [2.0442, 102.5689],
        'Kluang' => [2.0250, 103.3167],
        'Ipoh' => [4.5975, 101.0901],
        'Taiping' => [4.8500, 100.7333],
        'Melaka' => [2.1896, 102.2501],
        'Seremban' => [2.7297, 101.9381],
        'Port Dickson' => [2.5228, 101.7960],
        'Nilai' => [2.8100, 101.7970],
        'Kuantan' => [3.8077, 103.3260],
        'Temerloh' => [3.4500, 102.4167],
        'Bentong' => [3.5220, 101.9080],
        'Cameron Highlands' => [4.4710, 101.3770],
        'Genting Highlands' => [3.4230, 101.7930],
        'Kota Kinabalu' => [5.9804, 116.0735],
        'Sandakan' => [5.8402, 118.1179],
        'Tawau' => [4.2448, 117.8912],
        'Kuching' => [1.5535, 110.3593],
        'Miri' => [4.3995, 113.9914],
        'Sibu' => [2.2870, 111.8305],
        'Bintulu' => [3.1700, 113.0330],
        'Alor Setar' => [6.1248, 100.3678],
        'Sungai Petani' => [5.6470, 100.4870],
        'Kulim' => [5.3650, 100.5610],
        'Kota Bharu' => [6.1254, 102.2381],
        'Kuala Terengganu' => [5.3302, 103.1408],
        'Kemaman' => [4.2330, 103.4190],
        'Langkawi' => [6.3500, 99.8000],
        'Kangar' => [6.4414, 100.1986],
        'Labuan' => [5.2831, 115.2308],
    ];

    /** Every city name, flat, in state order. @return string[] */
    public static function names(): array
    {
        return array_merge(...array_values(self::BY_STATE));
    }

    /**
     * Flat list of every city, unchanged in shape from before the states were
     * introduced so existing callers keep working.
     *
     * @return array<int, array{name: string, slug: string, state: string}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::BY_STATE as $state => $cities) {
            foreach ($cities as $name) {
                $out[] = ['name' => $name, 'slug' => Str::slug($name), 'state' => $state];
            }
        }

        return $out;
    }

    /**
     * Grouped for a two-step "state, then city" picker.
     *
     * @return array<int, array{state: string, cities: array<int, array{name: string, slug: string}>}>
     */
    public static function grouped(): array
    {
        $out = [];

        foreach (self::BY_STATE as $state => $cities) {
            $out[] = [
                'state' => $state,
                'cities' => array_map(
                    fn (string $name) => ['name' => $name, 'slug' => Str::slug($name)],
                    $cities,
                ),
            ];
        }

        return $out;
    }

    /** @return string[] */
    public static function states(): array
    {
        return array_keys(self::BY_STATE);
    }

    /** Which state a city sits in — for prefilling the picker when editing. */
    public static function stateForCity(?string $city): ?string
    {
        if (! $city) {
            return null;
        }

        foreach (self::BY_STATE as $state => $cities) {
            if (in_array($city, $cities, true)) {
                return $state;
            }
        }

        return null;
    }

    /** @return array{lat: float, lng: float}|null */
    public static function coordsForName(?string $name): ?array
    {
        if ($name && isset(self::COORDS[$name])) {
            return ['lat' => self::COORDS[$name][0], 'lng' => self::COORDS[$name][1]];
        }

        return null;
    }

    /** Resolve a URL slug back to its canonical display name (null if unknown / "all"). */
    public static function nameForSlug(?string $slug): ?string
    {
        if (! $slug || $slug === self::ANY) {
            return null;
        }

        foreach (self::names() as $name) {
            if (Str::slug($name) === $slug) {
                return $name;
            }
        }

        return null;
    }

    public static function slugForName(?string $name): ?string
    {
        return $name ? Str::slug($name) : null;
    }

    public static function isKnownSlug(string $slug): bool
    {
        return $slug === self::ANY || self::nameForSlug($slug) !== null;
    }
}
