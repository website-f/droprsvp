import { GripVertical, ImagePlus, Loader2, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { CustomField, CustomFieldOption, CustomFieldType } from '@/components/custom-fields';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { uploadImageWithToast } from '@/lib/upload';

/**
 * The organizer's form builder: the additional fields a buyer fills in for each
 * ticket.
 *
 * Mirrors App\Support\CustomFields, which sanitises and stores what this
 * produces. Ids are minted on the server and echoed back unchanged — they are
 * the keys existing answers are stored under, so a field keeps its id for life
 * and renaming its label never orphans an answer.
 */

const field =
    'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

const TYPES: Array<{ value: CustomFieldType; label: string }> = [
    { value: 'text', label: 'Text — short answer' },
    { value: 'textarea', label: 'Text — long answer' },
    { value: 'select', label: 'Choice — pick from a list' },
    { value: 'image_choice', label: 'Choice — pick from pictures' },
];

const CHOICE_TYPES: CustomFieldType[] = ['select', 'image_choice'];

/** A blank field. No id — the server mints one on save. */
const blankField = (): CustomField => ({
    id: '',
    label: '',
    type: 'text',
    help: '',
    required: false,
    image: null,
    multiple: false,
    options: [],
});

const blankOption = (): CustomFieldOption => ({ id: '', label: '', image: null });

export function CustomFieldsBuilder({
    fields,
    onChange,
    errors,
}: {
    fields: CustomField[];
    onChange: (next: CustomField[]) => void;
    errors: Record<string, string>;
}) {
    const patch = (i: number, next: Partial<CustomField>) =>
        onChange(fields.map((f, idx) => (idx === i ? { ...f, ...next } : f)));

    const patchOption = (fieldIndex: number, optionIndex: number, next: Partial<CustomFieldOption>) =>
        patch(fieldIndex, {
            options: (fields[fieldIndex].options ?? []).map((o, idx) => (idx === optionIndex ? { ...o, ...next } : o)),
        });

    return (
        <div className="grid gap-3">
            <p className="text-sm text-muted-foreground">
                Collect anything you need from buyers — a meal choice, a t-shirt size, a drink from a
                photo menu. Add a picture to a field and it shows above it, so you can post your menu and
                let people simply type what they want. Every field is filled in{' '}
                <strong>once per ticket</strong>, and the answers show on your attendee list, at check-in
                and in your CSV export.
            </p>

            {fields.map((f, i) => (
                <div key={i} className="grid gap-3 rounded-lg border border-border p-3">
                    <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                        <span className="flex items-center gap-1.5 text-sm font-semibold text-muted-foreground">
                            <GripVertical className="size-4" />
                            Field {i + 1}
                            {f.label ? ` · ${f.label}` : ''}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => onChange(fields.filter((_, idx) => idx !== i))}
                        >
                            <Trash2 className="size-4" /> Remove
                        </Button>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label>Label</Label>
                            <input
                                className={field}
                                value={f.label}
                                onChange={(e) => patch(i, { label: e.target.value })}
                                placeholder="e.g. Which water would you like?"
                            />
                            {errors[`custom_fields.${i}.label`] && (
                                <p className="text-xs text-destructive">{errors[`custom_fields.${i}.label`]}</p>
                            )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Answer type</Label>
                            <AppSelect
                                value={f.type}
                                onChange={(v) => {
                                    const type = v as CustomFieldType;
                                    patch(i, {
                                        type,
                                        // Starting a choice question with one empty option saves a click.
                                        options: CHOICE_TYPES.includes(type)
                                            ? (f.options?.length ? f.options : [blankOption()])
                                            : [],
                                    });
                                }}
                                options={TYPES}
                            />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label>
                            Hint <span className="font-normal text-muted-foreground">— optional</span>
                        </Label>
                        <input
                            className={field}
                            value={f.help ?? ''}
                            onChange={(e) => patch(i, { help: e.target.value })}
                            placeholder="Shown under the label"
                        />
                    </div>

                    <FieldImage image={f.image ?? null} onChange={(image) => patch(i, { image })} />

                    {CHOICE_TYPES.includes(f.type) && (
                        <OptionList
                            fieldIndex={i}
                            type={f.type}
                            options={f.options ?? []}
                            onChange={(options) => patch(i, { options })}
                            onPatch={(optionIndex, next) => patchOption(i, optionIndex, next)}
                            errors={errors}
                        />
                    )}

                    <div className="flex flex-wrap items-center gap-5 rounded-lg bg-muted/40 p-3">
                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={!!f.required} onCheckedChange={(v) => patch(i, { required: v })} />
                            Required
                        </label>
                        {CHOICE_TYPES.includes(f.type) && (
                            <label className="flex items-center gap-2 text-sm">
                                <Switch checked={!!f.multiple} onCheckedChange={(v) => patch(i, { multiple: v })} />
                                Allow more than one
                            </label>
                        )}
                    </div>
                </div>
            ))}

            <Button type="button" variant="outline" className="gap-1.5" onClick={() => onChange([...fields, blankField()])}>
                <Plus className="size-4" /> Add a field
            </Button>
        </div>
    );
}

/**
 * An optional picture for the field itself, on any type.
 *
 * This is the "post your menu and let people type what they want" case: the
 * organizer uploads one photo, adds a plain text field under it, and never has
 * to maintain a list of options. Distinct from the per-CHOICE images used by the
 * pick-from-pictures type.
 */
function FieldImage({ image, onChange }: { image: string | null; onChange: (image: string | null) => void }) {
    const [uploading, setUploading] = useState(false);
    const input = useRef<HTMLInputElement | null>(null);

    const pick = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);
        const url = await uploadImageWithToast(file);

        if (url) {
            onChange(url);
        }

        setUploading(false);
    };

    return (
        <div className="grid gap-1.5">
            <Label>
                Picture <span className="font-normal text-muted-foreground">— optional, shown above the field</span>
            </Label>

            <input
                ref={input}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => pick(e.target.files?.[0])}
            />

            {image ? (
                <div className="flex items-start gap-3">
                    <img src={image} alt="" className="max-h-28 rounded-lg border border-border object-contain" />
                    <div className="flex flex-col gap-1.5">
                        <Button type="button" variant="outline" size="sm" onClick={() => input.current?.click()}>
                            Replace
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => onChange(null)}
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    className="justify-self-start gap-1.5"
                    disabled={uploading}
                    onClick={() => input.current?.click()}
                >
                    {uploading ? <Loader2 className="size-4 animate-spin" /> : <ImagePlus className="size-4" />}
                    Upload a picture
                </Button>
            )}
        </div>
    );
}

