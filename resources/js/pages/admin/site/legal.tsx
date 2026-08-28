import { Head, useForm, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { RichEditor } from '@/components/rich-editor';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface LegalPage { slug: string; title: string; body: string; url: string }

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function LegalPages({ pages }: { pages: LegalPage[] }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm<{ pages: LegalPage[] }>({ pages });
    const { data, setData, processing } = form;

    const patch = (i: number, key: 'title' | 'body', value: string) =>
        setData('pages', data.pages.map((p, idx) => (idx === i ? { ...p, [key]: value } : p)));

    const save = () => form.post('/admin/site/legal', { preserveScroll: true });

    return (
        <>
            <Head title="Legal pages" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Legal pages</h1>
                        <p className="text-sm text-muted-foreground">Edit your Privacy Policy and Terms &amp; Conditions. They’re published automatically.</p>
                    </div>
                    <Button onClick={save} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <div className="grid gap-8">
                    {data.pages.map((p, i) => (
                        <section key={p.slug} className="rounded-xl border border-border bg-card p-5 shadow-sm">
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <h2 className="font-semibold">{p.title}</h2>
                                <a href={p.url} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground">
                                    <ExternalLink className="size-3.5" /> View live
                                </a>
                            </div>
                            <div className="grid gap-4">
                                <div className="grid gap-1.5">
                                    <Label>Page title</Label>
                                    <input className={field} value={p.title} onChange={(e) => patch(i, 'title', e.target.value)} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Content</Label>
                                    <RichEditor value={p.body} onChange={(html) => patch(i, 'body', html)} />
                                </div>
                            </div>
                        </section>
                    ))}
                </div>

                <div className="mt-6 flex justify-end"><Button onClick={save} disabled={processing}>Save changes</Button></div>
            </div>
        </>
    );
}

LegalPages.layout = { breadcrumbs: [{ title: 'Legal pages', href: '/admin/site/legal' }] };
