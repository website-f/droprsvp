<?php

namespace App\Support;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Host\EventController;
use App\Models\Event;
use Illuminate\Support\Str;

/**
 * Organizer-defined additional fields on the checkout form, filled in once per
 * ticket.
 *
 * An organizer adds fields to their event the way they would in a form builder —
 * "Which water?", "T-shirt size", "Dietary needs" — and every buyer fills them
 * in for each ticket in their order.
 *
 * A field can carry an IMAGE of its own, independent of its type. That is the
 * case this was built for: the organizer uploads a photo of the drinks menu and
 * the buyer simply types which one they want, no options to maintain. Attach the
 * picture to the field and it renders above the input; leave it off and the
 * field is a plain question. Per-OPTION images are a separate thing, used by the
 * image_choice type when each individual choice needs its own picture.
 *
 * Everything about the shape of a field lives here: the types, how a definition
 * is validated on save, and how an answer is validated and normalised at
 * checkout. Controllers stay thin and the two ends cannot drift apart, which
 * matters because a field authored in the event builder is read back months
 * later by a different controller.
 *
 * Definition:
 *   { id, label, type, required, help, image, multiple, options: [{ id, label, image }] }
 *
 * Answer (one per ticket, keyed by field id):
 *   "text"            => string
 *   "choice"          => option id  (or list of option ids when multiple)
 *
 * @see EventController  where definitions are saved
 * @see CheckoutController    where answers are captured
 */
class CustomFields
{
    /** Free text, one line. */
    public const TEXT = 'text';

    /** Free text, multi-line. */
    public const TEXTAREA = 'textarea';

    /** Pick from a list — plain labels, rendered as a dropdown. */
    public const SELECT = 'select';

    /** Pick from a list of PICTURES — the "choose your water" case. */
    public const IMAGE_CHOICE = 'image_choice';

    public const TYPES = [self::TEXT, self::TEXTAREA, self::SELECT, self::IMAGE_CHOICE];

    /** Types whose answer must be one of the field's own options. */
    public const CHOICE_TYPES = [self::SELECT, self::IMAGE_CHOICE];

    /** A hard ceiling so one event cannot make checkout unusable. */
    public const MAX_FIELDS = 12;

    public const MAX_OPTIONS = 24;

    // ---- definitions (event builder) ---------------------------------------

