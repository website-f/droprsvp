import { Head, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Globe, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { RegionPicker } from '@/components/admin/region-versions';
import type { RegionOption } from '@/components/admin/region-versions';
import { RichEditor } from '@/components/rich-editor';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

interface LegalVersion { id?: number | null; regions: string[]; title: string; body: string }
interface LegalPage { slug: string; title: string; body: string; url: string; versions: LegalVersion[] }

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function LegalPages({ pages, regionOptions = [] }: { pages: LegalPage[]; regionOptions?: RegionOption[] }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm<{ pages: LegalPage[] }>({ pages: pages.map((p) => ({ ...p, versions: p.versions ?? [] })) });
    const { data, setData, processing, errors } = form;
    // Which tab each page has open: -1 = the main version, else a version index.
    const [tab, setTab] = useState<Record<string, number>>({});
    const [adding, setAdding] = useState<number | null>(null);
    const [picked, setPicked] = useState<string[]>([]);

    const flags = (codes: string[]) => codes.map((c) => regionOptions.find((r) => r.code === c)?.flag ?? '').join(' ');
    const names = (codes: string[]) => codes.map((c) => regionOptions.find((r) => r.code === c)?.name ?? c).join(', ');

    const setPage = (i: number, next: Partial<LegalPage>) =>
        setData('pages', data.pages.map((p, idx) => (idx === i ? { ...p, ...next } : p)));

    const patch = (i: number, key: 'title' | 'body', value: string) => {
        const v = tab[data.pages[i].slug] ?? -1;

        if (v < 0) {
            setPage(i, { [key]: value });
        } else {
            setPage(i, { versions: data.pages[i].versions.map((ver, vi) => (vi === v ? { ...ver, [key]: value } : ver)) });
        }
    };

    const addVersion = () => {
        if (adding === null || picked.length === 0) {
            return;
        }

        const p = data.pages[adding];
        // Starts as a copy of the main text, like page and help versions.
        setPage(adding, { versions: [...p.versions, { id: null, regions: picked, title: p.title, body: p.body }] });
        setTab({ ...tab, [p.slug]: p.versions.length });
        setAdding(null);
    };

    const removeVersion = (i: number, v: number) => {
        const p = data.pages[i];

        if (!window.confirm(`Delete the ${names(p.versions[v].regions)} version? Those regions will see the main ${p.title} again once you save.`)) {
            return;
        }

        setPage(i, { versions: p.versions.filter((_, vi) => vi !== v) });
        setTab({ ...tab, [p.slug]: -1 });
    };

    // Reload from the server so new versions carry their ids (else a second
    // save would create them again).
    const save = () => form.post('/admin/site/legal', {
        preserveScroll: true,
        onSuccess: (page) => setData('pages', (page.props as unknown as { pages: LegalPage[] }).pages),
    });
    const errorList = Object.values(errors as Record<string, string>);
    const multiRegion = regionOptions.length > 1;

    return (
        <>
            <Head title="Legal pages" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Legal pages</h1>
                        <p className="text-sm text-muted-foreground">
                            Edit your Privacy Policy and Terms &amp; Conditions. They’re published automatically.
                            {multiRegion && ' Add a region version where a country needs different terms.'}
                        </p>
                    </div>
                    <Button onClick={save} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}
                {errorList.length > 0 && (
                    <div className="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-2 text-sm text-destructive">
                        {[...new Set(errorList)].map((e) => <p key={e}>{e}</p>)}
                    </div>
                )}

                <div className="grid gap-8">
                    {data.pages.map((p, i) => {
                        const v = Math.min(tab[p.slug] ?? -1, p.versions.length - 1);
                        const current = v < 0 ? p : p.versions[v];
                        const taken = p.versions.flatMap((ver) => ver.regions);
                        const liveUrl = v < 0 ? p.url : `/${p.versions[v].regions[0]}/${p.slug}/`;

                        return (
                            <section key={p.slug} className="rounded-xl border border-border bg-card p-5 shadow-sm">
                                <div className="mb-4 flex items-center justify-between gap-3">
                                    <h2 className="font-semibold">{p.title}</h2>
                                    <a href={liveUrl} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground">
                                        <ExternalLink className="size-3.5" /> View live
                                    </a>
                                </div>

                                {multiRegion && (
                                    <div className="mb-4 flex flex-wrap items-center gap-1 rounded-lg border border-border p-1.5">
                                        <span className="flex items-center gap-1.5 px-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><Globe className="size-3.5" /> Region versions</span>
                                        <button type="button" onClick={() => setTab({ ...tab, [p.slug]: -1 })}
                                            className={cn('rounded-md px-3 py-1.5 text-sm', v < 0 ? 'bg-foreground text-background' : 'hover:bg-accent')}>
                                            {p.versions.length ? 'Everywhere else' : 'All regions'}
                                        </button>
                                        {p.versions.map((ver, vi) => (
                                            <button key={vi} type="button" onClick={() => setTab({ ...tab, [p.slug]: vi })}
                                                className={cn('inline-flex max-w-full items-center gap-1.5 rounded-md px-3 py-1.5 text-sm', v === vi ? 'bg-foreground text-background' : 'hover:bg-accent')}>
                                                <span aria-hidden>{flags(ver.regions)}</span>
                                                <span className="truncate">{names(ver.regions) || 'Pick regions'}</span>
                                                {!ver.id && <span className={cn('rounded px-1 text-[10px] uppercase', v === vi ? 'bg-background/20' : 'bg-muted text-muted-foreground')}>unsaved</span>}
                                            </button>
                                        ))}
                                        <button type="button" onClick={() => {
                                            setPicked([]);
                                            setAdding(i);
                                        }}
                                            className="inline-flex items-center gap-1 rounded-md border border-dashed border-border px-3 py-1.5 text-sm text-muted-foreground hover:border-foreground/40 hover:text-foreground">
                                            <Plus className="size-3.5" /> Add a region version
                                        </button>
                                    </div>
                                )}

                                <div className="grid gap-4">
                                    {v >= 0 && (
                                        <div className="grid gap-2 rounded-lg border border-border p-3">
                                            <div className="flex items-center justify-between gap-2">
                                                <Label>This version is for</Label>
                                                <Button type="button" variant="ghost" size="sm" className="text-destructive hover:bg-destructive/10 hover:text-destructive" onClick={() => removeVersion(i, v)}>
                                                    <Trash2 className="size-3.5" /> Delete version
                                                </Button>
                                            </div>
                                            <RegionPicker
                                                options={regionOptions}
                                                value={p.versions[v].regions}
                                                onChange={(codes) => setPage(i, { versions: p.versions.map((ver, vi) => (vi === v ? { ...ver, regions: codes } : ver)) })}
                                                taken={p.versions.filter((_, vi) => vi !== v).flatMap((ver) => ver.regions)}
                                            />
                                        </div>
                                    )}
                                    <div className="grid gap-1.5">
                                        <Label>Page title</Label>
                                        <input className={field} value={current.title} onChange={(e) => patch(i, 'title', e.target.value)} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Content</Label>
                                        {/* Keyed so switching tabs loads that version's text into the editor. */}
                                        <RichEditor key={`${p.slug}-${v}`} value={current.body} onChange={(html) => patch(i, 'body', html)} />
                                    </div>
                                </div>

                                <Dialog open={adding === i} onOpenChange={(o) => !o && setAdding(null)}>
                                    <DialogContent className="sm:max-w-lg">
                                        <DialogHeader>
                                            <DialogTitle>Add a region version of {p.title}</DialogTitle>
                                            <DialogDescription>
                                                Visitors in the regions you pick see this version instead — same address, its own text. It starts as a copy; save changes to publish it.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <RegionPicker options={regionOptions} value={picked} onChange={setPicked} taken={taken} />
                                        <DialogFooter className="gap-2">
                                            <Button variant="outline" onClick={() => setAdding(null)}>Cancel</Button>
                                            <Button disabled={picked.length === 0} onClick={addVersion}>Add version</Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </section>
                        );
                    })}
                </div>

                <div className="mt-6 flex justify-end"><Button onClick={save} disabled={processing}>Save changes</Button></div>
            </div>
        </>
    );
}

LegalPages.layout = { breadcrumbs: [{ title: 'Legal pages', href: '/admin/site/legal' }] };
