<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who booked, sliced every way an organizer actually asks about it.
 *
 * The attendee list used to filter by status and ticket type only. An organizer
 * who had asked "Have you played before?" at checkout could read each answer by
 * opening attendees one at a time, and could not answer the question they really
 * had — "how many first-timers do I need a storyteller for?" — at all.
 *
 * This builds the filters FROM the event instead of hard-coding them:
 *
 *   * one facet per choice-type booking question the organizer wrote, with each
 *     option it offered;
 *   * the checkout demographics — gender, age band, city, how they heard —
 *     included only when at least one buyer actually answered, so an event that
 *     never asked for a city does not grow an empty "City" filter;
 *   * "left a note", because remarks are where wheelchair access and allergies
 *     turn up, and they are exactly the ones an organizer must not miss.
 *
 * Every option carries a count. Counts are faceted: each facet is counted over
 * the tickets matching every OTHER active filter, so picking "Veteran" updates
 * the drink counts to what veterans ordered while still showing how many of the
 * other experience levels there are to switch to.
 *
 * Filtering happens in PHP over a slim projection of the event's tickets rather
 * than as JSON-path SQL. The answers live in a JSON column, multi-select answers
 * are arrays, and production runs MySQL while the suite runs SQLite — JSON
 * "contains" queries are not portable across the two. An event's ticket list is
 * bounded (hundreds, low thousands), so one pass over a few columns is cheap,
 * and it lets search reach the answers too: typing a nickname finds the ticket.
 */
class AttendeeDirectory
{
    /** Demographic facets, in display order: key => [label, order column]. */
    private const DEMOGRAPHICS = [
        'gender' => ['Gender', 'buyer_gender'],
        'age' => ['Age', 'buyer_age_band'],
        'city' => ['City', 'buyer_city'],
        'source' => ['Heard about it via', 'buyer_source'],
    ];

    private const GENDER_LABELS = [
        'female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'na' => 'Prefer not to say',
    ];

    private const SOURCE_LABELS = [
        'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok',
        'friend' => 'A friend', 'search' => 'Search', 'email' => 'Email', 'other' => 'Other',
    ];

    /** A city facet with more options than this is truncated to its biggest. */
    private const MAX_OPTIONS = 12;

    /** @var array<int, array<string, mixed>> the event's booking questions */
    private array $fields;

    /** @var Collection<int, array<string, mixed>>|null one entry per ticket */
    private ?Collection $rows = null;

    /** @var array<string, string> field id => type, for matching */
    private array $fieldTypes = [];

    /**
     * @param  array<string, string>  $facets  selected facet => value
     */
    public function __construct(
        private readonly Event $event,
        private readonly Builder $base,
        private readonly string $search,
        private readonly array $facets,
    ) {
        $this->fields = CustomFields::forEvent($event);
        $this->fieldTypes = array_column($this->fields, 'type', 'id');
    }

    /** The booking questions, resolved once for the whole page. */
    public function fields(): array
    {
        return $this->fields;
    }

    /** Whether search or any facet narrows the list beyond the SQL filters. */
    public function narrows(): bool
    {
        return $this->search !== '' || $this->activeFacets() !== [];
    }

