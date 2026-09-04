import { Head, Link, useForm } from '@inertiajs/react';
import { ExternalLink, Save, Upload } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { RichEditor } from '@/components/rich-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { uploadImage } from '@/lib/upload';

interface Hero { enabled: boolean; heading: string; subheading: string; image: string; cta_label: string; cta_url: string; align: 'left' | 'center' | 'right' }
interface SeoText { enabled: boolean; heading: string; body: string }
interface Data { hero: Hero; seo_text: SeoText }

export default function EventsPageSettings({ data: initial }: { data: Data }) {
    const form = useForm<Data>({ hero: initial.hero, seo_text: initial.seo_text });
    const { data, setData, processing } = form;
    const [uploading, setUploading] = useState(false);

    const patchHero = (val: Partial<Hero>) => setData('hero', { ...data.hero, ...val });
    const patchSeo = (val: Partial<SeoText>) => setData('seo_text', { ...data.seo_text, ...val });

    const uploadHero = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);

        try {
            patchHero({ image: await uploadImage(file) });
        } finally {
            setUploading(false);
        }
    };

    const save = () => form.post('/admin/site/events-page', { preserveScroll: true, onSuccess: () => toast.success('Events page saved') });

    return (
        <>
            <Head title="Events page" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Events page</h1>
                        <p className="text-sm text-muted-foreground">
                            The hero banner &amp; SEO block on the public{' '}
                            <Link href="/en-my/all" className="inline-flex items-center gap-1 text-primary hover:underline">browse events page <ExternalLink className="size-3" /></Link>.
                        </p>
                    </div>
                    <Button onClick={save} disabled={processing}><Save className="size-4" /> {processing ? 'Saving…' : 'Save'}</Button>
                </div>

                {/* Hero banner */}
                <section className="mb-4 rounded-2xl border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">Hero banner</h2>
                            <p className="text-xs text-muted-foreground">Shown at the top of the events page. Organizers who upload an event banner are featured alongside it.</p>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={data.hero.enabled} onCheckedChange={(v) => patchHero({ enabled: v })} />
                            {data.hero.enabled ? 'Shown' : 'Hidden'}
                        </label>
                    </div>

                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label>Background image</Label>
                            <div className="flex flex-wrap items-center gap-3">
                                {data.hero.image && <img src={data.hero.image} alt="" className="h-20 w-40 rounded-lg border border-border object-cover" />}
                                <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm hover:bg-accent">
                                    <Upload className="size-4" />{uploading ? 'Uploading…' : (data.hero.image ? 'Replace image' : 'Upload image')}
                                    <input type="file" accept="image/*" hidden onChange={(e) => uploadHero(e.target.files?.[0])} />
                                </label>
                                {data.hero.image && <Button type="button" variant="ghost" size="sm" onClick={() => patchHero({ image: '' })}>Remove</Button>}
                            </div>
                            <p className="text-xs text-muted-foreground">Recommended: 2400×800px (wide 3:1).</p>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="hero-heading">Heading</Label>
                            <Input id="hero-heading" value={data.hero.heading} onChange={(e) => patchHero({ heading: e.target.value })} placeholder="e.g. Best events in Kuala Lumpur" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="hero-sub">Subheading</Label>
                            <Input id="hero-sub" value={data.hero.subheading} onChange={(e) => patchHero({ subheading: e.target.value })} placeholder="A short line under the heading" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="hero-cta">Button label <span className="font-normal text-muted-foreground">(optional)</span></Label>
                                <Input id="hero-cta" value={data.hero.cta_label} onChange={(e) => patchHero({ cta_label: e.target.value })} placeholder="e.g. Browse all" />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="hero-url">Button link</Label>
                                <Input id="hero-url" value={data.hero.cta_url} onChange={(e) => patchHero({ cta_url: e.target.value })} placeholder="/en-my/all" />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Text alignment</Label>
                            <div className="flex gap-2">
                                {(['left', 'center', 'right'] as const).map((a) => (
                                    <Button key={a} type="button" size="sm" variant={data.hero.align === a ? 'default' : 'outline'} className="capitalize" onClick={() => patchHero({ align: a })}>{a}</Button>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>

                {/* SEO text block */}
                <section className="rounded-2xl border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">SEO text block</h2>
                            <p className="text-xs text-muted-foreground">A keyword-friendly section below the event grid, collapsed to a teaser with “Read more”.</p>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={data.seo_text.enabled} onCheckedChange={(v) => patchSeo({ enabled: v })} />
                            {data.seo_text.enabled ? 'Shown' : 'Hidden'}
                        </label>
                    </div>
                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="seo-heading">Heading</Label>
                            <Input id="seo-heading" value={data.seo_text.heading} onChange={(e) => patchSeo({ heading: e.target.value })} placeholder="e.g. Events & things to do in Malaysia" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Body</Label>
                            <RichEditor value={data.seo_text.body} onChange={(html) => patchSeo({ body: html })} placeholder="Write keyword-rich copy for search engines…" />
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}

EventsPageSettings.layout = { breadcrumbs: [{ title: 'Events page', href: '/admin/site/events-page' }] };