/** The choices for a pick-from-a-list / pick-from-pictures field, each with an optional image. */
function OptionList({
    fieldIndex,
    type,
    options,
    onChange,
    onPatch,
    errors,
}: {
    fieldIndex: number;
    type: CustomFieldType;
    options: CustomFieldOption[];
    onChange: (next: CustomFieldOption[]) => void;
    onPatch: (optionIndex: number, next: Partial<CustomFieldOption>) => void;
    errors: Record<string, string>;
}) {
    const [uploading, setUploading] = useState<number | null>(null);
    const inputs = useRef<Record<number, HTMLInputElement | null>>({});

    const pickImage = async (optionIndex: number, file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(optionIndex);
        // uploadImageWithToast validates, uploads, and toasts on failure —
        // returning null rather than throwing, so the picker just stays empty.
        const url = await uploadImageWithToast(file);

        if (url) {
            onPatch(optionIndex, { image: url });
        }

        setUploading(null);
    };

    return (
        <div className="grid gap-2">
            <Label>Choices</Label>

            {options.map((o, j) => (
                <div key={j} className="flex items-center gap-2">
                    {type === 'image_choice' && (
                        <>
                            <input
                                ref={(el) => {
                                    inputs.current[j] = el;
                                }}
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => pickImage(j, e.target.files?.[0])}
                            />
                            <button
                                type="button"
                                onClick={() => inputs.current[j]?.click()}
                                className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-lg border border-border bg-muted/40 transition-colors hover:border-foreground/40"
                                aria-label={`Image for choice ${j + 1}`}
                            >
                                {uploading === j ? (
                                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                                ) : o.image ? (
                                    <img src={o.image} alt="" className="size-full object-cover" />
                                ) : (
                                    <ImagePlus className="size-4 text-muted-foreground" />
                                )}
                            </button>
                        </>
                    )}
                    <input
                        className={field}
                        value={o.label}
                        onChange={(e) => onPatch(j, { label: e.target.value })}
                        placeholder={type === 'image_choice' ? 'e.g. Sparkling' : 'e.g. Vegetarian'}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-9 shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => onChange(options.filter((_, idx) => idx !== j))}
                        aria-label={`Remove choice ${j + 1}`}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            ))}

            {errors[`custom_fields.${fieldIndex}.options`] && (
                <p className="text-xs text-destructive">{errors[`custom_fields.${fieldIndex}.options`]}</p>
            )}

            <Button type="button" variant="ghost" size="sm" className="justify-self-start gap-1.5" onClick={() => onChange([...options, blankOption()])}>
                <Plus className="size-4" /> Add a choice
            </Button>
        </div>
    );
}
