import { GripVertical, ImagePlus, Loader2, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { AppSelect } from '@/components/ui/app-select';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { uploadImageWithToast } from '@/lib/upload';
import type { CustomField, CustomFieldOption, CustomFieldType } from '@/components/custom-fields';

/**
 * The organizer's form builder: the questions a buyer answers for each ticket.
 *
 * Mirrors App\Support\CustomFields, which sanitises and stores what this
 * produces. Ids are minted on the server and echoed back unchanged — they are
 * the keys existing answers are stored under, so a field keeps its id for life
 * and renaming its label never orphans an answer.
 */

const field =
    'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

const TYPES: Array<{ value: CustomFieldType; label: string }> = [
    { value: 'text', label: 'Short answer' },
    { value: 'textarea', label: 'Long answer' },
    { value: 'select', label: 'Pick from a list' },
    { value: 'image_choice', label: 'Pick from pictures' },
];

const CHOICE_TYPES: CustomFieldType[] = ['select', 'image_choice'];

/** A blank field. No id — the server mints one on save. */
const blankField = (): CustomField => ({
    id: '',
    label: '',
    type: 'text',
    help: '',
    required: false,
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
                Ask buyers anything you need — a meal choice, a t-shirt size, a drink from a photo menu.
                Each question is answered <strong>once per ticket</strong>, and the answers show on your
                attendee list and at check-in.
            </p>

            {fields.map((f, i) => (
                <div key={i} className="grid gap-3 rounded-lg border border-border p-3">
                    <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                        <span className="flex items-center gap-1.5 text-sm font-semibold text-muted-foreground">
                            <GripVertical className="size-4" />
                            Question {i + 1}
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
                            <Label>Question</Label>
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
                            placeholder="Shown under the question"
                        />
                    </div>

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
                <Plus className="size-4" /> Add a question
            </Button>
        </div>
    );
}

/** The choices for a select / picture question, each with an optional image. */
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
