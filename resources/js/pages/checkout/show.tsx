import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Loader2, Lock, Tag, UserRound, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { BeginCheckoutEvent } from '@/components/analytics-events';
import { Wordmark } from '@/components/brand';
import { CustomFieldsSection   } from '@/components/custom-fields';
import type {CustomAnswers, CustomField} from '@/components/custom-fields';
import { StateCityPicker  } from '@/components/state-city-picker';
import type {CityOption} from '@/components/state-city-picker';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

interface OrderView {
    reference: string; currency: string; total: number;
    subtotal: number; discount: number; discount_code?: string | null; tax: number;
    event: { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean; organizer?: string | null };
    items: Array<{ name: string; quantity: number; unit_price: number; line_total: number }>;
}
const CONSENT_TEXT = 'By submitting this form, you agree to let Drop RSVP use your details to manage your RSVP and provide event updates.';
const MARKETING_TEXT = 'Email me about upcoming events and offers on DropRSVP. Optional — unsubscribe any time.';

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

const GENDERS = [{ value: 'na', label: 'Prefer not to say' }, { value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }];
/** Birth years, newest first — a fact people know, unlike a band they have to place themselves in. */
const BIRTH_YEARS = Array.from({ length: new Date().getFullYear() - 1920 + 1 }, (_, i) => {
    const y = String(new Date().getFullYear() - i);

    return { value: y, label: y };
});
const SOURCES = [{ value: 'instagram', label: 'Instagram' }, { value: 'facebook', label: 'Facebook' }, { value: 'tiktok', label: 'TikTok' }, { value: 'friend', label: 'A friend' }, { value: 'search', label: 'Search' }, { value: 'email', label: 'Email' }, { value: 'other', label: 'Other' }];

/** The marker on a field that has to be filled in. */
const Needed = () => <span className="text-destructive" aria-hidden="true"> *</span>;

/** A filled-in answer to one of the organizer's questions. */
const answered = (value: string | string[] | undefined) => (Array.isArray(value) ? value.length > 0 : !!value?.trim());

interface Buyer { name: string | null; email: string | null; phone: string | null; gender: string | null; birth_year: number | null; city: string | null }

interface Account { name: string; email: string }

