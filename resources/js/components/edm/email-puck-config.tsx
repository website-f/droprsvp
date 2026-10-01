import { FieldLabel } from '@measured/puck';
import type { Config, CustomField, Data } from '@measured/puck';
import { imageUpload } from '@/components/cms/puck-config';
import { RichEditor } from '@/components/rich-editor';
import { SearchableSelect } from '@/components/ui/searchable-select';

/**
 * The email builder's blocks.
 *
 * What the canvas shows is a close PREVIEW. The email itself is produced by
 * App\Support\Edm\Renderer on the server — table layout, inline styles — so a
 * block added here must also be taught to the renderer, or it will simply not
 * appear in the sent email (the renderer skips types it does not know).
 *
 * Everything is styled inline rather than with Tailwind classes, so the canvas
 * looks like an email client would draw it rather than like the admin panel.
 */

export interface EventChoice { value: string; label: string; hint?: string | null; image?: string | null }

type Align = 'left' | 'center' | 'right';

export type EmailProps = {
    Heading: { text: string; level: 'h1' | 'h2'; align: Align };
    Text: { html: string; align: Align };
    Button: { label: string; url: string; align: Align; color: string };
    Image: { src: string; alt: string; href: string };
    Divider: Record<string, never>;
    Spacer: { size: 'sm' | 'md' | 'lg' };
    EventCard: { event: string; buttonLabel: string };
};

type RootProps = { brandColor: string; backgroundColor: string; showLogo: boolean };

const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
export const DEFAULT_BRAND = '#6d28d9';

const ALIGN = {
    type: 'radio' as const,
    label: 'Alignment',
    options: [
        { label: 'Left', value: 'left' },
        { label: 'Center', value: 'center' },
        { label: 'Right', value: 'right' },
    ],
};

/* ---- custom fields ------------------------------------------------------ */

const richText: CustomField<string> = {
    type: 'custom',
    render: ({ field, name, value, onChange }) => (
        <FieldLabel label={field.label ?? name}>
            <RichEditor compact value={value ?? ''} onChange={onChange} minHeight="min-h-40" placeholder="Write something…" />
            <p className="mt-1.5 text-[11px] text-muted-foreground">
                Personalise with <code>{'{{first_name}}'}</code>. Links must start with https://.
            </p>
        </FieldLabel>
    ),
};

const colorField = (label: string): CustomField<string> => ({
    type: 'custom',
    render: ({ value, onChange }) => (
        <FieldLabel label={label}>
            <div className="flex items-center gap-2">
                <input type="color" value={value || DEFAULT_BRAND} onChange={(e) => onChange(e.target.value)} className="h-9 w-12 cursor-pointer rounded border border-border bg-transparent" />
                <input type="text" value={value ?? ''} placeholder="Brand colour" onChange={(e) => onChange(e.target.value)} className="h-9 min-w-0 flex-1 rounded border border-border bg-background px-2 text-sm" />
            </div>
        </FieldLabel>
    ),
});

/* ---- config ------------------------------------------------------------- */

