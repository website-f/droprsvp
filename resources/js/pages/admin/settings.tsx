import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArmchairIcon, Banknote, ClipboardList, Flame, Percent, ReceiptText, Send, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { TagInput } from '@/components/ui/tag-input';

interface CheckoutRequired { phone: boolean; gender: boolean; age_band: boolean; city: boolean; source: boolean; notes: boolean }
interface TicketingModes { general: boolean; reserved: boolean; tables: boolean }
interface Announcement { active: boolean; style: string; level: string; title: string; body: string; cta_label: string; cta_url: string; version: number }
interface SettingsData {
    fee_percent: number; boost_price: number; boost_days: number; premium_price: number; premium_days: number;
    tax_percent: number; tax_label: string; tax_inclusive: boolean; support_email: string;
    checkout_required: CheckoutRequired; ticketing_modes: TicketingModes; announcement: Announcement; trending_keywords: string;
}

const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

type Tab = 'payments' | 'tax' | 'checkout' | 'ticketing' | 'search' | 'general';

const CHECKOUT_FIELDS: { key: keyof CheckoutRequired; label: string }[] = [
    { key: 'phone', label: 'Phone number' },
    { key: 'gender', label: 'Gender' },
    { key: 'age_band', label: 'Age band' },
    { key: 'city', label: 'City' },
    { key: 'source', label: 'How they heard about it' },
    { key: 'notes', label: 'Notes / remarks' },
];

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
        ticketing_modes: settings.ticketing_modes,
        announcement: settings.announcement,
        trending_keywords: settings.trending_keywords ?? '',
    });
    const { data, setData, processing } = form;
    const setRequired = (key: keyof CheckoutRequired, v: boolean) => setData('checkout_required', { ...data.checkout_required, [key]: v });
    const setMode = (key: keyof TicketingModes, v: boolean) => setData('ticketing_modes', { ...data.ticketing_modes, [key]: v });
    const setAnnounce = (key: keyof Announcement, v: string | boolean) => setData('announcement', { ...data.announcement, [key]: v });

    // A broadcast is a separate one-shot action (its own endpoint), not part of the settings save.
    const broadcast = useForm({ audience: 'all', title: '', body: '', url: '', level: 'info' });

    const TABS: { key: Tab; label: string; icon: typeof Banknote }[] = [
        { key: 'payments', label: 'Payments & fees', icon: Banknote },
        { key: 'tax', label: 'Tax', icon: Percent },
        { key: 'checkout', label: 'Checkout', icon: ClipboardList },
        { key: 'ticketing', label: 'Ticketing', icon: ArmchairIcon },
        { key: 'search', label: 'Search', icon: Flame },
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
                                <Switch checked={data.tax_inclusive} onCheckedChange={(v) => setData("tax_inclusive", v)} />
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
                                        <Switch checked={data.checkout_required[f.key]} onCheckedChange={(v) => setRequired(f.key, v)} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === 'ticketing' && (
                        <div className="grid gap-3">
                            <p className="text-sm text-muted-foreground">Choose which ticketing modes organizers can use when building an event. General admission is always available.</p>
                            <div className="flex items-center justify-between rounded-lg border border-border p-3 opacity-70">
                                <div><div className="text-sm font-medium">General admission</div><div className="text-xs text-muted-foreground">Free / paid / donation tickets. Always on.</div></div>
                                <Switch checked disabled onCheckedChange={() => {}} aria-label="General admission (always on)" />
                            </div>
                            <div className="flex items-center justify-between rounded-lg border border-border p-3">
                                <div><div className="text-sm font-medium">Reserved seating</div><div className="text-xs text-muted-foreground">Seat maps with a stage — for concerts &amp; theatres.</div></div>
                                <Switch checked={data.ticketing_modes.reserved} onCheckedChange={(v) => setMode('reserved', v)} />
                            </div>
                            <div className="flex items-center justify-between rounded-lg border border-border p-3">
                                <div><div className="text-sm font-medium">Table management</div><div className="text-xs text-muted-foreground">Banquet tables with capacity &amp; auto-assign — for dinners &amp; galas.</div></div>
                                <Switch checked={data.ticketing_modes.tables} onCheckedChange={(v) => setMode('tables', v)} />
                            </div>
                        </div>
                    )}

                    {tab === 'general' && (
                        <div className="grid gap-6 lg:grid-cols-2">
                            {/* Announcement banner / modal (saved with Settings) */}
                            <div className="grid content-start gap-3">
                                <h2 className="text-sm font-semibold">Site announcement</h2>
                                <p className="text-sm text-muted-foreground">A banner or first-load modal shown across the public site. Editing it re-shows for everyone.</p>
                                <div className="flex items-center justify-between rounded-lg border border-border p-3">
                                    <div><div className="text-sm font-medium">Show announcement</div><div className="text-xs text-muted-foreground">Turn the banner / modal on or off.</div></div>
                                    <Switch checked={data.announcement.active} onCheckedChange={(v) => setAnnounce('active', v)} />
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Style"><AppSelect value={data.announcement.style} onChange={(v) => setAnnounce('style', v)} options={[{ value: 'banner', label: 'Top banner' }, { value: 'modal', label: 'Popup modal' }]} /></Field>
                                    <Field label="Tone"><AppSelect value={data.announcement.level} onChange={(v) => setAnnounce('level', v)} options={[{ value: 'info', label: 'Info' }, { value: 'success', label: 'Success' }, { value: 'warning', label: 'Warning' }]} /></Field>
                                </div>
                                <Field label="Title"><input className={input} value={data.announcement.title} onChange={(e) => setAnnounce('title', e.target.value)} placeholder="Big news!" /></Field>
                                <Field label="Message"><textarea rows={2} className={area} value={data.announcement.body} onChange={(e) => setAnnounce('body', e.target.value)} /></Field>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Button label"><input className={input} value={data.announcement.cta_label} onChange={(e) => setAnnounce('cta_label', e.target.value)} placeholder="Learn more" /></Field>
                                    <Field label="Button link"><input className={input} value={data.announcement.cta_url} onChange={(e) => setAnnounce('cta_url', e.target.value)} placeholder="/en-my/all" /></Field>
                                </div>
                                <p className="text-xs text-muted-foreground">Announcement saves with the <strong>Save changes</strong> button above.</p>
                            </div>

                            {/* Broadcast — separate one-shot push to inboxes */}
                            <div className="grid content-start gap-3 rounded-xl border border-border bg-card p-4">
                                <h2 className="flex items-center gap-2 text-sm font-semibold"><Send className="size-4" /> Send a broadcast</h2>
                                <p className="text-sm text-muted-foreground">Push a notification to the bell inbox of a group of users. Sends immediately.</p>
                                <Field label="Audience"><AppSelect value={broadcast.data.audience} onChange={(v) => broadcast.setData('audience', v)} options={[{ value: 'all', label: 'Everyone' }, { value: 'organizers', label: 'Organizers' }, { value: 'buyers', label: 'Buyers' }, { value: 'admins', label: 'Admins' }]} /></Field>
                                <Field label="Title"><input className={input} value={broadcast.data.title} onChange={(e) => broadcast.setData('title', e.target.value)} placeholder="Scheduled maintenance" /></Field>
                                <Field label="Message"><textarea rows={2} className={area} value={broadcast.data.body} onChange={(e) => broadcast.setData('body', e.target.value)} /></Field>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Link (optional)"><input className={input} value={broadcast.data.url} onChange={(e) => broadcast.setData('url', e.target.value)} placeholder="/premium" /></Field>
                                    <Field label="Tone"><AppSelect value={broadcast.data.level} onChange={(v) => broadcast.setData('level', v)} options={[{ value: 'info', label: 'Info' }, { value: 'success', label: 'Success' }, { value: 'warning', label: 'Warning' }]} /></Field>
                                </div>
                                <div>
                                    <Button type="button" onClick={() => broadcast.post('/admin/broadcast', { preserveScroll: true, onSuccess: () => broadcast.reset('title', 'body', 'url') })} disabled={broadcast.processing || !broadcast.data.title.trim()}>
                                        <Send className="size-4" /> {broadcast.processing ? 'Sending…' : 'Send broadcast'}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}

                    {tab === 'search' && (
                        <div className="grid gap-3">
                            <div className="flex items-center gap-2 text-sm font-medium"><Flame className="size-4 text-[#f5924a]" /> Trending keywords</div>
                            <p className="text-sm text-muted-foreground">Pin your own “what’s hot” terms — they show first (with a fire icon) in the global search box, combined with system suggestions.</p>
                            <TagInput value={data.trending_keywords} onChange={(v) => setData('trending_keywords', v)} placeholder="Type a keyword, press Enter…" />
                        </div>
                    )}

                    {tab === 'general' && (
                        <div className="grid gap-4">
                            <Field label="Support email" hint="Shown to users who need help."><input type="email" className={input} value={data.support_email} onChange={(e) => setData('support_email', e.target.value)} placeholder="support@droprsvp.com" /></Field>

                            <Link href="/admin/site/receipt" className="flex items-center justify-between rounded-lg border border-border p-3 transition-colors hover:border-foreground/30 hover:bg-muted/40">
                                <div className="flex items-center gap-3">
                                    <span className="flex size-9 items-center justify-center rounded-lg bg-muted"><ReceiptText className="size-4" /></span>
                                    <div><div className="text-sm font-medium">Receipt &amp; invoice template</div><div className="text-xs text-muted-foreground">Branding, content and layout of the PDF buyers download.</div></div>
                                </div>
                                <span className="text-sm font-medium text-muted-foreground">Open editor →</span>
                            </Link>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

Settings.layout = { breadcrumbs: [{ title: 'Settings', href: '/admin/settings' }] };