export default function CheckoutShow({ order, buyer, cities = [], customFields = [], ticketCount = 0, account = null, loginUrl, analytics = null }: {
    order: OrderView; buyer: Buyer | null;
    cities?: CityOption[];
    customFields?: CustomField[]; ticketCount?: number;
    account?: Account | null; loginUrl?: string;
    analytics?: Record<string, unknown> | null;
}) {
    const form = useForm({
        buyer_name: buyer?.name ?? '',
        buyer_email: buyer?.email ?? '',
        buyer_phone: buyer?.phone ?? '',
        buyer_gender: buyer?.gender ?? 'na',
        buyer_birth_year: buyer?.birth_year != null ? String(buyer.birth_year) : '',
        buyer_city: buyer?.city ?? '',
        buyer_source: '',
        notes: '',
        // One answer set per ticket, in the order tickets are issued.
        custom_answers: Array.from({ length: ticketCount }, () => ({}) as CustomAnswers),
        // Only meaningful for guests: an account is created either way, this just
        // decides whether they land on the confirmation already signed in.
        auto_login: true,
        consent: true,
        // Off by default: marketing consent has to be an active choice.
        marketing_opt_in: false,
        // A separate list per organizer; also off by default.
        organizer_opt_in: false,
    });
    const isFree = order.total <= 0;
    const formRef = useRef<HTMLFormElement>(null);

    const codeForm = useForm({ code: '' });
    const [showCode, setShowCode] = useState(false);
    const applyCode = (e: React.FormEvent) => {
        e.preventDefault();
        codeForm.post(`/checkout/${order.reference}/code`, { preserveScroll: true, onSuccess: () => {
 codeForm.reset(); setShowCode(false);
} });
    };
    const removeCode = () => router.delete(`/checkout/${order.reference}/code`, { preserveScroll: true });

    /**
     * What still has to be filled in, keyed and worded the way the server
     * would say it — so the message lands under the right field before a
     * round trip, rather than after one. CheckoutController::pay and
     * CustomFields::normalise remain the authority; this only saves a wait.
     */
    const stillNeeded = (): Record<string, string> => {
        const out: Record<string, string> = {};
        const d = form.data;

        if (!d.buyer_name.trim()) {
            out.buyer_name = 'Please enter your name.';
        }

        if (!d.buyer_email.trim()) {
            out.buyer_email = 'Please enter your email — your tickets are sent there.';
        }

        if (!d.buyer_phone.trim()) {
            out.buyer_phone = 'Please enter a phone number, in case the organizer needs to reach you.';
        }

        // Only the organizer can make a question compulsory.
        d.custom_answers.forEach((answers, i) => {
            customFields.filter((f) => f.required).forEach((f) => {
                if (!answered(answers[f.id])) {
                    const choice = f.type === 'select' || f.type === 'image_choice';
                    out[`custom_answers.${i}.${f.id}`] = `Please ${choice ? 'choose' : 'fill in'} ${f.label} for ticket ${i + 1}.`;
                }
            });
        });

        if (!d.consent) {
            out.consent = 'Please agree to the terms to continue.';
        }

        return out;
    };

    /** Bring the first message into view, so nobody presses the button and sees nothing happen. */
    const showFirstProblem = () => requestAnimationFrame(() => {
        formRef.current?.querySelector('p.text-destructive')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.clearErrors();

        const problems = stillNeeded();

        if (Object.keys(problems).length > 0) {
            form.setError(problems as never);
            showFirstProblem();

            return;
        }

        form.post(`/checkout/${order.reference}/pay`, { onError: showFirstProblem });
    };

    // While a paid order is submitting, we're creating the gateway checkout and
    // about to hard-redirect the buyer — show a full-screen "redirecting" veil so
    // it never looks frozen (especially on slower connections).
    const redirecting = form.processing && !isFree;

    const errors = form.errors as Record<string, string | undefined>;
    const problemCount = Object.values(errors).filter(Boolean).length;
    const anyRequiredQuestion = customFields.some((f) => f.required);

    return (
        <>
            <BeginCheckoutEvent payload={analytics} />
            <Head title={`Checkout · ${order.event.title}`} />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto max-w-3xl px-6 py-4">
                        <Link href={`/en-my/e/${order.event.slug}/`} aria-label="DropRSVP"><Wordmark height={usePage().props.branding?.auth_height ?? 32} /></Link>
                    </div>
                </header>

                <main className="mx-auto grid grid-cols-1 max-w-3xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-[minmax(0,1fr)_300px]">
                    {/* Summary first in the page, so on a phone the buyer sees what
                        they are paying for before the form rather than after the
                        Pay button. On wider screens it moves to the right. */}
                    <aside className="md:order-last">
                        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <div className="font-semibold">{order.event.title}</div>
                            {order.event.when && <div className="mt-1 text-xs text-muted-foreground">{order.event.when}</div>}
                            <div className="mt-1 text-xs text-muted-foreground">{order.event.is_online ? 'Online event' : order.event.venue_name}</div>

                            <div className="my-4 h-px bg-border" />
                            <div className="grid gap-2 text-sm">
                                {order.items.map((i, idx) => (
                                    <div key={idx} className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">{i.quantity} × {i.name}</span>
                                        <span className="shrink-0">RM {i.line_total.toFixed(2)}</span>
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
                                                className="h-9 min-w-0 flex-1 rounded-lg border border-input bg-card px-3 text-sm uppercase outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
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
                                    {order.tax > 0 && <div className="flex justify-between text-muted-foreground"><span>Tax</span><span>RM {order.tax.toFixed(2)}</span></div>}
                                </div>
                            )}
                            <div className="mt-2 flex justify-between font-semibold">
                                <span>Total</span><span>{isFree ? 'Free' : `RM ${order.total.toFixed(2)}`}</span>
                            </div>
                        </div>
                        <p className="mt-3 text-center text-xs text-muted-foreground">Ref {order.reference}</p>
                    </aside>

                    {/* Buyer details. noValidate: the checks above say what is
                        missing in our own words, under the field, instead of the
                        browser's bubble on the first box only. */}
                    <form ref={formRef} onSubmit={submit} noValidate>
                        <h1 className="text-2xl font-bold tracking-tight">{isFree ? 'Register' : 'Checkout'}</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {buyer
                                ? 'We’ve filled in your details — check them, then '
                                : 'We only need your name, email and phone, then you can '}
                            {isFree ? 'complete your registration' : 'continue to payment'}.
                        </p>

                        {/* Who is checking out. An account is created for every
                            buyer either way — this is about whether they arrive
                            signed in, and lets a returning customer log in first
                            so the order joins the account they already have. */}
                        {account ? (
                            <div className="mt-6 flex items-center gap-3 rounded-xl border border-border bg-muted/30 p-4 text-sm">
                                <UserRound className="size-4 shrink-0 text-muted-foreground" />
                                <span className="min-w-0">
                                    Signed in as <strong>{account.name}</strong>{' '}
                                    <span className="break-all text-muted-foreground">({account.email})</span>
                                </span>
                            </div>
                        ) : (
                            <div className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-muted/30 p-4 text-sm">
                                <span className="text-muted-foreground">
                                    No password needed — we’ll set up an account so you can find your tickets later.
                                </span>
                                {loginUrl && (
                                    <Button asChild type="button" variant="outline" size="sm">
                                        <a href={loginUrl}>Already have an account? Log in</a>
                                    </Button>
                                )}
                            </div>
                        )}

                        {/* 1 — the only part that has to be filled in. */}
                        <section className="mt-6 grid gap-4" aria-labelledby="your-details">
                            <div className="flex items-baseline justify-between gap-3">
                                <h2 id="your-details" className="text-sm font-semibold">Your details</h2>
                                <span className="text-xs text-muted-foreground"><span className="text-destructive">*</span> required</span>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_name">Full name<Needed /></Label>
                                <input id="buyer_name" name="name" autoComplete="name" aria-required="true" aria-invalid={!!errors.buyer_name}
                                    className={field} value={form.data.buyer_name} onChange={(e) => form.setData('buyer_name', e.target.value)} />
                                {errors.buyer_name && <p className="text-xs text-destructive">{errors.buyer_name}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_email">Email<Needed /></Label>
                                <input id="buyer_email" name="email" type="email" inputMode="email" autoComplete="email" aria-required="true" aria-invalid={!!errors.buyer_email}
                                    aria-describedby="buyer_email_hint" placeholder="you@example.com"
                                    className={field} value={form.data.buyer_email} onChange={(e) => form.setData('buyer_email', e.target.value)} />
                                {errors.buyer_email
                                    ? <p className="text-xs text-destructive">{errors.buyer_email}</p>
                                    : <p id="buyer_email_hint" className="text-xs text-muted-foreground">Your {isFree ? 'confirmation' : 'tickets'} will be sent here.</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_phone">Phone<Needed /></Label>
                                <input id="buyer_phone" name="tel" type="tel" inputMode="tel" autoComplete="tel" aria-required="true" aria-invalid={!!errors.buyer_phone}
                                    aria-describedby="buyer_phone_hint" placeholder="012-345 6789"
                                    className={field} value={form.data.buyer_phone} onChange={(e) => form.setData('buyer_phone', e.target.value)} />
                                {errors.buyer_phone
                                    ? <p className="text-xs text-destructive">{errors.buyer_phone}</p>
                                    : <p id="buyer_phone_hint" className="text-xs text-muted-foreground">Only used if the organizer needs to reach you about this event.</p>}
                            </div>
                        </section>

                        {/* 2 — the organizer's own questions, one set per ticket. Ahead
                            of the optional part, because some of these can be required. */}
                        {customFields.length > 0 && (
                            <section className="mt-6 grid gap-4 rounded-xl border border-border p-4" aria-labelledby="organizer-questions">
                                <div>
                                    <h2 id="organizer-questions" className="text-sm font-semibold">Questions from the organizer</h2>
                                    <p className="text-xs text-muted-foreground">
                                        {ticketCount > 1 ? `Asked for each of your ${ticketCount} tickets.` : 'Asked by the organizer of this event.'}
                                        {anyRequiredQuestion && ' Questions marked * need an answer.'}
                                    </p>
                                </div>
                                <CustomFieldsSection
                                    fields={customFields}
                                    ticketCount={ticketCount}
                                    answers={form.data.custom_answers}
                                    onChange={(next) => form.setData('custom_answers', next)}
                                    errors={form.errors as unknown as Record<string, string>}
                                />
                            </section>
                        )}

                        {/* 3 — entirely optional. Helps the organizer understand who
                            is coming; nobody is held up for leaving it. */}
                        <section className="mt-6 rounded-xl border border-border bg-muted/30 p-4" aria-labelledby="about-you">
                            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <h2 id="about-you" className="text-sm font-semibold">About you</h2>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">Optional — skip if you like</span>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="buyer_gender">Gender</Label>
                                    <AppSelect id="buyer_gender" value={form.data.buyer_gender} onChange={(v) => form.setData('buyer_gender', v)} options={GENDERS} />
                                    {errors.buyer_gender && <p className="text-xs text-destructive">{errors.buyer_gender}</p>}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="buyer_birth_year">Year you were born</Label>
                                    <AppSelect id="buyer_birth_year" value={form.data.buyer_birth_year} onChange={(v) => form.setData('buyer_birth_year', v)} options={BIRTH_YEARS} placeholder="Select year" />
                                    {errors.buyer_birth_year && <p className="text-xs text-destructive">{errors.buyer_birth_year}</p>}
                                </div>
                                <StateCityPicker
                                    idPrefix="buyer_city"
                                    cities={cities}
                                    value={form.data.buyer_city}
                                    onChange={(v) => form.setData('buyer_city', v)}
                                    error={errors.buyer_city}
                                />
                                <div className="grid gap-1.5">
                                    <Label htmlFor="buyer_source">How did you hear about it?</Label>
                                    <AppSelect id="buyer_source" value={form.data.buyer_source} onChange={(v) => form.setData('buyer_source', v)} options={SOURCES} placeholder="Select one" />
                                    {errors.buyer_source && <p className="text-xs text-destructive">{errors.buyer_source}</p>}
                                </div>
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor="notes">Notes for the organizer</Label>
                                    <textarea id="notes" rows={3} className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Dietary needs, accessibility, a question…" />
                                    {errors.notes && <p className="text-xs text-destructive">{errors.notes}</p>}
                                </div>
                            </div>
                        </section>

                        <div className="mt-6 grid gap-3">
                            {/* Guests only: a signed-in buyer is already signed in. */}
                            {!account && (
                                <div className="flex items-start gap-3 rounded-xl border border-border p-4 text-sm">
                                    <Switch checked={form.data.auto_login} onCheckedChange={(v) => form.setData('auto_login', v)} aria-label="Keep me signed in" className="mt-0.5" />
                                    <span className="text-muted-foreground">
                                        Keep me signed in after {isFree ? 'registering' : 'payment'}, so I can see my tickets straight away.
                                    </span>
                                </div>
                            )}

                            {/* Consent */}
                            <div className={`flex items-start gap-3 rounded-xl border p-4 text-sm ${errors.consent ? 'border-destructive' : 'border-border'}`}>
                                <Switch checked={form.data.consent} onCheckedChange={(v) => form.setData('consent', v)} aria-label="Agree to terms" className="mt-0.5" />
                                <span className="text-muted-foreground">{CONSENT_TEXT}</span>
                            </div>
                            {errors.consent && <p className="text-xs text-destructive">{errors.consent}</p>}

                            {order.event.organizer && (
                                <div className="flex items-start gap-3 rounded-xl border border-border p-4 text-sm">
                                    <Switch checked={form.data.organizer_opt_in} onCheckedChange={(v) => form.setData('organizer_opt_in', v)} aria-label={`Email me about future events from ${order.event.organizer}`} className="mt-0.5" />
                                    <span className="text-muted-foreground">Email me about future events from <span className="font-medium text-foreground">{order.event.organizer}</span>. Optional — unsubscribe any time.</span>
                                </div>
                            )}

                            <div className="flex items-start gap-3 rounded-xl border border-border p-4 text-sm">
                                <Switch checked={form.data.marketing_opt_in} onCheckedChange={(v) => form.setData('marketing_opt_in', v)} aria-label="Email me about upcoming events" className="mt-0.5" />
                                <span className="text-muted-foreground">{MARKETING_TEXT}</span>
                            </div>
                        </div>

                        {problemCount > 0 && (
                            <p className="mt-6 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive" role="alert">
                                Almost there — please check the highlighted {problemCount === 1 ? 'field' : 'fields'}.
                            </p>
                        )}

                        <Button type="submit" size="lg" className="mt-6 w-full" disabled={form.processing}>
                            <Lock className="size-4" /> {isFree ? 'Complete registration' : `Pay RM ${order.total.toFixed(2)}`}
                        </Button>
                        <p className="mt-2 text-center text-xs text-muted-foreground">
                            {isFree
                                ? 'Your confirmation and tickets are emailed to you straight away.'
                                : 'You’ll pay on our payment provider’s secure page next.'}
                        </p>
                    </form>
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
