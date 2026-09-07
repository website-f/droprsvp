import { Head, useForm } from '@inertiajs/react';
import { ExternalLink, RotateCcw, Save } from 'lucide-react';
import { Button } from '@/components/ui/button';

export default function RobotsEditor({ content, default: fallback, url }: { content: string; default: string; url: string }) {
    const form = useForm<{ content: string }>({ content });
    const { data, setData, processing } = form;

    const save = () => form.post('/admin/site/robots', { preserveScroll: true });

    return (
        <>
            <Head title="robots.txt" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">robots.txt</h1>
                        <p className="text-sm text-muted-foreground">
                            Tell search-engine crawlers what they may access. Served live at{' '}
                            <a href={url} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-primary hover:underline">{url} <ExternalLink className="size-3" /></a>.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button type="button" variant="outline" onClick={() => setData('content', fallback)}><RotateCcw className="size-4" /> Reset to default</Button>
                        <Button onClick={save} disabled={processing}><Save className="size-4" /> {processing ? 'Saving…' : 'Save'}</Button>
                    </div>
                </div>

                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <textarea
                        value={data.content}
                        onChange={(e) => setData('content', e.target.value)}
                        spellCheck={false}
                        rows={16}
                        className="w-full resize-y rounded-lg border border-input bg-background px-3 py-2.5 font-mono text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        placeholder="User-agent: *&#10;Allow: /"
                    />
                    <p className="mt-2 text-xs text-muted-foreground">
                        The admin panel is always excluded from search indexes regardless of this file. Include a <code>Sitemap:</code> line so crawlers can find your sitemap.
                    </p>
                </div>
            </div>
        </>
    );
}

RobotsEditor.layout = { breadcrumbs: [{ title: 'robots.txt', href: '/admin/site/robots' }] };