    /** @return list<int> ticket ids matching search AND every active facet */
    public function matchingIds(): array
    {
        return $this->rows()
            ->filter(fn (array $row) => $this->matchesSearch($row) && $this->matchesFacets($row, null))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Every facet worth showing, with faceted counts.
     *
     * @return list<array{key: string, label: string, kind: string, options: list<array{value: string, label: string, count: int}>}>
     */
    public function facets(): array
    {
        $out = [];

        // The organizer's own questions first: they are why this page exists.
        foreach ($this->fields as $field) {
            $key = 'f_'.$field['id'];
            $pool = $this->poolExcluding($key);

            if (in_array($field['type'], CustomFields::CHOICE_TYPES, true)) {
                $options = collect($field['options'])->map(fn (array $option) => [
                    'value' => (string) $option['id'],
                    'label' => (string) $option['label'],
                    'count' => $pool->filter(fn (array $row) => in_array($option['id'], $row['answers'][$field['id']] ?? [], true))->count(),
                ])->values()->all();
            } else {
                // Typed answers. These used to get no filter at all, so an event
                // whose questions were free text showed nothing under "your
                // booking questions". Offer answered / not answered, plus the
                // answers people actually share — grouped so "Halal" and
                // "halal" are one chip. The chips are chosen from everyone, so
                // they don't jump around as other filters change; only the
                // counts follow the filters.
                $grouped = AnswerInsights::groupText(
                    $this->rows()->map(fn (array $row) => $row['answers'][$field['id']][0] ?? '')->filter(),
                );
                $answerOf = fn (array $row) => AnswerInsights::normalise((string) ($row['answers'][$field['id']][0] ?? ''));

                $options = array_merge(
                    [
                        ['value' => '__any', 'label' => 'Answered', 'count' => $pool->filter(fn ($row) => $answerOf($row) !== '')->count()],
                        ['value' => '__none', 'label' => 'No answer', 'count' => $pool->filter(fn ($row) => $answerOf($row) === '')->count()],
                    ],
                    array_map(fn (array $value) => [
                        'value' => 'v:'.$value['key'],
                        'label' => $value['name'],
                        'count' => $pool->filter(fn ($row) => $answerOf($row) === $value['key'])->count(),
                    ], $grouped['values']),
                );
            }

            $out[] = ['key' => $key, 'label' => $field['label'], 'kind' => 'question', 'options' => $options];
        }

        // Demographics, only where somebody actually answered.
        foreach (self::DEMOGRAPHICS as $key => [$label]) {
            $values = $this->rows()->pluck($key)->filter(fn ($v) => $v !== null && $v !== '')->unique();

            if ($values->isEmpty()) {
                continue;
            }

            $pool = $this->poolExcluding($key);

            $options = $values
                ->map(fn ($value) => [
                    'value' => (string) $value,
                    'label' => $this->demographicLabel($key, (string) $value),
                    'count' => $pool->where($key, $value)->count(),
                ])
                ->sortByDesc('count')
                ->take(self::MAX_OPTIONS)
                ->values()
                ->all();

            // Age bands read better in age order than by popularity.
            if ($key === 'age') {
                usort($options, fn ($a, $b) => strnatcmp($a['value'], $b['value']));
            }

            $out[] = ['key' => $key, 'label' => $label, 'kind' => 'demographic', 'options' => $options];
        }

        if ($this->rows()->contains(fn (array $row) => $row['has_note'])) {
            $pool = $this->poolExcluding('note');
            $out[] = [
                'key' => 'note',
                'label' => 'Remarks',
                'kind' => 'flag',
                'options' => [[
                    'value' => 'yes',
                    'label' => 'Left a note',
                    'count' => $pool->where('has_note', true)->count(),
                ]],
            ];
        }

        return $out;
    }

    /** Only the facets the request selected that actually exist on this event. */
    public function activeFacets(): array
    {
        $known = ['gender', 'age', 'city', 'source', 'note'];

        foreach ($this->fields as $field) {
            $known[] = 'f_'.$field['id'];
        }

        return array_filter(
            $this->facets,
            fn ($value, $key) => $value !== '' && in_array($key, $known, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    // ---- internals -----------------------------------------------------------

    /**
     * One slim row per ticket matching the SQL filters (status, ticket type).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $readableFields = $this->fields;

        return $this->rows = (clone $this->base)
            ->with('order:id,reference,buyer_name,buyer_email,buyer_phone,buyer_gender,buyer_age_band,buyer_city,buyer_source,notes')
            ->get(['id', 'order_id', 'attendee_name', 'attendee_email', 'qr_token', 'custom_answers'])
            ->map(function (Ticket $t) use ($readableFields) {
                $answers = [];

                foreach ((array) ($t->custom_answers ?? []) as $fieldId => $value) {
                    // Normalised to a list, so single- and multi-select are
                    // matched the same way.
                    $answers[$fieldId] = array_map('strval', (array) $value);
                }

                $readable = CustomFields::readable($this->event, $t->custom_answers, $readableFields);

                return [
                    'id' => $t->id,
                    'answers' => $answers,
                    'gender' => $t->order?->buyer_gender,
                    'age' => $t->order?->buyer_age_band,
                    'city' => $t->order?->buyer_city,
                    'source' => $t->order?->buyer_source,
                    'has_note' => trim((string) $t->order?->notes) !== '',
                    // Everything search is allowed to match, lower-cased once.
                    'haystack' => Str::lower(implode(' ', array_filter([
                        $t->attendee_name, $t->attendee_email, $t->qr_token,
                        $t->order?->reference, $t->order?->buyer_name, $t->order?->buyer_email,
                        $t->order?->buyer_phone, $t->order?->buyer_city, $t->order?->notes,
                        implode(' ', $readable),
                    ]))),
                ];
            });
    }

    /** Rows matching search and every active facet EXCEPT $skip (faceted counts). */
    private function poolExcluding(string $skip): Collection
    {
        return $this->rows()->filter(
            fn (array $row) => $this->matchesSearch($row) && $this->matchesFacets($row, $skip),
        );
    }

    private function matchesSearch(array $row): bool
    {
        if ($this->search === '') {
            return true;
        }

        // Phone numbers are typed every way imaginable — "012-345 6789",
        // "0123456789" — so digits are compared on their own as well.
        $needle = Str::lower($this->search);
        $digits = preg_replace('/\D+/', '', $this->search);

        return str_contains($row['haystack'], $needle)
            || ($digits !== '' && strlen($digits) >= 4 && str_contains(preg_replace('/\D+/', '', $row['haystack']), $digits));
    }

    private function matchesFacets(array $row, ?string $skip): bool
    {
        foreach ($this->activeFacets() as $key => $value) {
            if ($key === $skip) {
                continue;
            }

            $ok = match (true) {
                str_starts_with($key, 'f_') => $this->matchesAnswer(substr($key, 2), (string) $value, $row),
                $key === 'note' => $row['has_note'],
                default => (string) ($row[$key] ?? '') === (string) $value,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** Does this row's answer to one question satisfy a facet value? */
    private function matchesAnswer(string $fieldId, string $value, array $row): bool
    {
        $given = $row['answers'][$fieldId] ?? [];

        if (in_array($this->fieldTypes[$fieldId] ?? '', CustomFields::CHOICE_TYPES, true)) {
            return in_array($value, $given, true);
        }

        $answer = AnswerInsights::normalise((string) ($given[0] ?? ''));

        return match (true) {
            $value === '__any' => $answer !== '',
            $value === '__none' => $answer === '',
            str_starts_with($value, 'v:') => $answer === substr($value, 2),
            default => false,
        };
    }

    private function demographicLabel(string $key, string $value): string
    {
        return match ($key) {
            'gender' => self::GENDER_LABELS[$value] ?? Str::headline($value),
            'source' => self::SOURCE_LABELS[$value] ?? Str::headline($value),
            'age' => $value,
            default => $value,
        };
    }

    /** Readable label for a stored gender, for the detail view. */
    public static function genderLabel(?string $value): ?string
    {
        return $value ? (self::GENDER_LABELS[$value] ?? Str::headline($value)) : null;
    }

    /** Readable label for a stored "heard about it via", for the detail view. */
    public static function sourceLabel(?string $value): ?string
    {
        return $value ? (self::SOURCE_LABELS[$value] ?? Str::headline($value)) : null;
    }
}
