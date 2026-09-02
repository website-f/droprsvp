import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useRef, useState  } from 'react';
import type {ReactNode} from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { uploadImage } from '@/lib/upload';

interface Template {
    accent: string; logo: string; show_logo: boolean; logo_align: 'left' | 'right';
    title: string; header_note: string; notes: string; footer_note: string;
    show_status: boolean; show_context: boolean; show_seller_detail: boolean; show_tax: boolean;
}

const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Field({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function ToggleRow({ label, hint, checked, onChange }: { label: string; hint: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
            <div><div className="text-sm font-medium">{label}</div><div className="text-xs text-muted-foreground">{hint}</div></div>
            <Switch checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

/** A live mock of the receipt PDF that reflects every template option instantly. */
function Preview({ t }: { t: Template }) {
    const logo = t.show_logo && t.logo ? <img src={t.logo} alt="" className="mb-2 h-8 object-contain" /> : null;

    return (
        <div className="rounded-xl border border-border bg-white p-6 text-[11px] leading-relaxed text-[#27272a] shadow-sm">
            <div className="flex items-start justify-between gap-4">
                <div>
                    {t.logo_align === 'left' && logo}
                    <div className="text-[15px] font-bold">DropRSVP</div>
                    {t.show_seller_detail && <div className="text-[10px] text-[#8a8a92]">Sample organizer · Kuala Lumpur</div>}
                </div>
                <div className="text-right">
                    {t.logo_align === 'right' && <div className="flex justify-end">{logo}</div>}
                    <div className="text-[9px] uppercase tracking-wide text-[#8a8a92]">{t.title || 'Receipt'}</div>
                    <div className="text-base font-bold" style={{ color: t.accent }}>DRSVP-SAMPLE</div>
                    <div className="text-[10px] text-[#8a8a92]">{new Date().toLocaleDateString(undefined, { dateStyle: 'medium' })}</div>
                    {t.show_status && <div className="mt-1"><span className="rounded-full bg-[#dcfce7] px-2 py-0.5 text-[9px] font-bold text-[#166534]">paid</span></div>}
                </div>
            </div>
            {t.header_note && <div className="mt-3 font-bold" style={{ color: t.accent }}>{t.header_note}</div>}

            <div className="mt-5 grid grid-cols-2 gap-4">
                <div>
                    <div className="text-[9px] uppercase tracking-wide text-[#8a8a92]">Billed to</div>
                    <div className="font-bold">Jane Doe</div>
                    <div className="text-[#8a8a92]">jane@example.com</div>
                </div>
                {t.show_context && (
                    <div className="text-right">
                        <div className="text-[9px] uppercase tracking-wide text-[#8a8a92]">For</div>
                        <div className="font-bold">Sample Event 2026</div>
                    </div>
                )}
            </div>

            <div className="mt-5 flex justify-between border-y border-[#e7e7ea] py-1 text-[9px] uppercase text-[#8a8a92]"><span>Description</span><span>Amount</span></div>
            <div className="flex justify-between border-b border-[#f0f0f2] py-1.5"><span>General Admission × 2</span><span>MYR 100.00</span></div>
            <div className="flex justify-between border-b border-[#f0f0f2] py-1.5"><span>VIP Table × 1</span><span>MYR 150.00</span></div>
            <div className="mt-2 flex flex-col items-end gap-1">
                <div className="flex w-40 justify-between text-[#8a8a92]"><span>Subtotal</span><span>MYR 250.00</span></div>
                {t.show_tax && <div className="flex w-40 justify-between text-[#8a8a92]"><span>Tax</span><span>MYR 15.00</span></div>}
                <div className="flex w-40 justify-between border-t border-[#e7e7ea] pt-1.5 text-sm font-bold" style={{ color: t.accent }}><span>Total</span><span>MYR 265.00</span></div>
            </div>

            {t.notes.trim() && <div className="mt-5 whitespace-pre-line border-t border-[#e7e7ea] pt-3 text-[10px] text-[#8a8a92]">{t.notes}</div>}
            <div className="mt-5 border-t border-[#e7e7ea] pt-3 text-center text-[9px] text-[#8a8a92]">{t.footer_note || 'powered by DropRSVP'}</div>
        </div>
    );
}

export default function ReceiptEditor({ template }: { template: Template }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm<Template>(template);
    const { data, setData, processing } = form;
    const set = <K extends keyof Template>(key: K, v: Template[K]) => setData(key, v as never);
    const logoRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const onLogo = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);

        try {
            set('logo', await uploadImage(file));
        } finally {
            setUploading(false);
        }
    };

    return (
        <>
            <Head title="Receipt template" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/admin/settings" aria-label="Back to settings"><ArrowLeft className="size-4" /></Link></Button>
                    <div className="flex-1">
                        <h1 className="text-xl font-bold tracking-tight">Receipt &amp; invoice template</h1>
                        <p className="text-sm text-muted-foreground">Brand and lay out the PDF buyers and organizers download.</p>
                    </div>
                    <Button asChild variant="outline"><a href="/admin/site/receipt/preview" target="_blank" rel="noopener"><ExternalLink className="size-4" /> Preview PDF</a></Button>
                    <Button onClick={() => form.post('/admin/site/receipt', { preserveScroll: true })} disabled={processing}>Save</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <div className="grid gap-6 lg:grid-cols-[1fr_1fr]">
                    {/* Controls */}
                    <div className="grid content-start gap-5">
                        <section className="grid gap-3 rounded-xl border border-border bg-card p-4">
                            <h2 className="text-sm font-semibold">Branding</h2>
                            <Field label="Accent colour" hint="Colours the document number, header note and total.">
                                <div className="flex items-center gap-2">
                                    <input type="color" value={data.accent} onChange={(e) => set('accent', e.target.value)} className="h-10 w-14 shrink-0 rounded-lg border border-input bg-card p-1" aria-label="Accent colour" />
                                    <input className={input} value={data.accent} onChange={(e) => set('accent', e.target.value)} />
                                </div>
                            </Field>
                            <ToggleRow label="Show logo" hint="Print a logo in the header." checked={data.show_logo} onChange={(v) => set('show_logo', v)} />
                            {data.show_logo && (
                                <>
                                    <Field label="Logo" hint="PNG or JPG. A wordmark works best.">
                                        <div className="flex items-center gap-3">
                                            {data.logo && <img src={data.logo} alt="" className="h-10 rounded border border-border bg-card object-contain p-1" />}
                                            <input ref={logoRef} type="file" accept="image/*" className="hidden" onChange={(e) => onLogo(e.target.files?.[0])} />
                                            <Button type="button" variant="outline" size="sm" onClick={() => logoRef.current?.click()} disabled={uploading}>{uploading ? 'Uploading…' : 'Upload logo'}</Button>
                                            {data.logo && <Button type="button" variant="ghost" size="sm" onClick={() => set('logo', '')}>Remove</Button>}
                                        </div>
                                    </Field>
                                    <Field label="Logo position">
                                        <div className="inline-flex rounded-lg border border-border p-0.5">
                                            {(['left', 'right'] as const).map((a) => (
                                                <button key={a} type="button" onClick={() => set('logo_align', a)} className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize ${data.logo_align === a ? 'bg-foreground text-background' : 'text-muted-foreground'}`}>{a}</button>
                                            ))}
                                        </div>
                                    </Field>
                                </>
                            )}
                        </section>

                        <section className="grid gap-3 rounded-xl border border-border bg-card p-4">
                            <h2 className="text-sm font-semibold">Content</h2>
                            <Field label="Document title" hint="Blank uses the default (Receipt / Payout receipt)."><input className={input} value={data.title} onChange={(e) => set('title', e.target.value)} placeholder="Receipt" /></Field>
                            <Field label="Header note" hint="A line under the header, e.g. a thank-you."><input className={input} value={data.header_note} onChange={(e) => set('header_note', e.target.value)} placeholder="Thank you for your purchase!" /></Field>
                            <Field label="Notes / terms" hint="Shown above the footer. Refund policy, tax number, etc."><textarea rows={4} className={area} value={data.notes} onChange={(e) => set('notes', e.target.value)} placeholder="Tickets are non-refundable within 48 hours of the event…" /></Field>
                            <Field label="Footer note"><input className={input} value={data.footer_note} onChange={(e) => set('footer_note', e.target.value)} placeholder="powered by DropRSVP" /></Field>
                        </section>

                        <section className="grid gap-3 rounded-xl border border-border bg-card p-4">
                            <h2 className="text-sm font-semibold">Sections</h2>
                            <ToggleRow label="Status badge" hint="The paid / refunded badge." checked={data.show_status} onChange={(v) => set('show_status', v)} />
                            <ToggleRow label="Organizer detail" hint="The seller detail line under the name." checked={data.show_seller_detail} onChange={(v) => set('show_seller_detail', v)} />
                            <ToggleRow label="“For” event block" hint="Which event the receipt is for." checked={data.show_context} onChange={(v) => set('show_context', v)} />
                            <ToggleRow label="Tax row" hint="Show tax in the totals when it applies." checked={data.show_tax} onChange={(v) => set('show_tax', v)} />
                        </section>
                    </div>

                    {/* Live preview */}
                    <div className="lg:sticky lg:top-4 lg:self-start">
                        <div className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">Live preview</div>
                        <Preview t={data} />
                    </div>
                </div>
            </div>
        </>
    );
}

ReceiptEditor.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/admin/settings' },
        { title: 'Receipt template', href: '/admin/site/receipt' },
    ],
};
