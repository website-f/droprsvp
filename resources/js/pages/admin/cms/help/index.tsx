import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useConfirm } from '@/components/confirm-dialog';
import { LifeBuoy, Pencil, Plus, Trash2 } from 'lucide-react';

interface Row { id: number; title: string; category: string; status: string; updated_at: string }

export default function HelpIndex({ articles }: { articles: Row[] }) {
    const confirm = useConfirm();
    const remove = async (a: Row) => {
        if (await confirm({ title: `Delete “${a.title}”?`, confirmText: 'Delete', destructive: true })) {
            router.delete(`/admin/cms/help/${a.id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Help center" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Help center</h1>
                        <p className="text-sm text-muted-foreground">Write and organize support articles shown at /help.</p>
                    </div>
                    <Button asChild><Link href="/admin/cms/help/create"><Plus className="size-4" /> New article</Link></Button>
                </div>

                {articles.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-border p-12 text-center">
                        <LifeBuoy className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">No help articles yet</p>
                        <Button asChild className="mt-5"><Link href="/admin/cms/help/create">Create your first article</Link></Button>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        <ul className="divide-y divide-border">
                            {articles.map((a) => (
                                <li key={a.id} className="flex items-center justify-between gap-3 p-4">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">{a.title}</p>
                                        <p className="text-xs text-muted-foreground">{a.category} · updated {a.updated_at}</p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Badge variant={a.status === 'published' ? 'default' : 'secondary'}>{a.status}</Badge>
                                        <Button asChild variant="outline" size="sm"><Link href={`/admin/cms/help/${a.id}/edit`}><Pencil className="size-4" /></Link></Button>
                                        <Button variant="ghost" size="sm" onClick={() => remove(a)}><Trash2 className="size-4 text-destructive" /></Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </>
    );
}

HelpIndex.layout = { breadcrumbs: [{ title: 'Help center', href: '/admin/cms/help' }] };
