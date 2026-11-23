<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Region versions of pages, help articles and legal pages, for the admin.
 *
 * A page has a main version — the default, shown in every region without one
 * of its own — and may have region versions: the same address, with its own
 * title, content and SEO for the regions it lists (the Singapore pricing
 * page). This is the "tab per country" the editors show.
 */
final class RegionVersions
{
    /** The enabled regions, for the editors' region pickers. */
    public static function options(): array
    {
        return array_map(fn (array $r) => ['code' => $r['code'], 'name' => $r['name'], 'flag' => $r['flag'], 'default' => $r['default']], Regions::enabled());
    }

    /** @return array<string, mixed> validation rules for a `regions` list */
    public static function rules(bool $required = false): array
    {
        return [
            'regions' => [$required ? 'required' : 'nullable', 'array', $required ? 'min:1' : 'min:0'],
            'regions.*' => ['string', Rule::in(Regions::codes())],
        ];
    }

    /**
     * Refuse a region already covered by another version of the same page —
     * a visitor there would get one of two pages at random.
     */
    public static function assertFree(Model $main, array $regions, ?int $exceptId = null): void
    {
        $taken = $main->versions()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['id', 'regions'])
            ->flatMap(fn ($v) => (array) $v->regions)
            ->all();

        $clash = array_values(array_intersect($regions, $taken));

        if ($clash) {
            $names = implode(', ', array_map(fn ($c) => Regions::find($c)['name'] ?? $c, $clash));

            throw ValidationException::withMessages(['regions' => "{$names} already has its own version of this page. Pick other regions, or edit that version."]);
        }
    }

    /**
     * The tabs above the editor: the main version, then each region version,
     * with which one is open.
     *
     * @return list<array{id: int, label: string, flags: string, regions: list<string>, status: ?string, main: bool, active: bool}>
     */
    public static function tabs(Model $record): array
    {
        $main = $record->parent_id ? $record->parent : $record;
        $rows = collect([$main])->concat($main->versions()->get());

        return $rows->map(function (Model $v) use ($record, $main) {
            $regions = (array) $v->regions;
            $isMain = $v->is($main);

            return [
                'id' => $v->id,
                'label' => $isMain
                    ? ($regions ? 'Main · '.self::names($regions) : 'Main (all regions)')
                    : self::names($regions),
                'flags' => implode(' ', array_map(fn ($c) => Regions::flag(substr($c, 3)), $regions)),
                'regions' => array_values($regions),
                'status' => $v->status ?? null,
                'main' => $isMain,
                'active' => $v->is($record),
            ];
        })->values()->all();
    }

    /** "Singapore, Indonesia". */
    public static function names(array $codes): string
    {
        return implode(', ', array_map(fn ($c) => Regions::find($c)['name'] ?? strtoupper(substr($c, 3)), $codes));
    }
}
