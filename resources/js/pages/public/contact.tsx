import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Headset, Mail, MessageSquare, Phone, Send, Tag, User } from 'lucide-react';
import { useState } from 'react';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface Category { value: string; label: string }
interface Seo { title: string }

const field = 'h-11 w-full rounded-xl border border-input bg-card px-3.5 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function Contact({ categories, seo }: { categories: Category[]; seo: Seo }) {
    const [sent, setSent] = useState(false);
    const form = useForm({ name: '', email: '', phone: '', category: 'enquiry', message: '' });
    const { data, setData, processing, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/contact', { preserveScroll: true, onSuccess: () => {
            form.reset(); setSent(true);
        } });
    };

    return (
        <>
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto grid w-full max-w-5xl flex-1 gap-10 px-6 py-12 lg:grid-cols-[1fr_1.2fr]">
                    {/* Intro */}
                    <div>
                        <span className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium text-muted-foreground"><Headset className="size-3.5" /> We're here to help</span>
                        <h1 className="mt-4 text-3xl font-bold tracking-tight sm:text-4xl">Get in touch</h1>
                        <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                            Questions about tickets, your events, or working with us? Send a message and the right team will get back to you.
                        </p>
                        <ul className="mt-8 grid gap-4 text-sm">
                            {[[Headset, 'Support', 'Help with tickets, check-in and your account'], [Tag, 'Sales', 'Pricing, demos and partnerships'], [MessageSquare, 'General enquiry', 'Anything else on your mind']].map(([Icon, t, d], i) => (
                                <li key={i} className="flex items-start gap-3">
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-foreground text-background"><Icon className="size-4" /></span>
                                    <div><div className="font-medium">{t as string}</div><div className="text-muted-foreground">{d as string}</div></div>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Form */}
                    <div className="rounded-2xl border border-border bg-card p-6 shadow-sm sm:p-8">
                        {sent ? (
                            <div className="flex flex-col items-center gap-3 py-12 text-center">
                                <span className="flex size-14 items-center justify-center rounded-2xl bg-foreground text-background"><CheckCircle2 className="size-7" /></span>
                                <h2 className="text-xl font-bold tracking-tight">Message sent</h2>
                                <p className="max-w-sm text-sm text-muted-foreground">Thanks for reaching out — we'll get back to you soon.</p>
                                <Button variant="outline" className="mt-2" onClick={() => setSent(false)}>Send another message</Button>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="grid gap-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">Name</Label>
                                    <div className="relative">
                                        <User className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <input id="name" className={`${field} pl-9`} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Your name" />
                                    </div>
                                    {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="email">Email</Label>
                                        <div className="relative">
                                            <Mail className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                            <input id="email" type="email" className={`${field} pl-9`} value={data.email} onChange={(e) => setData('email', e.target.value)} placeholder="you@example.com" />
                                        </div>
                                        {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="phone">Phone</Label>
                                        <div className="relative">
                                            <Phone className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                            <input id="phone" className={`${field} pl-9`} value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="+60 12-345 6789" />
                                        </div>
                                        {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label>How can we help?</Label>
                                    <AppSelect
                                        aria-label="Category"
                                        value={data.category}
                                        onChange={(v) => setData('category', v)}
                                        options={categories.map((c) => ({ value: c.value, label: c.label }))}
                                    />
                                    {errors.category && <p className="text-xs text-destructive">{errors.category}</p>}
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="message">Message</Label>
                                    <textarea id="message" rows={5} className="w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" value={data.message} onChange={(e) => setData('message', e.target.value)} placeholder="Tell us a bit more…" />
                                    {errors.message && <p className="text-xs text-destructive">{errors.message}</p>}
                                </div>

                                <Button type="submit" size="lg" className="mt-1" disabled={processing}><Send className="size-4" /> Send message</Button>
                            </form>
                        )}
                    </div>
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
