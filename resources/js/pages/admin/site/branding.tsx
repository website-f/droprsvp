import { Head, useForm } from '@inertiajs/react';
import { ImageUp, Loader2, RotateCcw, Save } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SwitchField } from '@/components/ui/switch';
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

function SizeRow({ label, hint, value, min, max, onChange, previewSrc, invert }: { label: string; hint: string; value: number; min: number; max: number; onChange: (v: number) => void; previewSrc: string; invert: boolean }) {
    return (
        <div className="grid gap-1.5">
            <div className="flex items-center justify-between">
                <Label>{label}</Label>
                <span className="text-xs tabular-nums text-muted-foreground">{value}px</span>
            </div>
            <input type="range" min={min} max={max} value={value} onChange={(e) => onChange(Number(e.target.value))} className="w-full accent-foreground" />
            {/* Live preview — the logo drawn at the exact height, on light + dark. */}
            <div className="grid grid-cols-2 gap-1.5">
                <div className="flex h-16 items-center justify-center overflow-hidden rounded-lg border border-border bg-white px-2">
                    <img src={previewSrc} alt="" style={{ height: value }} className="max-w-full object-contain" />
                </div>
                <div className="flex h-16 items-center justify-center overflow-hidden rounded-lg border border-border bg-neutral-900 px-2">
                    <img src={previewSrc} alt="" style={{ height: value }} className={`max-w-full object-contain ${invert ? 'invert' : ''}`} />
                </div>
            </div>
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
                        <SizeRow label="Header logo" hint="Public site top bar." value={data.header_height} min={24} max={80} onChange={(v) => setData('header_height', v)} previewSrc={data.logo_full || DEFAULTS.logo_full} invert={data.invert_dark} />
                        <SizeRow label="Sidebar logo" hint="Dashboard sidebar." value={data.sidebar_height} min={24} max={72} onChange={(v) => setData('sidebar_height', v)} previewSrc={data.logo_full || DEFAULTS.logo_full} invert={data.invert_dark} />
                        <SizeRow label="Footer logo" hint="Site footer." value={data.footer_height} min={20} max={64} onChange={(v) => setData('footer_height', v)} previewSrc={data.logo_full || DEFAULTS.logo_full} invert={data.invert_dark} />
                        <SizeRow label="Auth &amp; checkout" hint="Sign-up, login &amp; checkout." value={data.auth_height} min={20} max={56} onChange={(v) => setData('auth_height', v)} previewSrc={data.logo_full || DEFAULTS.logo_full} invert={data.invert_dark} />
                    </div>
                    <div className="mt-6 rounded-lg border border-border p-3">
                        <SwitchField
                            checked={data.invert_dark}
                            onCheckedChange={(v) => setData('invert_dark', v)}
                            label="Invert logo in dark mode"
                            description="Keep on for a dark/monochrome logo so it shows on dark backgrounds. Turn off if your logo is already light or full-colour."
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

BrandingPage.layout = {
    breadcrumbs: [{ title: 'Branding', href: '/admin/site/branding' }],
};
