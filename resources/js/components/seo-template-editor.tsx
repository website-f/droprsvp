import { useForm } from '@inertiajs/react';
import { RotateCcw, Save, Wand2 } from 'lucide-react';
import { useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export interface Token { token: string; label: string }

export interface SeoTemplates {
    event_title: string;
    event_description: string;
    organizer_title: string;
    organizer_description: string;
}

type Slot = keyof SeoTemplates;

const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 font-mono text-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-card px-3 py-2 font-mono text-xs leading-relaxed outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/**
 * The house SEO templates — one title and one description per page type,
 * applied to every event and organizer page that has no override of its own.
 *
 * The point of the editor is that you can see what it produces. Each field
 * shows a live preview with the tokens filled in from a worked example, and
 * clicking a token chip inserts it where the cursor is rather than making you
 * remember the spelling.
 */
export function SeoTemplateEditor({
    templates,
    defaults,
    tokens,
    organizerTokens,
}: {
    templates: SeoTemplates;
    defaults: SeoTemplates;
    tokens: Token[];
    organizerTokens: Token[];
}) {
    const form = useForm<SeoTemplates>({ ...templates });
    const refs = useRef<Partial<Record<Slot, HTMLInputElement | HTMLTextAreaElement | null>>>({});

    const insert = (slot: Slot, token: string) => {
        const el = refs.current[slot];
        const value = form.data[slot] ?? '';

        if (!el) {
            form.setData(slot, `${value}${token}`);

            return;
        }

        const start = el.selectionStart ?? value.length;
        const end = el.selectionEnd ?? value.length;
        const next = value.slice(0, start) + token + value.slice(end);

        form.setData(slot, next);

        // Put the caret after what we just inserted, so several chips in a row
        // build a sentence instead of stacking in one spot.
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const dirty = (Object.keys(templates) as Slot[]).some((k) => form.data[k] !== templates[k]);

    return (
        <section className="mb-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-1 flex items-center gap-2 text-sm font-semibold">
                <Wand2 className="size-4" /> Page templates
            </div>
            <p className="mb-4 text-xs text-muted-foreground">
                Applied to every event and organizer page that doesn&rsquo;t have its own override below. Tokens in{' '}
                <code className="rounded bg-muted px-1 py-0.5">{'{braces}'}</code> are replaced with the real values. A token with
                nothing behind it (an online event has no venue) is removed cleanly, along with any stranded comma.
            </p>

            <div className="grid gap-5">
                <Field
                    slot="event_title"
                    label="Event page title"
                    hint="Aim for under 60 characters once the tokens are filled in."
                    form={form}
                    refs={refs}
                    tokens={tokens}
                    onInsert={insert}
                    example={EVENT_EXAMPLE}
                    fallback={defaults.event_title}
                />
                <Field
                    slot="event_description"
                    label="Event meta description"
                    hint="Aim for 120–160 characters."
                    multiline
                    form={form}
                    refs={refs}
                    tokens={tokens}
                    onInsert={insert}
                    example={EVENT_EXAMPLE}
                    fallback={defaults.event_description}
                />
                <Field
                    slot="organizer_title"
                    label="Organizer page title"
                    form={form}
                    refs={refs}
                    tokens={organizerTokens}
                    onInsert={insert}
                    example={ORGANIZER_EXAMPLE}
                    fallback={defaults.organizer_title}
                />
                <Field
                    slot="organizer_description"
                    label="Organizer meta description"
                    multiline
                    form={form}
                    refs={refs}
                    tokens={organizerTokens}
                    onInsert={insert}
                    example={ORGANIZER_EXAMPLE}
                    fallback={defaults.organizer_description}
                />
            </div>

            <div className="mt-5 flex flex-wrap items-center gap-2">
                <Button size="sm" onClick={() => form.post('/admin/seo/templates', { preserveScroll: true })} disabled={!dirty || form.processing}>
                    <Save className="size-3.5" /> Save templates
                </Button>
                <Button size="sm" variant="ghost" onClick={() => form.setData({ ...defaults })}>
                    <RotateCcw className="size-3.5" /> Reset to defaults
                </Button>
            </div>
        </section>
    );
}

/** A worked example, so the preview shows something concrete rather than tokens. */
const EVENT_EXAMPLE: Record<string, string> = {
    '{event_name}': 'Blood on the Clocktower Session',
    '{category}': 'Community',
    '{city}': 'Kajang',
    '{state}': 'Selangor',
    '{venue}': 'HOL Cafe',
    '{date}': '10 Oct 2026',
    '{short_date}': '10 Oct 2026',
    '{day_date}': 'Sat, 10 Oct 2026',
    '{start_time}': '3pm',
    '{organizer}': 'BoardLah Entertainment',
    '{site}': 'DropRSVP',
};

const ORGANIZER_EXAMPLE: Record<string, string> = {
    '{organizer}': 'BoardLah Entertainment',
    '{city}': 'Kajang',
    '{site}': 'DropRSVP',
};

/**
 * Mirrors SeoTemplate::assemble() on the server, so the preview is what will
 * actually be emitted rather than an idealised version of it.
 *
 * An empty token is replaced with a marker, and each marker is then removed
 * together with the preposition, dash or comma that introduced it — otherwise
 * an event with no city previews as "Neon Nights -, 10 Oct 2026".
 */
function preview(template: string, values: Record<string, string>): string {
    const MARK = '\u0000';
    let out = template;

    for (const [token, value] of Object.entries(values)) {
        out = out.split(token).join(value.trim() || MARK);
    }

    // Any remaining {token} has no example value; treat it as empty too.
    out = out.replace(/\{[a-z_]+\}/g, MARK);

    const glue = String.raw`(?:\s*(?:\b(?:at|in|on|by|for|from)\b|[,\-–|·])\s*)`;
    const pattern = new RegExp(`${glue}?${MARK}`, 'u');

    let previous: string;
    do {
        previous = out;
        out = out.replace(new RegExp(pattern.source, 'gu'), '');
    } while (out !== previous);

    out = out
        .replace(/\s{2,}/g, ' ')
        .replace(/\s+([,.])/g, '$1')
        .replace(/^[\s,\-–|·]+/, '')
        .replace(/[\s,\-–|·]+$/, '')
        .trim();

    return out.charAt(0).toUpperCase() + out.slice(1);
}

function Field({
    slot,
    label,
    hint,
    multiline,
    form,
    refs,
    tokens,
    onInsert,
    example,
    fallback,
}: {
    slot: Slot;
    label: string;
    hint?: string;
    multiline?: boolean;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    form: any;
    refs: React.MutableRefObject<Partial<Record<Slot, HTMLInputElement | HTMLTextAreaElement | null>>>;
    tokens: Token[];
    onInsert: (slot: Slot, token: string) => void;
    example: Record<string, string>;
    fallback: string;
}) {
    const value: string = form.data[slot] ?? '';
    // An empty field means "use the shipped default", which is what the server
    // does — so the preview shows that, not an empty string.
    const rendered = preview(value.trim() || fallback, example);
    const error: string | undefined = form.errors[slot];

    return (
        <div className="grid gap-1.5">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Label className="text-xs">{label}</Label>
                <span className={`text-[11px] tabular-nums ${rendered.length > (multiline ? 160 : 60) ? 'text-amber-600 dark:text-amber-500' : 'text-muted-foreground'}`}>
                    {rendered.length} chars
                </span>
            </div>

            {multiline ? (
                <textarea
                    ref={(el) => { refs.current[slot] = el; }}
                    rows={3}
                    className={area}
                    value={value}
                    onChange={(e) => form.setData(slot, e.target.value)}
                    placeholder={fallback}
                />
            ) : (
                <input
                    ref={(el) => { refs.current[slot] = el; }}
                    className={input}
                    value={value}
                    onChange={(e) => form.setData(slot, e.target.value)}
                    placeholder={fallback}
                />
            )}

            <div className="flex flex-wrap gap-1">
                {tokens.map((t) => (
                    <button
                        key={t.token}
                        type="button"
                        onClick={() => onInsert(slot, t.token)}
                        title={t.label}
                        className="rounded border border-border px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        {t.token}
                    </button>
                ))}
            </div>

            <p className="rounded-lg bg-muted/50 px-2.5 py-1.5 text-xs">
                <span className="text-muted-foreground">Preview: </span>
                {rendered}
            </p>

            {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
            {error && <p className="text-[11px] text-destructive">{error}</p>}
        </div>
    );
}
