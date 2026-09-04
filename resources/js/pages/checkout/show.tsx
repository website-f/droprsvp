import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Loader2, Lock, Tag, X } from 'lucide-react';
import { useState } from 'react';
import { Wordmark } from '@/components/brand';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

interface OrderView {
    reference: string; currency: string; total: number;
    subtotal: number; discount: number; discount_code?: string | null; fees: number; tax: number;
    event: { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean };
    items: Array<{ name: string; quantity: number; unit_price: number; line_total: number }>;
}
interface Required { phone: boolean; gender: boolean; age_band: boolean; city: boolean; source: boolean; notes: boolean }

const CONSENT_TEXT = 'By submitting this form, you agree to let Drop RSVP use your details to manage your RSVP and provide event updates.';

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

const GENDERS = [{ value: 'na', label: 'Prefer not to say' }, { value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }];
const AGE_BANDS = [{ value: '', label: '—' }, { value: 'under-18', label: 'Under 18' }, { value: '18-24', label: '18–24' }, { value: '25-34', label: '25–34' }, { value: '35-44', label: '35–44' }, { value: '45-54', label: '45–54' }, { value: '55+', label: '55+' }];
const SOURCES = [{ value: '', label: '—' }, { value: 'instagram', label: 'Instagram' }, { value: 'facebook', label: 'Facebook' }, { value: 'tiktok', label: 'TikTok' }, { value: 'friend', label: 'A friend' }, { value: 'search', label: 'Search' }, { value: 'email', label: 'Email' }, { value: 'other', label: 'Other' }];

interface Buyer { name: string | null; email: string | null; phone: string | null; gender: string | null; age_band: string | null; city: string | null }

