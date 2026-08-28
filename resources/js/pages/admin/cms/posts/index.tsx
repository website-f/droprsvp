import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useConfirm } from '@/components/confirm-dialog';
import { ExternalLink, Newspaper, Pencil, Plus, Trash2 } from 'lucide-react';

interface PostRow { id: number; title: string; slug: string; status: string; category: string | null; updated_at: string }

export default function PostsIndex({ posts }: { posts: PostRow[] }) {
    const confirm = useConfirm();
    const remove = async (p: PostRow) => {
        if (await confirm({ title: `Delete “${p.title}”?`, description: 'This post will be removed.', confirmText: 'Delete', destructive: true })) {
            router.delete(`/admin/cms/posts/${p.id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Posts" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Blog posts</h1>
                        <p className="text-sm text-muted-foreground">Write and publish articles.</p>
                    </div>
                    <Button asChild><Link href="/admin/cms/posts/create"><Plus className="size-4" /> New post</Link></Button>
                </div>

                {posts.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <Newspaper className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">No posts yet.</p>
                        <Button asChild><Link href="/admin/cms/posts/create"><Plus className="size-4" /> Write your first post</Link></Button>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr><th className="px-4 py-3 font-medium">Title</th><th className="px-4 py-3 font-medium">Category</th><th className="px-4 py-3 font-medium">Status</th><th className="px-4 py-3 text-right font-medium">Actions</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {posts.map((p) => (
                                    <tr key={p.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{p.title}</div>
                                            <div className="text-xs text-muted-foreground">/blog/{p.slug} · {p.updated_at}</div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{p.category ?? '—'}</td>
                                        <td className="px-4 py-3"><Badge variant={p.status === 'published' ? 'default' : 'secondary'} className="capitalize">{p.status}</Badge></td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                {p.status === 'published' && <Button asChild variant="ghost" size="sm"><a href={`/blog/${p.slug}`} target="_blank" rel="noreferrer"><ExternalLink className="size-3.5" /></a></Button>}
                                                <Button asChild variant="outline" size="sm"><Link href={`/admin/cms/posts/${p.id}/edit`}><Pencil className="size-3.5" /> Edit</Link></Button>
                                                <Button variant="ghost" size="sm" onClick={() => remove(p)}><Trash2 className="size-3.5" /></Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

PostsIndex.layout = {
    breadcrumbs: [{ title: 'Posts', href: '/admin/cms/posts' }],
};
