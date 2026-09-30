import { Check } from 'lucide-react';
import { Label } from '@/components/ui/label';

/**
 * The organizer's own additional fields on the checkout form, filled in once per
 * ticket.
 *
 * The definitions are authored in the event builder and mirrored here — the
 * shapes must match App\Support\CustomFields, which validates the answers on the
 * way back in and is the only place that knows what an option id means.
 */

export type CustomFieldType = 'text' | 'textarea' | 'select' | 'image_choice';

export interface CustomFieldOption {
    id: string;
    label: string;
    image?: string | null;
}

export interface CustomField {
    id: string;
    label: string;
    type: CustomFieldType;
    help?: string | null;
    required?: boolean;
    /** An illustration for the field itself — a menu photo, a size chart. */
    image?: string | null;
    multiple?: boolean;
    options?: CustomFieldOption[];
}

/** One ticket's answers, keyed by field id. */
export type CustomAnswers = Record<string, string | string[]>;

const inputClass =
    'w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/**
 * Renders one ticket's worth of fields.
 *
 * `errors` is keyed the way Laravel returns them — custom_answers.<index>.<fieldId>
 * — so a message lands on the exact ticket and question it belongs to.
 */
export function CustomFieldSet({
    fields,
    index,
    answers,
    onChange,
    errors,
}: {
    fields: CustomField[];
    index: number;
    answers: CustomAnswers;
    onChange: (next: CustomAnswers) => void;
    errors: Record<string, string>;
}) {
    const set = (fieldId: string, value: string | string[]) => onChange({ ...answers, [fieldId]: value });

    /** Picking in a multi-select toggles; in a single-select it replaces. */
    const toggle = (field: CustomField, optionId: string) => {
        if (!field.multiple) {
            set(field.id, answers[field.id] === optionId ? '' : optionId);

            return;
        }

        const current = Array.isArray(answers[field.id]) ? (answers[field.id] as string[]) : [];
        set(field.id, current.includes(optionId) ? current.filter((id) => id !== optionId) : [...current, optionId]);
    };

    const isPicked = (field: CustomField, optionId: string) =>
        field.multiple
            ? Array.isArray(answers[field.id]) && (answers[field.id] as string[]).includes(optionId)
            : answers[field.id] === optionId;

    return (
        <div className="grid gap-4">
            {fields.map((f) => {
                const error = errors[`custom_answers.${index}.${f.id}`];
                const value = answers[f.id];

                return (
                    <div key={f.id} className="grid gap-1.5">
                        <Label>
                            {f.label}
                            {f.required && <span className="ml-0.5 text-destructive">*</span>}
                        </Label>
                        {f.help && <p className="text-xs text-muted-foreground">{f.help}</p>}

                        {/* The organizer's own illustration — a photo of the drinks
                            menu, a size chart. Shown on any field type, so "here is
                            the menu, type what you want" needs no options at all. */}
                        {f.image && (
                            <a href={f.image} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-xl border border-border">
                                <img src={f.image} alt={f.label} className="max-h-72 w-full object-contain bg-muted/30" />
                            </a>
                        )}

                        {f.type === 'text' && (
                            <input
                                className={inputClass}
                                value={typeof value === 'string' ? value : ''}
                                onChange={(e) => set(f.id, e.target.value)}
                            />
                        )}

                        {f.type === 'textarea' && (
                            <textarea
                                rows={3}
                                className={inputClass}
                                value={typeof value === 'string' ? value : ''}
                                onChange={(e) => set(f.id, e.target.value)}
                            />
                        )}

                        {f.type === 'select' && (
                            <select
                                className={inputClass}
                                value={typeof value === 'string' ? value : ''}
                                onChange={(e) => set(f.id, e.target.value)}
                            >
                                <option value="">—</option>
                                {(f.options ?? []).map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        )}

                        {/* Picture menu — the "choose your water" case. The image is
                            the point, so it leads and the label sits under it. */}
                        {f.type === 'image_choice' && (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {(f.options ?? []).map((o) => {
                                    const picked = isPicked(f, o.id);

                                    return (
                                        <button
                                            key={o.id}
                                            type="button"
                                            onClick={() => toggle(f, o.id)}
                                            aria-pressed={picked}
                                            className={`group relative overflow-hidden rounded-xl border text-left transition-colors ${
                                                picked ? 'border-foreground ring-2 ring-foreground/20' : 'border-border hover:border-foreground/40'
                                            }`}
                                        >
                                            {o.image ? (
                                                <img src={o.image} alt="" className="aspect-square w-full object-cover" />
                                            ) : (
                                                <div className="aspect-square w-full bg-muted" />
                                            )}
                                            {picked && (
                                                <span className="absolute right-1.5 top-1.5 grid size-6 place-items-center rounded-full bg-foreground text-background">
                                                    <Check className="size-3.5" />
                                                </span>
                                            )}
                                            <span className="block px-2 py-1.5 text-xs font-medium">{o.label}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        {error && <p className="text-xs text-destructive">{error}</p>}
                    </div>
                );
            })}
        </div>
    );
}

/**
 * All tickets in the order, each with its own copy of the fields.
 *
 * The list order is load-bearing: CheckoutService issues tickets by walking the
 * order items and their quantities in exactly this sequence, and copies
 * answers[n] onto the nth ticket.
 */
export function CustomFieldsSection({
    fields,
    ticketCount,
    answers,
    onChange,
    errors,
}: {
    fields: CustomField[];
    ticketCount: number;
    answers: CustomAnswers[];
    onChange: (next: CustomAnswers[]) => void;
    errors: Record<string, string>;
}) {
    if (fields.length === 0 || ticketCount === 0) {
        return null;
    }

    const setAt = (index: number, next: CustomAnswers) =>
        onChange(Array.from({ length: ticketCount }, (_, i) => (i === index ? next : (answers[i] ?? {}))));

    return (
        <div className="grid gap-5">
            {Array.from({ length: ticketCount }, (_, i) => (
                <div key={i} className={ticketCount > 1 ? 'rounded-xl border border-border p-4' : ''}>
                    {ticketCount > 1 && (
                        <p className="mb-3 text-sm font-semibold">Ticket {i + 1}</p>
                    )}
                    <CustomFieldSet
                        fields={fields}
                        index={i}
                        answers={answers[i] ?? {}}
                        onChange={(next) => setAt(i, next)}
                        errors={errors}
                    />
                </div>
            ))}
        </div>
    );
}