export default function CheckoutShow({ order, required, buyer }: { order: OrderView; required: Required; buyer: Buyer | null }) {
    const form = useForm({
        buyer_name: buyer?.name ?? '',
        buyer_email: buyer?.email ?? '',
        buyer_phone: buyer?.phone ?? '',
        buyer_gender: buyer?.gender ?? 'na',
        buyer_age_band: buyer?.age_band ?? '',
        buyer_city: buyer?.city ?? '',
        buyer_source: '',
        notes: '',
        consent: true,
    });
    const isFree = order.total <= 0;

    const codeForm = useForm({ code: '' });
    const [showCode, setShowCode] = useState(false);
    const applyCode = (e: React.FormEvent) => {
        e.preventDefault();
        codeForm.post(`/checkout/${order.reference}/code`, { preserveScroll: true, onSuccess: () => {
 codeForm.reset(); setShowCode(false); 
} });
    };
    const removeCode = () => router.delete(`/checkout/${order.reference}/code`, { preserveScroll: true });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/checkout/${order.reference}/pay`);
    };

    // While a paid order is submitting, we're creating the gateway checkout and
    // about to hard-redirect the buyer — show a full-screen "redirecting" veil so
    // it never looks frozen (especially on slower connections).
    const redirecting = form.processing && !isFree;

    const req = (label: string, on: boolean) => on ? <>{label} <span className="text-destructive">*</span></> : <>{label}</>;
    const missing =
        !form.data.buyer_name.trim() || !form.data.buyer_email.trim() ||
        (required.phone && !form.data.buyer_phone.trim()) ||
        (required.age_band && !form.data.buyer_age_band) ||
        (required.city && !form.data.buyer_city.trim()) ||
        (required.source && !form.data.buyer_source) ||
        (required.notes && !form.data.notes.trim());
    const canSubmit = form.data.consent && !missing;

    return (
        <>
            <Head title={`Checkout · ${order.event.title}`} />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto max-w-3xl px-6 py-4">
                        <Link href={`/en-my/e/${order.event.slug}`} aria-label="DropRSVP"><Wordmark height={usePage().props.branding?.auth_height ?? 32} /></Link>
                    </div>
                </header>

                <main className="mx-auto grid max-w-3xl gap-8 px-6 py-10 md:grid-cols-[1fr_300px]">
                    {/* Buyer details */}
                    <form onSubmit={submit}>
                        <h1 className="text-2xl font-bold tracking-tight">Checkout</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {buyer ? 'We’ve filled in your details — edit anything below, then ' : 'Enter your details to '}{isFree ? 'register' : 'continue to payment'}.
                        </p>

                        <div className="mt-6 grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_name">Full name</Label>
                                <input id="buyer_name" className={field} value={form.data.buyer_name} onChange={(e) => form.setData('buyer_name', e.target.value)} />
                                {form.errors.buyer_name && <p className="text-xs text-destructive">{form.errors.buyer_name}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_email">Email</Label>
                                <input id="buyer_email" type="email" className={field} value={form.data.buyer_email} onChange={(e) => form.setData('buyer_email', e.target.value)} />
                                {form.errors.buyer_email && <p className="text-xs text-destructive">{form.errors.buyer_email}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_phone">{req('Phone', required.phone)}</Label>
                                <input id="buyer_phone" className={field} value={form.data.buyer_phone} onChange={(e) => form.setData('buyer_phone', e.target.value)} />
                                {form.errors.buyer_phone && <p className="text-xs text-destructive">{form.errors.buyer_phone}</p>}
                            </div>

                            {/* About you (optional) — helps the organizer understand who's coming. */}
                            <div className="rounded-xl border border-border bg-muted/30 p-4">
                                <p className="mb-3 text-xs font-medium text-muted-foreground">About you <span className="font-normal">(optional — helps the organizer)</span></p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label>{req('Gender', required.gender)}</Label>
                                        <AppSelect value={form.data.buyer_gender} onChange={(v) => form.setData('buyer_gender', v)} options={GENDERS} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>{req('Age', required.age_band)}</Label>
                                        <AppSelect value={form.data.buyer_age_band || ''} onChange={(v) => form.setData('buyer_age_band', v)} options={AGE_BANDS} />
                                        {form.errors.buyer_age_band && <p className="text-xs text-destructive">{form.errors.buyer_age_band}</p>}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="buyer_city">{req('City', required.city)}</Label>
                                        <input id="buyer_city" className={field} value={form.data.buyer_city} onChange={(e) => form.setData('buyer_city', e.target.value)} placeholder="e.g. Kuala Lumpur" />
                                        {form.errors.buyer_city && <p className="text-xs text-destructive">{form.errors.buyer_city}</p>}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>{req('How did you hear about it?', required.source)}</Label>
                                        <AppSelect value={form.data.buyer_source || ''} onChange={(v) => form.setData('buyer_source', v)} options={SOURCES} />
                                        {form.errors.buyer_source && <p className="text-xs text-destructive">{form.errors.buyer_source}</p>}
                                    </div>
                                    <div className="grid gap-1.5 sm:col-span-2">
                                        <Label htmlFor="notes">{req('Notes / remarks', required.notes)}</Label>
                                        <textarea id="notes" rows={3} className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Anything the organizer should know? (dietary needs, accessibility, a question…)" />
                                        {form.errors.notes && <p className="text-xs text-destructive">{form.errors.notes}</p>}
                                    </div>
                                </div>
                            </div>

                            {/* Consent */}
                            <div className="flex items-start gap-3 rounded-xl border border-border p-4 text-sm">
                                <Switch checked={form.data.consent} onCheckedChange={(v) => form.setData('consent', v)} aria-label="Agree to terms" className="mt-0.5" />
                                <span className="text-muted-foreground">{CONSENT_TEXT}</span>
                            </div>
                            {form.errors.consent && <p className="text-xs text-destructive">{form.errors.consent}</p>}
                        </div>

                        <Button type="submit" size="lg" className="mt-6 w-full" disabled={form.processing || !canSubmit}>
                            <Lock className="size-4" /> {isFree ? 'Complete registration' : `Pay RM ${order.total.toFixed(2)}`}
                        </Button>
                    </form>

                    {/* Summary */}
                    <aside className="md:order-last">
                        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <div className="font-semibold">{order.event.title}</div>
                            {order.event.when && <div className="mt-1 text-xs text-muted-foreground">{order.event.when}</div>}
                            <div className="mt-1 text-xs text-muted-foreground">{order.event.is_online ? 'Online event' : order.event.venue_name}</div>

                            <div className="my-4 h-px bg-border" />
                            <div className="grid gap-2 text-sm">
                                {order.items.map((i, idx) => (
                                    <div key={idx} className="flex justify-between">
                                        <span className="text-muted-foreground">{i.quantity} × {i.name}</span>
                                        <span>RM {i.line_total.toFixed(2)}</span>
                                    </div>
                                ))}
                            </div>
                            <div className="my-4 h-px bg-border" />

                            {/* Promo code — only meaningful on paid orders */}
                            {!isFree && (
                                <div className="mb-3">
                                    {order.discount_code ? (
                                        <div className="flex items-center justify-between rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm">
                                            <span className="flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400"><Tag className="size-3.5" /> {order.discount_code}</span>
                                            <button type="button" onClick={removeCode} className="text-muted-foreground hover:text-foreground" aria-label="Remove code"><X className="size-3.5" /></button>
                                        </div>
                                    ) : showCode ? (
                                        <form onSubmit={applyCode} className="flex gap-2">
                                            <input autoFocus value={codeForm.data.code} onChange={(e) => codeForm.setData('code', e.target.value)} placeholder="Promo code"
                                                className="h-9 flex-1 rounded-lg border border-input bg-card px-3 text-sm uppercase outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                                            <Button type="submit" size="sm" variant="outline" disabled={codeForm.processing || !codeForm.data.code}>Apply</Button>
                                        </form>
                                    ) : (
                                        <button type="button" onClick={() => setShowCode(true)} className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><Tag className="size-3.5" /> Have a promo code?</button>
                                    )}
                                    {codeForm.errors.code && <p className="mt-1 text-xs text-destructive">{codeForm.errors.code}</p>}
                                </div>
                            )}

                            {!isFree && (
                                <div className="grid gap-1.5 text-sm">
                                    <div className="flex justify-between text-muted-foreground"><span>Subtotal</span><span>RM {order.subtotal.toFixed(2)}</span></div>
                                    {order.discount > 0 && <div className="flex justify-between text-emerald-600 dark:text-emerald-400"><span>Discount</span><span>− RM {order.discount.toFixed(2)}</span></div>}
                                    {order.fees > 0 && <div className="flex justify-between text-muted-foreground"><span>Platform fee</span><span>RM {order.fees.toFixed(2)}</span></div>}
                                    {order.tax > 0 && <div className="flex justify-between text-muted-foreground"><span>Tax</span><span>RM {order.tax.toFixed(2)}</span></div>}
                                </div>
                            )}
                            <div className="mt-2 flex justify-between font-semibold">
                                <span>Total</span><span>RM {order.total.toFixed(2)}</span>
                            </div>
                        </div>
                        <p className="mt-3 text-center text-xs text-muted-foreground">Ref {order.reference}</p>
                    </aside>
                </main>
            </div>

            {/* Redirecting-to-payment veil */}
            {redirecting && (
                <div className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-background/80 px-6 text-center backdrop-blur-sm">
                    <Loader2 className="size-8 animate-spin text-foreground" />
                    <div>
                        <p className="text-base font-semibold">Redirecting to secure payment…</p>
                        <p className="mt-1 text-sm text-muted-foreground">Please don’t close or refresh this page.</p>
                    </div>
                    <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground"><Lock className="size-3.5" /> Payments are processed securely by our payment provider.</div>
                </div>
            )}
        </>
    );
}
