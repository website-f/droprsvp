import { Head, useForm } from '@inertiajs/react';
import { ImageUp, Loader2, RotateCcw, Save } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { uploadImage } from '@/lib/upload';

interface Branding {
    logo_full: string; logo_mark: string;
    header_height: number; sidebar_height: number; footer_height: number; auth_height: number; invert_dark: boolean;
}

const DEFAULTS = { logo_full: '/logo-full.png', logo_mark: '/logo-mark.png' };

/** A single logo slot: preview on light + dark, upload / remove. */
function LogoSlot({ label, hint, value, fallback, invert, onUpload, onRemove }: {
    label: string; hint: string; value: string; fallback: string; invert: boolean;
    onUpload: (file: File) => void; onRemove: () => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const src = value || fallback;
    const isCustom = !!value && value !== fallback;

    const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];

        if (!file) {
            return;
        }

        setBusy(true);

        try {
            await onUpload(file);
        } finally {
            setBusy(false);

            if (inputRef.current) {
                inputRef.current.value = '';
            }
        }
    };

    return (
        <div className="rounded-2xl border border-border bg-card p-5">
            <div className="flex items-center justify-between">
                <div>
                    <div className="text-sm font-semibold">{label}</div>
                    <div className="text-xs text-muted-foreground">{hint}</div>
                </div>
                {isCustom && <span className="rounded-full bg-secondary px-2 py-0.5 text-[11px] font-medium">Custom</span>}
            </div>

            {/* Previews on light + dark so the invert setting is obvious */}
            <div className="mt-4 grid grid-cols-2 gap-3">
                <div className="flex h-24 items-center justify-center rounded-xl border border-border bg-white p-3">
                    <img src={src} alt={`${label} on light`} className="max-h-full max-w-full object-contain" />
                </div>
                <div className="flex h-24 items-center justify-center rounded-xl border border-border bg-neutral-900 p-3">
                    <img src={src} alt={`${label} on dark`} className={`max-h-full max-w-full object-contain ${invert ? 'invert' : ''}`} />
                </div>
            </div>

            <input ref={inputRef} type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" className="hidden" onChange={pick} />
            <div className="mt-4 flex items-center gap-2">
                <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => inputRef.current?.click()}>
                    {busy ? <Loader2 className="size-3.5 animate-spin" /> : <ImageUp className="size-3.5" />} Upload
                </Button>
                {isCustom && (
                    <Button type="button" variant="ghost" size="sm" onClick={onRemove}><RotateCcw className="size-3.5" /> Reset to default</Button>
                )}
            </div>
        </div>
    );
}

function SizeRow({ label, hint, value, min, max, onChange }: { label: string; hint: string; value: number; min: number; max: number; onChange: (v: number) => void }) {
    return (
        <div className="grid gap-1.5">
            <div className="flex items-center justify-between">
                <Label>{label}</Label>
                <span className="text-xs tabular-nums text-muted-foreground">{value}px</span>
            </div>
            <input type="range" min={min} max={max} value={value} onChange={(e) => onChange(Number(e.target.value))} className="w-full accent-foreground" />
            <p className="text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}

export default function BrandingPage({ branding }: { branding: Branding }) {
    const form = useForm<Branding>({ ...branding });
    const { data, setData, processing } = form;

    const save = () => form.post('/admin/site/branding', { preserveScroll: true, onSuccess: () => toast.success('Branding saved') });

    return (
        <>
            <Head title="Branding" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Branding</h1>
                        <p className="text-sm text-muted-foreground">Your logo across the whole site — upload, resize, or reset to default.</p>
                    </div>
                    <Button onClick={save} disabled={processing}><Save className="size-4" /> {processing ? 'Saving…' : 'Save'}</Button>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <LogoSlot
                        label="Wordmark (full logo)"
                        hint="Shown in the header, footer and sidebar (expanded)."
                        value={data.logo_full}
                        fallback={DEFAULTS.logo_full}
                        invert={data.invert_dark}
                        onUpload={async (file) => setData('logo_full', await uploadImage(file))}
                        onRemove={() => setData('logo_full', '')}
                    />
                    <LogoSlot
                        label="Mark (square icon)"
                        hint="Sidebar (collapsed), favicon and small spots."
                        value={data.logo_mark}
                        fallback={DEFAULTS.logo_mark}
                        invert={data.invert_dark}
                        onUpload={async (file) => setData('logo_mark', await uploadImage(file))}
                        onRemove={() => setData('logo_mark', '')}
                    />
                </div>

                <div className="mt-4 rounded-2xl border border-border bg-card p-5">
                    <h2 className="mb-4 text-sm font-semibold">Size &amp; appearance</h2>
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        <SizeRow label="Header logo" hint="Public site top bar." value={data.header_height} min={24} max={80} onChange={(v) => setData('header_height', v)} />
                        <SizeRow label="Sidebar logo" hint="Dashboard sidebar." value={data.sidebar_height} min={24} max={72} onChange={(v) => setData('sidebar_height', v)} />
                        <SizeRow label="Footer logo" hint="Site footer." value={data.footer_height} min={20} max={64} onChange={(v) => setData('footer_height', v)} />
                        <SizeRow label="Auth &amp; checkout" hint="Sign-up, login &amp; checkout." value={data.auth_height} min={20} max={56} onChange={(v) => setData('auth_height', v)} />
                    </div>
                    <label className="mt-6 flex items-start gap-2.5 rounded-lg border border-border p-3 text-sm">
                        <input type="checkbox" className="mt-0.5 size-4 rounded border-input" checked={data.invert_dark} onChange={(e) => setData('invert_dark', e.target.checked)} />
                        <span>
                            <span className="font-medium">Invert logo in dark mode</span>
                            <span className="mt-0.5 block text-xs text-muted-foreground">Keep on for a dark/monochrome logo so it shows on dark backgrounds. Turn off if your logo is already light or full-colour.</span>
                        </span>
                    </label>
                </div>
            </div>
        </>
    );
}

BrandingPage.layout = {
    breadcrumbs: [{ title: 'Branding', href: '/admin/site/branding' }],
};
