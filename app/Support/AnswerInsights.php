<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What ticket-holders answered to the organizer's booking questions, counted.
 *
 * Two places need it: the attendee list's filters, and the analytics page.
 * Both used to cover CHOICE questions only — or, on the analytics page, none at
 * all — so an event whose questions were typed answers ("Any dietary needs?",
 * "Nickname for your tag") had nothing to filter by and nothing to count, and
 * the organizer tallied them by hand from a CSV.
 *
 * Choice questions are counted per option, every option listed (a zero is an
 * answer too: nobody picked the vegan meal). Text questions are counted per
 * distinct answer, grouped case- and spacing-insensitively so "Halal", "halal"
 * and "HALAL " are one bar, shown in whichever spelling was typed most often.
 * Answers nobody else gave are not plotted one bar each — a chart of forty
 * unique nicknames is noise — but still counted as answered, and a few are
 * kept as examples so the organizer can see what people wrote.
 *
 * Scope is every CURRENT ticket-holder (valid or checked in), not tickets sold
 * in a date window: this is "who is coming and what do they need", and a
 * "last 30 days" cut would silently undercount the drinks to prepare.
 */
class AnswerInsights
{
    /** How many distinct typed answers a text question may show as bars. */
    public const MAX_TEXT_VALUES = 8;

    /** A text answer is plotted when given at least this often, or when the question has few distinct answers. */
    private const REPEAT_THRESHOLD = 2;

    /** Text questions with at most this many distinct answers plot all of them. */
    private const SMALL_SET = 8;

    /** One canonical form for comparing typed answers. */
    public static function normalise(string $text): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /**
     * Per-question summary for an event.
     *
     * @param  callable|null  $scope  narrows the orders counted (the analytics
     *                                page's city / source filters); receives an
     *                                orders query builder
     * @return list<array<string, mixed>>
     */
    public static function forEvent(Event $event, ?callable $scope = null): array
    {
        $fields = CustomFields::forEvent($event);

        if ($fields === []) {
            return [];
        }

        $query = $event->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->with('ticketType:id,name');

        // A plain `if`, not ->when($scope, …): when() treats a Closure passed
        // as its CONDITION as something to call, so it ran the order filter
        // against the tickets query itself — buyer_city on `tickets`, which
        // SQLite reads as a string literal (no rows) and MySQL rejects.
        if ($scope !== null) {
            $query->whereHas('order', fn ($orders) => $scope($orders));
        }

        $tickets = $query->get(['id', 'ticket_type_id', 'custom_answers']);

        $total = $tickets->count();
        $types = $tickets->pluck('ticketType.name')->filter()->unique()->values();

        return array_map(
            fn (array $field) => self::summarise($field, $tickets, $total, $types),
            $fields,
        );
    }

    /**
     * Grouped typed answers for one text question, most common first.
     *
     * @param  iterable<string>  $answers  raw answers, blanks already removed
     * @return array{values: list<array{key: string, name: string, value: int}>, distinct: int}
     */
    public static function groupText(iterable $answers): array
    {
        $groups = [];

        foreach ($answers as $answer) {
            $key = self::normalise($answer);

            if ($key === '') {
                continue;
            }

            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
            $groups[$key]['spellings'][trim($answer)] = ($groups[$key]['spellings'][trim($answer)] ?? 0) + 1;
        }

        $distinct = count($groups);

        $values = collect($groups)
            ->map(function (array $group, string $key) {
                arsort($group['spellings']);

                return ['key' => $key, 'name' => (string) array_key_first($group['spellings']), 'value' => $group['count']];
            })
            // Plot everything when there are only a few distinct answers;
            // otherwise only the ones people actually share.
            ->filter(fn (array $row) => $distinct <= self::SMALL_SET || $row['value'] >= self::REPEAT_THRESHOLD)
            ->sortByDesc('value')
            ->take(self::MAX_TEXT_VALUES)
            ->values()
            ->all();

        return ['values' => $values, 'distinct' => $distinct];
    }

    /** @return array<string, mixed> */
    private static function summarise(array $field, Collection $tickets, int $total, Collection $types): array
    {
        $answersFor = fn (Ticket $t) => array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) (($t->custom_answers ?? [])[$field['id']] ?? [])),
            fn ($v) => $v !== '',
        ));

        $answered = $tickets->filter(fn (Ticket $t) => $answersFor($t) !== [])->count();

        $base = [
            'id' => $field['id'],
            'label' => $field['label'],
            'answered' => $answered,
            'total' => $total,
            'rate' => $total > 0 ? (int) round($answered / $total * 100) : 0,
        ];

        if (in_array($field['type'], CustomFields::CHOICE_TYPES, true)) {
            $options = collect($field['options'])->map(fn (array $option) => [
                'name' => $option['label'],
                'value' => $tickets->filter(fn (Ticket $t) => in_array($option['id'], $answersFor($t), true))->count(),
                // The attendee-list filter for exactly these people, so a bar
                // can link straight to "who picked this".
                'facet' => (string) $option['id'],
            ])->values()->all();

            // Split by ticket type, when there is more than one: "beginners
            // session vs experienced session" is the comparison organizers
            // actually want from a question like "Have you played before?".
            $byType = $types->count() > 1
                ? $types->map(fn (string $type) => [
                    'type' => $type,
                    'counts' => collect($field['options'])->mapWithKeys(fn (array $option) => [
                        $option['label'] => $tickets
                            ->filter(fn (Ticket $t) => $t->ticketType?->name === $type && in_array($option['id'], $answersFor($t), true))
                            ->count(),
                    ])->all(),
                ])->values()->all()
                : [];

            return $base + [
                'kind' => 'choice',
                'multiple' => (bool) ($field['multiple'] ?? false),
                'options' => $options,
                'by_type' => $byType,
            ];
        }

        $all = $tickets->flatMap($answersFor);
        $grouped = self::groupText($all);
        $plotted = array_sum(array_column($grouped['values'], 'value'));

        return $base + [
            'kind' => 'text',
            'values' => array_map(fn ($row) => ['name' => $row['name'], 'value' => $row['value'], 'facet' => 'v:'.$row['key']], $grouped['values']),
            'distinct' => $grouped['distinct'],
            // Everyone whose answer is not one of the plotted bars.
            'other' => max(0, $all->count() - $plotted),
            // A few verbatim answers that did NOT get a bar, so one-off replies
            // are still visible — repeating the plotted ones would be noise.
            'samples' => $all
                ->reject(fn ($a) => in_array(self::normalise($a), array_column($grouped['values'], 'key'), true))
                ->unique(fn ($a) => self::normalise($a))
                ->take(6)
                ->values()
                ->all(),
        ];
    }
}
