import { Head, useForm, usePage } from '@inertiajs/react';
import { Banknote, ClipboardList, Percent, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface CheckoutRequired { phone: boolean; gender: boolean; age_band: boolean; city: boolean; source: boolean; notes: boolean }
interface SettingsData {
    fee_percent: number; boost_price: number; boost_days: number; premium_price: number; premium_days: number;
    tax_percent: number; tax_label: string; tax_inclusive: boolean; support_email: string;
    checkout_required: CheckoutRequired;
}

const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

type Tab = 'payments' | 'tax' | 'checkout' | 'general';

const CHECKOUT_FIELDS: { key: keyof CheckoutRequired; label: string }[] = [
    { key: 'phone', label: 'Phone number' },
    { key: 'gender', label: 'Gender' },
    { key: 'age_band', label: 'Age band' },
    { key: 'city', label: 'City' },
    { key: 'source', label: 'How they heard about it' },
    { key: 'notes', label: 'Notes / remarks' },
];

function Toggle({ on, onChange }: { on: boolean; onChange: (v: boolean) => void }) {
    return (
        <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors ${on ? 'bg-foreground' : 'bg-input'}`}>
            <span className={`inline-block size-5 rounded-full bg-background shadow transition-transform ${on ? 'translate-x-[1.375rem]' : 'translate-x-0.5'}`} />
        </button>
    );
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

export default function Settings({ settings }: { settings: SettingsData }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const [tab, setTab] = useState<Tab>('payments');
    const form = useForm({
        fee_percent: String(settings.fee_percent),
        boost_price: String(settings.boost_price),
        boost_days: String(settings.boost_days),
        premium_price: String(settings.premium_price),
        premium_days: String(settings.premium_days),
        tax_percent: String(settings.tax_percent),
        tax_label: settings.tax_label ?? '',
        tax_inclusive: settings.tax_inclusive,
        support_email: settings.support_email ?? '',
        checkout_required: settings.checkout_required,
    });
    const { data, setData, processing } = form;
    const setRequired = (key: keyof CheckoutRequired, v: boolean) => setData('checkout_required', { ...data.checkout_required, [key]: v });

    const TABS: { key: Tab; label: string; icon: typeof Banknote }[] = [
        { key: 'payments', label: 'Payments & fees', icon: Banknote },
        { key: 'tax', label: 'Tax', icon: Percent },
        { key: 'checkout', label: 'Checkout', icon: ClipboardList },
        { key: 'general', label: 'General', icon: Settings2 },
    ];

    return (
        <>
            <Head title="Settings" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Settings</h1>
                        <p className="text-sm text-muted-foreground">Platform fees, tax and general configuration.</p>
                    </div>
                    <Button onClick={() => form.post('/admin/settings', { preserveScroll: true })} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                {/* Tabs */}
                <div className="mb-6 flex gap-1 border-b border-border">
                    {TABS.map((t) => {
                        const active = tab === t.key;

                        return (
                            <button key={t.key} type="button" onClick={() => setTab(t.key)}
                                className={`-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors ${active ? 'border-foreground text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'}`}>
                                <t.icon className="size-4" /> {t.label}
                            </button>
                        );
                    })}
                </div>

                <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
                    {tab === 'payments' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Platform fee (%)" hint="Kept from each organizer's gross ticket revenue."><input type="number" min={0} max={100} step="0.1" className={input} value={data.fee_percent} onChange={(e) => setData('fee_percent', e.target.value)} /></Field>
                            <Field label="Event boost price (RM)"><input type="number" min={0} step="1" className={input} value={data.boost_price} onChange={(e) => setData('boost_price', e.target.value)} /></Field>
                            <Field label="Boost duration (days)"><input type="number" min={1} step="1" className={input} value={data.boost_days} onChange={(e) => setData('boost_days', e.target.value)} /></Field>
                            <Field label="Premium price / period (RM)"><input type="number" min={0} step="1" className={input} value={data.premium_price} onChange={(e) => setData('premium_price', e.target.value)} /></Field>
                            <Field label="Premium duration (days)"><input type="number" min={1} step="1" className={input} value={data.premium_days} onChange={(e) => setData('premium_days', e.target.value)} /></Field>
                        </div>
                    )}

                    {tab === 'tax' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Tax rate (%)" hint="0 turns tax off. Applied to ticket subtotals at checkout."><input type="number" min={0} max={100} step="0.1" className={input} value={data.tax_percent} onChange={(e) => setData('tax_percent', e.target.value)} /></Field>
                            <Field label="Tax label" hint="e.g. SST, GST, VAT."><input className={input} value={data.tax_label} onChange={(e) => setData('tax_label', e.target.value)} /></Field>
                            <div className="sm:col-span-2 flex items-center justify-between rounded-lg border border-border p-3">
                                <div><div className="text-sm font-medium">Prices include tax</div><div className="text-xs text-muted-foreground">Show ticket prices as tax-inclusive.</div></div>
                                <Toggle on={data.tax_inclusive} onChange={(v) => setData('tax_inclusive', v)} />
                            </div>
                        </div>
                    )}

                    {tab === 'checkout' && (
                        <div className="grid gap-3">
                            <p className="text-sm text-muted-foreground">Choose which buyer fields are required at checkout. Name and email are always required.</p>
                            {CHECKOUT_FIELDS.map((f) => (
                                <div key={f.key} className="flex items-center justify-between rounded-lg border border-border p-3">
                                    <div className="text-sm font-medium">{f.label}</div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs text-muted-foreground">{data.checkout_required[f.key] ? 'Required' : 'Optional'}</span>
                                        <Toggle on={data.checkout_required[f.key]} onChange={(v) => setRequired(f.key, v)} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === 'general' && (
                        <div className="grid gap-4">
                            <Field label="Support email" hint="Shown to users who need help."><input type="email" className={input} value={data.support_email} onChange={(e) => setData('support_email', e.target.value)} placeholder="support@droprsvp.com" /></Field>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

Settings.layout = { breadcrumbs: [{ title: 'Settings', href: '/admin/settings' }] };
