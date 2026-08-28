import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface HomeSeo { title: string; description: string; keywords: string }

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Count({ value, max }: { value: string; max: number }) {
    const len = value.length;

    return <span className={`text-xs ${len > max ? 'text-destructive' : 'text-muted-foreground'}`}>{len}/{max}</span>;
}

export default function HomeSeoSettings({ seo }: { seo: HomeSeo }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const [baseUrl] = useState(() => (typeof window !== 'undefined' ? window.location.origin : ''));

    const form = useForm<HomeSeo>({ title: seo.title ?? '', description: seo.description ?? '', keywords: seo.keywords ?? '' });
    const { data, setData, processing } = form;

    const save = () => form.post('/admin/site/home-seo', { preserveScroll: true });

    return (
        <>
            <Head title="Homepage SEO" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Homepage SEO</h1>
                        <p className="text-sm text-muted-foreground">The landing page design is fixed — here you tune how it appears in search &amp; social.</p>
                    </div>
                    <Button onClick={save} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
                    {/* Google snippet preview */}
                    <div className="mb-5 rounded-lg border border-border bg-muted/40 p-4">
                        <div className="truncate text-xs text-emerald-700 dark:text-emerald-400">{baseUrl || 'https://example.com'}</div>
                        <div className="mt-0.5 truncate text-base text-blue-700 dark:text-blue-400">{data.title || 'Your homepage title'}</div>
                        <div className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{data.description || 'Add a description to control how the homepage appears in search results.'}</div>
                    </div>

                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between"><Label>Title</Label><Count value={data.title} max={60} /></div>
                            <input className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="DropRSVP — Discover events near you" />
                        </div>
                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between"><Label>Description</Label><Count value={data.description} max={155} /></div>
                            <textarea rows={3} className={area} value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Find events happening near you and get tickets…" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Keywords</Label>
                            <input className={field} value={data.keywords} onChange={(e) => setData('keywords', e.target.value)} placeholder="events, tickets, kuala lumpur, concerts" />
                            <p className="text-xs text-muted-foreground">Comma-separated. Rendered as the <code>meta keywords</code> tag. Leave any field blank to use the built-in default.</p>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
