import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { RichEditor } from '@/components/rich-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

interface Seo { enabled: boolean; heading: string; body: string }

export default function SeoTextEditor({ seo }: { seo: Seo }) {
    const form = useForm<Seo>({ enabled: seo.enabled, heading: seo.heading ?? '', body: seo.body ?? '' });

    const save = () => form.post('/admin/site/seo-text', { preserveScroll: true });

    return (
        <>
            <Head title="SEO text block" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <Link href="/admin/site/landing" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Landing</Link>
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">SEO text block</h1>
                        <p className="text-sm text-muted-foreground">A keyword-friendly section near the foot of the home page, collapsed to a teaser with “Read more”.</p>
                    </div>
                    <div className="flex items-center gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={form.data.enabled} onCheckedChange={(v) => form.setData('enabled', v)} />
                            {form.data.enabled ? 'Shown' : 'Hidden'}
                        </label>
                        <Button onClick={save} disabled={form.processing}>{form.processing ? 'Saving…' : 'Save changes'}</Button>
                    </div>
                </div>

                <div className="grid gap-5 rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="grid gap-1.5">
                        <Label htmlFor="heading">Heading</Label>
                        <Input id="heading" value={form.data.heading} onChange={(e) => form.setData('heading', e.target.value)} placeholder="e.g. Discover & host events across Malaysia" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Body</Label>
                        <RichEditor value={form.data.body} onChange={(html) => form.setData('body', html)} placeholder="Write keyword-rich copy for search engines…" />
                    </div>
                </div>
            </div>
        </>
    );
}

SeoTextEditor.layout = { breadcrumbs: [{ title: 'SEO text block', href: '/admin/site/seo-text' }] };
