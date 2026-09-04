import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import { Switch } from '@/components/ui/switch';
import { uploadImage } from '@/lib/upload';

interface City { name: string; slug: string }
interface Banner { image: string; heading: string; subheading: string; cta_label: string; cta_url: string; align?: 'left' | 'center' | 'right' }

interface Sections {
    hero: { style: 'classic' | 'banners'; autoplay: boolean; interval: number; banners: Banner[] };
    organizer: { enabled: boolean; heading: string; body: string; cta_label: string; cta_url: string; image: string };
    event_time: { enabled: boolean; heading: string; items: { label: string; value: string }[] };
    nearby_cities: { enabled: boolean; heading: string; cities: string[] };
    featured_organizers: { enabled: boolean; heading: string; subheading: string };
    contact: { enabled: boolean; heading: string; subheading: string };
    showcase: { enabled: boolean };
    seo_text: { enabled: boolean; heading: string; body: string };
}

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Toggle({ on, onChange, label }: { on: boolean; onChange: (v: boolean) => void; label: string }) {
    return (
        <div className="flex shrink-0 items-center gap-2">
            <span className="whitespace-nowrap text-xs font-medium text-muted-foreground">{label}</span>
            <Switch checked={on} onCheckedChange={onChange} aria-label={label} />
        </div>
    );
}

function Card({ children }: { children: React.ReactNode }) {
    return (
        <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
            <div className="grid gap-4">{children}</div>
        </section>
    );
}

/** Recursively replace null with '' so text inputs stay controlled (no React warnings). */
function noNulls<T>(v: T): T {
    if (v === null) {
        return '' as unknown as T;
    }

    if (Array.isArray(v)) {
        return v.map((x) => noNulls(x)) as unknown as T;
    }

    if (v && typeof v === 'object') {
        return Object.fromEntries(Object.entries(v).map(([k, val]) => [k, noNulls(val)])) as T;
    }

    return v;
}

