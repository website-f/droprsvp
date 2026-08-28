import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Plus, Trash2 } from 'lucide-react';

interface FooterLink { label: string; url: string }
interface FooterColumn { title: string; links: FooterLink[] }
interface FooterCfg { tagline: string; copyright: string; columns: FooterColumn[] }

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function FooterSettings({ footer }: { footer: FooterCfg }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm<FooterCfg>({ tagline: footer.tagline ?? '', copyright: footer.copyright ?? '', columns: footer.columns ?? [] });
    const { data, setData, processing } = form;

    const setCol = (ci: number, patch: Partial<FooterColumn>) => setData('columns', data.columns.map((c, i) => i === ci ? { ...c, ...patch } : c));
    const setLink = (ci: number, li: number, patch: Partial<FooterLink>) => setCol(ci, { links: data.columns[ci].links.map((l, i) => i === li ? { ...l, ...patch } : l) });
    const save = () => form.post('/admin/site/footer', { preserveScroll: true });

    return (
        <>
            <Head title="Footer" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Footer</h1>
                        <p className="text-sm text-muted-foreground">Edit the footer tagline, link columns and copyright — shown across the whole site.</p>
                    </div>
                    <Button onClick={save} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <div className="grid gap-6">
                    <section className="grid gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
                        <div className="grid gap-1.5"><Label>Tagline</Label><textarea rows={2} className={area} value={data.tagline} onChange={(e) => setData('tagline', e.target.value)} /></div>
                        <div className="grid gap-1.5"><Label>Copyright</Label><input className={field} value={data.copyright} onChange={(e) => setData('copyright', e.target.value)} /></div>
                    </section>

                    <div className="grid gap-4 lg:grid-cols-2">
                        {data.columns.map((col, ci) => (
                            <section key={ci} className="grid gap-3 rounded-xl border border-border bg-card p-4 shadow-sm">
                                <div className="flex items-center gap-2">
                                    <input className={field} value={col.title} placeholder="Column title" onChange={(e) => setCol(ci, { title: e.target.value })} />
                                    <button type="button" aria-label="Remove column" onClick={() => setData('columns', data.columns.filter((_, i) => i !== ci))} className="flex size-10 shrink-0 items-center justify-center rounded-lg text-destructive hover:bg-destructive/10"><Trash2 className="size-4" /></button>
                                </div>
                                <div className="grid gap-2">
                                    {col.links.map((l, li) => (
                                        <div key={li} className="flex gap-2">
                                            <input className={field} value={l.label} placeholder="Label" onChange={(e) => setLink(ci, li, { label: e.target.value })} />
                                            <input className={field} value={l.url} placeholder="/url" onChange={(e) => setLink(ci, li, { url: e.target.value })} />
                                            <button type="button" aria-label="Remove link" onClick={() => setCol(ci, { links: col.links.filter((_, i) => i !== li) })} className="flex size-10 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent"><Trash2 className="size-4" /></button>
                                        </div>
                                    ))}
                                    <Button type="button" variant="outline" size="sm" className="w-max" onClick={() => setCol(ci, { links: [...col.links, { label: '', url: '' }] })}><Plus className="size-4" /> Add link</Button>
                                </div>
                            </section>
                        ))}
                    </div>

                    {data.columns.length < 4 && (
                        <Button type="button" variant="outline" className="w-max" onClick={() => setData('columns', [...data.columns, { title: 'New column', links: [] }])}><Plus className="size-4" /> Add column</Button>
                    )}
                </div>

                <div className="mt-6 flex justify-end"><Button onClick={save} disabled={processing}>Save changes</Button></div>
            </div>
        </>
    );
}

FooterSettings.layout = { breadcrumbs: [{ title: 'Footer', href: '/admin/site/footer' }] };
