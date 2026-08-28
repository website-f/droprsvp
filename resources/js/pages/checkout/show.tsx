import { Head, Link, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface OrderView {
    reference: string; currency: string; total: number;
    event: { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean };
    items: Array<{ name: string; quantity: number; unit_price: number; line_total: number }>;
}

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

const GENDERS = [{ value: 'na', label: 'Prefer not to say' }, { value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }];
const AGE_BANDS = [{ value: '', label: '—' }, { value: 'under-18', label: 'Under 18' }, { value: '18-24', label: '18–24' }, { value: '25-34', label: '25–34' }, { value: '35-44', label: '35–44' }, { value: '45-54', label: '45–54' }, { value: '55+', label: '55+' }];
const SOURCES = [{ value: '', label: '—' }, { value: 'instagram', label: 'Instagram' }, { value: 'facebook', label: 'Facebook' }, { value: 'tiktok', label: 'TikTok' }, { value: 'friend', label: 'A friend' }, { value: 'search', label: 'Search' }, { value: 'email', label: 'Email' }, { value: 'other', label: 'Other' }];

export default function CheckoutShow({ order }: { order: OrderView }) {
    const form = useForm({ buyer_name: '', buyer_email: '', buyer_phone: '', buyer_gender: 'na', buyer_age_band: '', buyer_city: '', buyer_source: '' });
    const isFree = order.total <= 0;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/checkout/${order.reference}/pay`);
    };

    return (
        <>
            <Head title={`Checkout · ${order.event.title}`} />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto max-w-3xl px-6 py-4">
                        <Link href={`/e/${order.event.slug}`} className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
                    </div>
                </header>

                <main className="mx-auto grid max-w-3xl gap-8 px-6 py-10 md:grid-cols-[1fr_300px]">
                    {/* Buyer details */}
                    <form onSubmit={submit}>
                        <h1 className="text-2xl font-bold tracking-tight">Checkout</h1>
                        <p className="mt-1 text-sm text-muted-foreground">Enter your details to {isFree ? 'register' : 'pay'}.</p>

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
                                <Label htmlFor="buyer_phone">Phone (optional)</Label>
                                <input id="buyer_phone" className={field} value={form.data.buyer_phone} onChange={(e) => form.setData('buyer_phone', e.target.value)} />
                            </div>

                            {/* About you (optional) — helps the organizer understand who's coming. */}
                            <div className="rounded-xl border border-border bg-muted/30 p-4">
                                <p className="mb-3 text-xs font-medium text-muted-foreground">About you <span className="font-normal">(optional — helps the organizer)</span></p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label>Gender</Label>
                                        <AppSelect value={form.data.buyer_gender} onChange={(v) => form.setData('buyer_gender', v)} options={GENDERS} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Age</Label>
                                        <AppSelect value={form.data.buyer_age_band || ''} onChange={(v) => form.setData('buyer_age_band', v)} options={AGE_BANDS} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="buyer_city">City</Label>
                                        <input id="buyer_city" className={field} value={form.data.buyer_city} onChange={(e) => form.setData('buyer_city', e.target.value)} placeholder="e.g. Kuala Lumpur" />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>How did you hear about it?</Label>
                                        <AppSelect value={form.data.buyer_source || ''} onChange={(v) => form.setData('buyer_source', v)} options={SOURCES} />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <Button type="submit" size="lg" className="mt-6 w-full" disabled={form.processing}>
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
                            <div className="flex justify-between font-semibold">
                                <span>Total</span><span>RM {order.total.toFixed(2)}</span>
                            </div>
                        </div>
                        <p className="mt-3 text-center text-xs text-muted-foreground">Ref {order.reference}</p>
                    </aside>
                </main>
            </div>
        </>
    );
}