    /** Validation rules for the `custom_fields` half of the event form. */
    public static function rules(): array
    {
        return [
            'custom_fields' => ['array', 'max:'.self::MAX_FIELDS],
            'custom_fields.*.id' => ['nullable', 'string', 'max:40'],
            'custom_fields.*.label' => ['required', 'string', 'max:120'],
            'custom_fields.*.type' => ['required', 'in:'.implode(',', self::TYPES)],
            'custom_fields.*.help' => ['nullable', 'string', 'max:200'],
            'custom_fields.*.required' => ['boolean'],
            // An illustration for the field itself — a menu photo, a size chart,
            // a seating diagram. Optional on every type.
            'custom_fields.*.image' => ['nullable', 'string', 'max:2048'],
            'custom_fields.*.multiple' => ['boolean'],
            'custom_fields.*.options' => ['array', 'max:'.self::MAX_OPTIONS],
            'custom_fields.*.options.*.id' => ['nullable', 'string', 'max:40'],
            'custom_fields.*.options.*.label' => ['required', 'string', 'max:120'],
            'custom_fields.*.options.*.image' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * Clean a submitted definition set for storage: stable ids, no stray keys,
     * and options dropped from types that cannot use them.
     *
     * Ids are generated once and then preserved, because they are the keys every
     * existing answer is stored under — regenerating them would orphan the
     * answers on every order already placed.
     *
     * @return list<array<string,mixed>>
     */
    public static function sanitize(?array $fields): array
    {
        $clean = [];

        foreach (array_values($fields ?? []) as $field) {
            // Anything but an array here is corrupt data — a hand-edited JSON
            // column, a half-written import. Indexing a string with a string key
            // is a TypeError in PHP 8, which would 500 every page that reads the
            // event, so skip rather than trust the column's shape.
            if (! is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            $type = $field['type'] ?? self::TEXT;

            if ($label === '' || ! in_array($type, self::TYPES, true)) {
                continue;
            }

            $entry = [
                'id' => self::id($field['id'] ?? null),
                'label' => $label,
                'type' => $type,
                'help' => trim((string) ($field['help'] ?? '')) ?: null,
                'required' => (bool) ($field['required'] ?? false),
                'image' => trim((string) ($field['image'] ?? '')) ?: null,
            ];

            if (in_array($type, self::CHOICE_TYPES, true)) {
                $entry['multiple'] = (bool) ($field['multiple'] ?? false);
                $entry['options'] = [];

                foreach (array_values((array) ($field['options'] ?? [])) as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $optionLabel = trim((string) ($option['label'] ?? ''));

                    if ($optionLabel === '') {
                        continue;
                    }

                    $entry['options'][] = [
                        'id' => self::id($option['id'] ?? null),
                        'label' => $optionLabel,
                        'image' => trim((string) ($option['image'] ?? '')) ?: null,
                    ];
                }

                // A choice with nothing to choose from would trap the buyer on a
                // required question they cannot answer.
                if ($entry['options'] === []) {
                    continue;
                }
            }

            $clean[] = $entry;
        }

        return $clean;
    }

    /** Keep an existing id, or mint one. */
    private static function id(?string $existing): string
    {
        $existing = trim((string) $existing);

        return $existing !== '' ? $existing : (string) Str::uuid();
    }

    // ---- answers (checkout) ------------------------------------------------

    /** The fields a buyer must answer for this event, ready for the frontend. */
    public static function forEvent(Event $event): array
    {
        return self::sanitize($event->custom_fields);
    }

    /**
     * Validate and normalise one order's answers.
     *
     * $answers arrives as a list with one entry per ticket, in the same order the
     * tickets will be issued (see CheckoutService::markPaid). Returns the same
     * shape, with unknown fields dropped and choices resolved to option ids.
     *
     * @param  list<array<string,mixed>>  $answers
     * @return array{0: list<array<string,mixed>>, 1: array<string,string>} [normalised, errors]
     */
    public static function normalise(Event $event, array $answers, int $ticketCount): array
    {
        $fields = self::forEvent($event);

        if ($fields === []) {
            return [[], []];
        }

        $normalised = [];
        $errors = [];

        for ($i = 0; $i < $ticketCount; $i++) {
            $given = is_array($answers[$i] ?? null) ? $answers[$i] : [];
            $row = [];

            foreach ($fields as $field) {
                $key = "custom_answers.{$i}.{$field['id']}";
                $value = $given[$field['id']] ?? null;

                if (in_array($field['type'], self::CHOICE_TYPES, true)) {
                    $valid = array_column($field['options'], 'id');
                    $picked = array_values(array_intersect(
                        array_map('strval', (array) $value),
                        $valid,
                    ));

                    if ($field['required'] && $picked === []) {
                        $errors[$key] = "Please choose {$field['label']} for ticket ".($i + 1).'.';
                    }

                    if ($picked !== []) {
                        $row[$field['id']] = ($field['multiple'] ?? false) ? $picked : $picked[0];
                    }

                    continue;
                }

                $text = trim((string) (is_array($value) ? '' : $value));

                if ($field['required'] && $text === '') {
                    $errors[$key] = "Please fill in {$field['label']} for ticket ".($i + 1).'.';
                }

                if ($text !== '') {
                    $row[$field['id']] = Str::limit($text, 500, '');
                }
            }

            $normalised[] = $row;
        }

        return [$normalised, $errors];
    }

    /**
     * Turn one ticket's stored answers into readable label => value pairs, for
     * the attendee list, check-in and the ticket itself.
     *
     * Reads the labels off the event's CURRENT definitions, so a renamed field
     * shows its new name; an answer whose field was deleted is dropped rather
     * than shown as a bare uuid.
     *
     * @return array<string,string>
     */
    public static function readable(Event $event, ?array $answers, ?array $fields = null): array
    {
        if (! $answers) {
            return [];
        }

        $out = [];

        // $fields lets a caller resolve the definitions once for a whole page
        // of rows instead of re-parsing them per row.
        foreach ($fields ?? self::forEvent($event) as $field) {
            $value = $answers[$field['id']] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (in_array($field['type'], self::CHOICE_TYPES, true)) {
                $labels = [];
                foreach ((array) $value as $id) {
                    foreach ($field['options'] as $option) {
                        if ($option['id'] === $id) {
                            $labels[] = $option['label'];
                        }
                    }
                }
                $value = implode(', ', $labels);
            }

            if ($value !== '') {
                $out[$field['label']] = (string) $value;
            }
        }

        return $out;
    }
}