export function emailConfig(events: EventChoice[]): Config<EmailProps, RootProps> {
    const eventPicker: CustomField<string> = {
        type: 'custom',
        render: ({ value, onChange }) => (
            <FieldLabel label="Event">
                <SearchableSelect
                    value={value ?? ''}
                    onChange={onChange}
                    options={events.map((e) => ({ value: e.value, label: e.label, hint: e.hint ?? undefined }))}
                    placeholder="Pick an upcoming event"
                    searchPlaceholder="Search events…"
                />
                <p className="mt-1.5 text-[11px] text-muted-foreground">The date, venue, price and artwork fill in by themselves.</p>
            </FieldLabel>
        ),
    };

    return {
        root: {
            fields: {
                brandColor: colorField('Brand colour'),
                backgroundColor: colorField('Background'),
                showLogo: {
                    type: 'radio',
                    options: [
                        { label: 'Show logo', value: true },
                        { label: 'No logo', value: false },
                    ],
                },
            },
            defaultProps: { brandColor: DEFAULT_BRAND, backgroundColor: '#f3f4f6', showLogo: true },
            render: ({ children, backgroundColor, showLogo }) => (
                <div style={{ background: backgroundColor || '#f3f4f6', padding: '24px 12px', minHeight: '100%', fontFamily: FONT }}>
                    {/* The canvas inherits the app's CSS reset, which strips
                        paragraph and list spacing. These mirror the inline
                        styles EmailHtml writes into the sent email, so the
                        canvas spaces text the way an inbox will. */}
                    <style>{`.edm-text p{margin:0 0 14px}.edm-text ul,.edm-text ol{margin:0 0 14px;padding-left:22px}.edm-text ul{list-style:disc}.edm-text ol{list-style:decimal}.edm-text li{margin:0 0 6px}.edm-text a{color:${DEFAULT_BRAND};text-decoration:underline}.edm-text strong,.edm-text b{font-weight:700}.edm-text em,.edm-text i{font-style:italic}.edm-text h2{font-size:20px;font-weight:700;margin:0 0 10px}.edm-text h3{font-size:17px;font-weight:700;margin:0 0 8px}`}</style>
                    <div style={{ maxWidth: 600, margin: '0 auto', background: '#ffffff', borderRadius: 14, padding: '28px 32px 20px' }}>
                        {showLogo && <img src="/logo-full.png" alt="DropRSVP" style={{ height: 32, width: 'auto', display: 'block', marginBottom: 22 }} />}
                        {children}
                    </div>
                    {/* The real footer is added by the server and cannot be
                        removed; this is only what it will look like. */}
                    <div style={{ maxWidth: 600, margin: '0 auto', padding: '18px 24px 8px', textAlign: 'center', fontSize: 12, lineHeight: 1.6, color: '#6b7280' }}>
                        You are receiving this because you opted in to emails from DropRSVP.
                        <br />
                        <u>Unsubscribe</u> · <u>View in browser</u>
                        <br />
                        Added automatically to every email
                    </div>
                </div>
            ),
        },
        components: {
            Heading: {
                label: 'Heading',
                fields: {
                    text: { type: 'textarea', label: 'Heading' },
                    level: { type: 'radio', label: 'Size', options: [{ label: 'Large', value: 'h1' }, { label: 'Medium', value: 'h2' }] },
                    align: ALIGN,
                },
                defaultProps: { text: 'A headline', level: 'h2', align: 'left' },
                render: ({ text, level, align }) => (
                    <div style={{ textAlign: align, fontSize: level === 'h1' ? 28 : 21, lineHeight: 1.25, fontWeight: 700, color: '#111827', paddingBottom: 12, whiteSpace: 'pre-wrap' }}>
                        {text}
                    </div>
                ),
            },
            Text: {
                label: 'Text',
                fields: { html: { ...richText, label: 'Text' }, align: ALIGN },
                defaultProps: { html: '<p>Write your message here.</p>', align: 'left' },
                render: ({ html, align }) => (
                    <div
                        className="edm-text"
                        style={{ textAlign: align, fontSize: 15, lineHeight: 1.6, color: '#374151', paddingBottom: 4 }}
                        // The author's own content in their own editor. The SENT
                        // email is allow-listed on the server (EmailHtml).
                        dangerouslySetInnerHTML={{ __html: html || '' }}
                    />
                ),
            },
            Button: {
                label: 'Button',
                fields: {
                    label: { type: 'text', label: 'Button text' },
                    url: { type: 'text', label: 'Link (https://…)' },
                    align: ALIGN,
                    color: colorField('Colour (blank = brand)'),
                },
                defaultProps: { label: 'Get tickets', url: 'https://www.droprsvp.com/en-my/all/', align: 'center', color: '' },
                // A blank colour uses the brand colour in the SENT email; the
                // canvas shows the default brand, since a block cannot read the
                // root's settings live.
                render: ({ label, align, color }) => (
                    <div style={{ textAlign: align, padding: '8px 0 16px' }}>
                        <span style={{ display: 'inline-block', padding: '13px 28px', borderRadius: 999, background: color || DEFAULT_BRAND, color: '#fff', fontWeight: 700, fontSize: 15 }}>
                            {label || 'Button'}
                        </span>
                    </div>
                ),
            },
            Image: {
                label: 'Image',
                fields: {
                    src: imageUpload,
                    alt: { type: 'text', label: 'Description (for screen readers and blocked images)' },
                    href: { type: 'text', label: 'Link (optional)' },
                },
                defaultProps: { src: '', alt: '', href: '' },
                render: ({ src, alt }) => src ? (
                    <img src={src} alt={alt} style={{ display: 'block', width: '100%', height: 'auto', borderRadius: 10, marginBottom: 16 }} />
                ) : (
                    <div style={{ border: '1px dashed #d1d5db', borderRadius: 10, padding: 28, textAlign: 'center', color: '#9ca3af', fontSize: 13, marginBottom: 16 }}>
                        Upload an image in the panel →
                    </div>
                ),
            },
            Divider: {
                label: 'Divider',
                fields: {},
                defaultProps: {},
                render: () => <div style={{ borderTop: '1px solid #e5e7eb', margin: '8px 0 16px' }} />,
            },
            Spacer: {
                label: 'Spacer',
                fields: { size: { type: 'radio', label: 'Height', options: [{ label: 'Small', value: 'sm' }, { label: 'Medium', value: 'md' }, { label: 'Large', value: 'lg' }] } },
                defaultProps: { size: 'md' },
                render: ({ size }) => <div style={{ height: size === 'sm' ? 8 : size === 'lg' ? 40 : 20 }} />,
            },
            EventCard: {
                label: 'Event card',
                fields: { event: eventPicker, buttonLabel: { type: 'text', label: 'Button text' } },
                defaultProps: { event: '', buttonLabel: 'Get tickets' },
                render: ({ event, buttonLabel }) => {
                    const chosen = events.find((e) => e.value === event);

                    if (!chosen) {
                        return (
                            <div style={{ border: '1px dashed #d1d5db', borderRadius: 12, padding: 24, textAlign: 'center', color: '#9ca3af', fontSize: 13, marginBottom: 18 }}>
                                Pick an event in the panel →
                            </div>
                        );
                    }

                    return (
                        <div style={{ border: '1px solid #e5e7eb', borderRadius: 12, overflow: 'hidden', marginBottom: 18 }}>
                            {chosen.image && <img src={chosen.image} alt="" style={{ display: 'block', width: '100%', height: 'auto' }} />}
                            <div style={{ padding: '18px 20px 20px' }}>
                                <div style={{ fontSize: 18, fontWeight: 700, color: '#111827', marginBottom: 6 }}>{chosen.label}</div>
                                {chosen.hint && <div style={{ fontSize: 14, color: '#4b5563' }}>{chosen.hint}</div>}
                                <span style={{ display: 'inline-block', marginTop: 14, padding: '11px 22px', borderRadius: 999, background: DEFAULT_BRAND, color: '#fff', fontWeight: 700, fontSize: 14 }}>
                                    {buttonLabel || 'Get tickets'}
                                </span>
                            </div>
                        </div>
                    );
                },
            },
        },
    };
}

export const emptyEmail: Data = { root: { props: {} }, content: [] };
