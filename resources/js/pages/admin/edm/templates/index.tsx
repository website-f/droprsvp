import { Head, Link, router, useForm } from '@inertiajs/react';
import { Copy, Eye, LayoutTemplate, MoreHorizontal, Pencil, Plus, Send, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { EDM_CRUMB, EdmHeader, field } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

interface Saved { id: number; name: string; description: string | null; subject: string | null; blocks: number; updated_at: string | null; creator: string | null }
interface Starter { key: string; name: string; description: string; subject: string; blocks: number }
interface Props { templates: Saved[]; starters: Starter[]; base?: string }

/**
 * A live thumbnail: the real rendered email, scaled down. Rendered by the same
 * code that sends, so what the card shows is what the inbox gets.
 */
function Thumb({ src, title }: { src: string; title: string }) {
    return (
        <div className="relative h-56 overflow-hidden rounded-t-2xl border-b border-border bg-muted/40">
            <iframe
                src={src}
                title={title}
                sandbox=""
                loading="lazy"
                tabIndex={-1}
                className="pointer-events-none absolute left-0 top-0 h-[1120px] w-[200%] origin-top-left scale-50 border-0 bg-white"
            />
        </div>
    );
}

export default function EdmTemplates({ base = '/admin/edm', templates, starters }: Props) {
    const confirm = useConfirm();
    const [preview, setPreview] = useState<{ src: string; title: string } | null>(null);
    const [creating, setCreating] = useState<{ starter?: string } | null>(null);
    const form = useForm({ name: '', description: '', starter: '' });

    const openCreate = (starter?: Starter) => {
        form.setData({ name: starter ? `${starter.name} (custom)` : '', description: starter?.description ?? '', starter: starter?.key ?? '' });
        setCreating({ starter: starter?.key });
    };

    const use = (payload: Record<string, string | number>) => router.post(`${base}/templates/use`, payload);

    const remove = async (t: Saved) => {
        if (await confirm({ title: `Delete “${t.name}”?`, description: 'Campaigns already made from it keep their own copy.', confirmText: 'Delete', destructive: true })) {
            router.delete(`${base}/templates/${t.id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Templates" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader
                    title="Templates"
                    description="Reusable designs. Start a campaign from one, and the design is copied in — editing the campaign never changes the template."
                    actions={<Button onClick={() => openCreate()}><Plus className="size-4" /> New template</Button>}
                />

                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><LayoutTemplate className="size-4" /> Your templates <span className="text-muted-foreground">({templates.length})</span></h2>
                {templates.length === 0 ? (
                    <div className="mb-8 flex flex-col items-center gap-2 rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
                        <LayoutTemplate className="size-6" />
                        <div>No saved templates yet. Customise a starter below, build one from scratch, or use “Save as template” on any campaign.</div>
                    </div>
                ) : (
                    <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {templates.map((t) => (
                            <article key={t.id} className="flex flex-col rounded-2xl border border-border bg-card shadow-sm">
                                <button type="button" className="text-left" onClick={() => setPreview({ src: `${base}/templates/${t.id}/preview`, title: t.name })} aria-label={`Preview ${t.name}`}>
                                    <Thumb src={`${base}/templates/${t.id}/preview`} title={t.name} />
                                </button>
                                <div className="flex flex-1 flex-col gap-1 p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <h3 className="min-w-0 truncate font-semibold">{t.name}</h3>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="size-8 shrink-0" aria-label="More"><MoreHorizontal className="size-4" /></Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem onClick={() => setPreview({ src: `${base}/templates/${t.id}/preview`, title: t.name })}><Eye className="size-4" /> Preview</DropdownMenuItem>
                                                <DropdownMenuItem onClick={() => router.visit(`${base}/templates/${t.id}/editor`)}><Pencil className="size-4" /> Edit design</DropdownMenuItem>
                                                <DropdownMenuItem onClick={() => router.post(`${base}/templates/${t.id}/duplicate`, {}, { preserveScroll: true })}><Copy className="size-4" /> Duplicate</DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem className="text-destructive" onClick={() => remove(t)}><Trash2 className="size-4" /> Delete</DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                    {t.description && <p className="line-clamp-2 text-xs text-muted-foreground">{t.description}</p>}
                                    <p className="text-[11px] text-muted-foreground">{t.blocks} block{t.blocks === 1 ? '' : 's'} · edited {t.updated_at}{t.creator ? ` by ${t.creator}` : ''}</p>
                                    <div className="mt-auto flex gap-2 pt-3">
                                        <Button size="sm" className="flex-1" onClick={() => use({ template_id: t.id })}><Send className="size-4" /> Use</Button>
                                        <Button size="sm" variant="outline" className="flex-1" asChild><Link href={`${base}/templates/${t.id}/editor`}><Pencil className="size-4" /> Edit</Link></Button>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                )}

                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Sparkles className="size-4" /> Starters</h2>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {starters.map((s) => (
                        <article key={s.key} className="flex flex-col rounded-2xl border border-border bg-card shadow-sm">
                            <button type="button" className="text-left" onClick={() => setPreview({ src: `${base}/templates/starters/${s.key}/preview`, title: s.name })} aria-label={`Preview ${s.name}`}>
                                <Thumb src={`${base}/templates/starters/${s.key}/preview`} title={s.name} />
                            </button>
                            <div className="flex flex-1 flex-col gap-1 p-4">
                                <h3 className="font-semibold">{s.name}</h3>
                                <p className="text-xs text-muted-foreground">{s.description}</p>
                                <div className="mt-auto flex gap-2 pt-3">
                                    <Button size="sm" className="flex-1" onClick={() => use({ starter: s.key })}><Send className="size-4" /> Use</Button>
                                    <Button size="sm" variant="outline" className="flex-1" onClick={() => openCreate(s)}><Copy className="size-4" /> Customise</Button>
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            </div>

            {/* Full-size preview */}
            <Dialog open={preview !== null} onOpenChange={(o) => !o && setPreview(null)}>
                <DialogContent className="flex h-[90vh] max-w-3xl flex-col gap-3 p-4 sm:p-6">
                    <DialogHeader>
                        <DialogTitle>{preview?.title}</DialogTitle>
                        <DialogDescription>As an inbox shows it, with your name filled in.</DialogDescription>
                    </DialogHeader>
                    {preview && <iframe src={preview.src} title={preview.title} sandbox="" className="min-h-0 w-full flex-1 rounded-xl border border-border bg-white" />}
                </DialogContent>
            </Dialog>

            {/* New / customise */}
            <Dialog open={creating !== null} onOpenChange={(o) => !o && setCreating(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{creating?.starter ? 'Customise starter' : 'New template'}</DialogTitle>
                        <DialogDescription>{creating?.starter ? 'Copies the starter into your library to edit.' : 'Starts with a heading, a paragraph and a button.'}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`${base}/templates`);
                    }} className="grid gap-3">
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Name</span>
                            <input autoFocus className={field} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Monthly newsletter" />
                            {form.errors.name && <span className="text-xs text-destructive">{form.errors.name}</span>}
                        </label>
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Description <span className="font-normal text-muted-foreground">(optional)</span></span>
                            <input className={field} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="When to use it" />
                        </label>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="ghost" onClick={() => setCreating(null)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing || !form.data.name.trim()}>Create &amp; edit design</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

EdmTemplates.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Templates', href: '/admin/edm/templates' }] };