export default function LandingSettings({ sections, cities = [] }: { sections: Sections; cities?: City[] }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm<Sections>(noNulls(sections));
    const { data, setData, processing } = form;
    const [uploading, setUploading] = useState(false);

    const patch = <K extends keyof Sections>(key: K, val: Partial<Sections[K]>) => setData({ ...data, [key]: { ...data[key], ...val } });

    const uploadOrg = async (file?: File) => {
        if (!file) {
return;
}

        setUploading(true);

        try {
 patch('organizer', { image: await uploadImage(file) }); 
} catch { /* keep */ } finally {
 setUploading(false); 
}
    };

    // Hero banners.
    const emptyBanner = (): Banner => ({ image: '', heading: '', subheading: '', cta_label: '', cta_url: '', align: 'center' });
    const patchHero = (val: Partial<Sections['hero']>) => setData({ ...data, hero: { ...data.hero, ...val } });
    const patchBanner = (i: number, val: Partial<Banner>) => patchHero({ banners: data.hero.banners.map((b, idx) => (idx === i ? { ...b, ...val } : b)) });
    const uploadBanner = async (i: number, file?: File) => {
        if (!file) {
            return;
        }

        setUploading(true);

        try {
            patchBanner(i, { image: await uploadImage(file) });
        } catch { /* keep */ } finally {
            setUploading(false);
        }
    };

    const save = () => form.post('/admin/site/landing', { preserveScroll: true });

    return (
        <>
            <Head title="Landing sections" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Landing sections</h1>
                        <p className="text-sm text-muted-foreground">Toggle and edit the optional sections on your home page.</p>
                    </div>
                    <Button onClick={save} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <div className="grid gap-6">
                    {/* Hero */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Hero</h2>
                            <div className="inline-flex rounded-lg border border-border p-0.5">
                                <button type="button" onClick={() => patchHero({ style: 'classic' })} className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${data.hero.style !== 'banners' ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'}`}>Classic</button>
                                <button type="button" onClick={() => {
 patchHero({ style: 'banners' });

 if (data.hero.banners.length === 0) {
patchHero({ style: 'banners', banners: [emptyBanner()] });
} 
}} className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${data.hero.style === 'banners' ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'}`}>Banners</button>
                            </div>
                        </div>

                        {data.hero.style !== 'banners' ? (
                            <p className="text-sm text-muted-foreground">The built-in hero (headline + Browse / Create buttons). Search lives in the top bar, so there’s no search box here.</p>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-4">
                                    <Toggle on={data.hero.autoplay} onChange={(v) => patchHero({ autoplay: v })} label={data.hero.autoplay ? 'Auto-swipe on' : 'Auto-swipe off'} />
                                    <div className="flex items-center gap-2">
                                        <Label className="text-xs">Seconds / slide</Label>
                                        <input type="number" min={2} max={15} className="h-9 w-20 rounded-lg border border-input bg-background px-2 text-sm" value={data.hero.interval} onChange={(e) => patchHero({ interval: Math.max(2, Math.min(15, +e.target.value || 5)) })} />
                                    </div>
                                </div>

                                {data.hero.banners.map((b, i) => (
                                    <div key={i} className="grid gap-3 rounded-lg border border-border p-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-semibold text-muted-foreground">Banner {i + 1}</span>
                                            <Button type="button" variant="ghost" size="icon" aria-label="Remove banner" onClick={() => patchHero({ banners: data.hero.banners.filter((_, idx) => idx !== i) })}><Trash2 className="size-4" /></Button>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {b.image && <img src={b.image} alt="" className="h-16 w-28 rounded-lg border border-border object-cover" />}
                                            <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-input bg-background px-3 text-sm">
                                                <Upload className="size-4" />{uploading ? 'Uploading…' : (b.image ? 'Replace image' : 'Upload image')}
                                                <input type="file" accept="image/*" hidden onChange={(e) => uploadBanner(i, e.target.files?.[0])} />
                                            </label>
                                            <span className="text-xs text-muted-foreground">Recommended 1200×420</span>
                                        </div>
                                        <div className="grid gap-1.5"><Label className="text-xs">Heading</Label><input className={field} value={b.heading} onChange={(e) => patchBanner(i, { heading: e.target.value })} placeholder="From pop ballads to emo encores" /></div>
                                        <div className="grid gap-1.5"><Label className="text-xs">Subheading</Label><input className={field} value={b.subheading} onChange={(e) => patchBanner(i, { subheading: e.target.value })} /></div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="grid gap-1.5"><Label className="text-xs">Button label</Label><input className={field} value={b.cta_label} onChange={(e) => patchBanner(i, { cta_label: e.target.value })} placeholder="Get Into Live Music" /></div>
                                            <div className="grid gap-1.5"><Label className="text-xs">Button link</Label><input className={field} value={b.cta_url} onChange={(e) => patchBanner(i, { cta_url: e.target.value })} placeholder="/en-my/all/music" /></div>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label className="text-xs">Text position</Label>
                                            <div className="inline-flex w-max rounded-lg border border-border p-0.5">
                                                {(['left', 'center', 'right'] as const).map((a) => (
                                                    <button key={a} type="button" onClick={() => patchBanner(i, { align: a })} className={`rounded-md px-3 py-1 text-xs font-medium capitalize ${(b.align ?? 'center') === a ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'}`}>{a}</button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                                {data.hero.banners.length < 8 && (
                                    <Button type="button" variant="outline" size="sm" className="w-max" onClick={() => patchHero({ banners: [...data.hero.banners, emptyBanner()] })}><Plus className="size-3.5" /> Add banner</Button>
                                )}
                            </>
                        )}
                    </Card>

                    {/* Organizer */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Organizer promo</h2>
                            <Toggle on={data.organizer.enabled} onChange={(v) => patch('organizer', { enabled: v })} label={data.organizer.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.organizer.heading} onChange={(e) => patch('organizer', { heading: e.target.value })} /></div>
                        <div className="grid gap-1.5"><Label>Body</Label><textarea rows={2} className={area} value={data.organizer.body} onChange={(e) => patch('organizer', { body: e.target.value })} /></div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5"><Label>Button label</Label><input className={field} value={data.organizer.cta_label} onChange={(e) => patch('organizer', { cta_label: e.target.value })} /></div>
                            <div className="grid gap-1.5"><Label>Button link</Label><input className={field} value={data.organizer.cta_url} onChange={(e) => patch('organizer', { cta_url: e.target.value })} /></div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Image (optional)</Label>
                            <div className="flex items-center gap-3">
                                {data.organizer.image && <img src={data.organizer.image} alt="" className="h-16 w-24 rounded-lg border border-border object-cover" />}
                                <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-input px-3 py-2 text-sm hover:bg-accent">
                                    <Upload className="size-4" />{uploading ? 'Uploading…' : 'Upload'}
                                    <input type="file" accept="image/*" hidden onChange={(e) => uploadOrg(e.target.files?.[0])} />
                                </label>
                                {data.organizer.image && <Button type="button" variant="ghost" size="sm" onClick={() => patch('organizer', { image: '' })}>Remove</Button>}
                            </div>
                        </div>
                    </Card>

                    {/* Event time */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Event-time chips</h2>
                            <Toggle on={data.event_time.enabled} onChange={(v) => patch('event_time', { enabled: v })} label={data.event_time.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.event_time.heading} onChange={(e) => patch('event_time', { heading: e.target.value })} /></div>
                        <div className="grid gap-2">
                            <Label>Chips (label + when filter)</Label>
                            {data.event_time.items.map((it, i) => (
                                <div key={i} className="flex gap-2">
                                    <input className={field} value={it.label} placeholder="Label e.g. This weekend" onChange={(e) => {
 const items = [...data.event_time.items]; items[i] = { ...it, label: e.target.value }; patch('event_time', { items }); 
}} />
                                    <div className="w-[160px] shrink-0">
                                        <AppSelect
                                            value={it.value}
                                            onChange={(v) => {
 const items = [...data.event_time.items]; items[i] = { ...it, value: v }; patch('event_time', { items }); 
}}
                                            options={[{ value: 'today', label: 'Today' }, { value: 'weekend', label: 'Weekend' }, { value: 'week', label: 'This week' }, { value: 'month', label: 'This month' }]}
                                            className="h-10"
                                        />
                                    </div>
                                    <button type="button" onClick={() => patch('event_time', { items: data.event_time.items.filter((_, j) => j !== i) })} className="flex size-10 shrink-0 items-center justify-center rounded-lg text-destructive hover:bg-destructive/10"><Trash2 className="size-4" /></button>
                                </div>
                            ))}
                            <Button type="button" variant="outline" size="sm" className="w-max" onClick={() => patch('event_time', { items: [...data.event_time.items, { label: '', value: 'today' }] })}><Plus className="size-4" /> Add chip</Button>
                        </div>
                    </Card>

                    {/* Nearby cities */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Nearby cities</h2>
                            <Toggle on={data.nearby_cities.enabled} onChange={(v) => patch('nearby_cities', { enabled: v })} label={data.nearby_cities.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.nearby_cities.heading} onChange={(e) => patch('nearby_cities', { heading: e.target.value })} /></div>
                        <div className="grid gap-1.5">
                            <Label>Cities</Label>
                            <MultiSelect
                                values={data.nearby_cities.cities}
                                onChange={(vals) => patch('nearby_cities', { cities: vals })}
                                options={cities.map((c) => ({ value: c.name, label: c.name }))}
                                placeholder="Choose cities to feature…"
                            />
                            <p className="text-xs text-muted-foreground">Each links to its city page (e.g. /en-my/kuala-lumpur).</p>
                        </div>
                    </Card>

                    {/* Featured organizers */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Featured organizers</h2>
                            <Toggle on={data.featured_organizers.enabled} onChange={(v) => patch('featured_organizers', { enabled: v })} label={data.featured_organizers.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.featured_organizers.heading} onChange={(e) => patch('featured_organizers', { heading: e.target.value })} /></div>
                        <div className="grid gap-1.5"><Label>Subheading</Label><input className={field} value={data.featured_organizers.subheading} onChange={(e) => patch('featured_organizers', { subheading: e.target.value })} /></div>
                        <p className="text-xs text-muted-foreground">Automatically shows your most active organizers (by number of published events).</p>
                    </Card>

                    {/* Contact us */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Contact us</h2>
                            <Toggle on={data.contact.enabled} onChange={(v) => patch('contact', { enabled: v })} label={data.contact.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.contact.heading} onChange={(e) => patch('contact', { heading: e.target.value })} /></div>
                        <div className="grid gap-1.5"><Label>Subheading</Label><textarea className={area} rows={2} value={data.contact.subheading} onChange={(e) => patch('contact', { subheading: e.target.value })} /></div>
                        <p className="text-xs text-muted-foreground">A contact form on the home page; submissions appear under Contact messages.</p>
                    </Card>

                    {/* Illustration showcase */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Illustration sections</h2>
                            <Toggle on={data.showcase.enabled} onChange={(v) => patch('showcase', { enabled: v })} label={data.showcase.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <p className="text-xs text-muted-foreground">The two illustrated bands — “For every kind of gathering” and “Everything you need to run the night”. Turn off to hide both (e.g. in favour of the SEO text block below).</p>
                    </Card>

                    {/* SEO text block */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">SEO text block</h2>
                            <Toggle on={data.seo_text.enabled} onChange={(v) => patch('seo_text', { enabled: v })} label={data.seo_text.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.seo_text.heading} onChange={(e) => patch('seo_text', { heading: e.target.value })} placeholder="e.g. Discover & host events across Malaysia" /></div>
                        <div className="grid gap-1.5"><Label>Body</Label><textarea className={area} rows={6} value={data.seo_text.body} onChange={(e) => patch('seo_text', { body: e.target.value })} placeholder="Keyword-rich copy shown near the foot of the home page…" /></div>
                        <p className="text-xs text-muted-foreground">A keyword-friendly paragraph near the foot of the home page, collapsed to a teaser with a “Read more” link.</p>
                    </Card>
                </div>

                <div className="mt-6 flex justify-end"><Button onClick={save} disabled={processing}>Save changes</Button></div>
            </div>
        </>
    );
}

LandingSettings.layout = { breadcrumbs: [{ title: 'Landing', href: '/admin/site/landing' }] };
