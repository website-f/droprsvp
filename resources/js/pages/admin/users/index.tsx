import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Search, ShieldCheck } from 'lucide-react';

interface Row { id: number; name: string; email: string; roles: string[]; events: number; is_superadmin: boolean }
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null }

export default function AdminUsers({ users, filters }: { users: Paginated; filters: { q: string } }) {
    const [q, setQ] = useState(filters.q);
    const flash = usePage().props.flash as { success?: string; error?: string } | undefined;

    const toggle = (u: Row) => {
        const verb = u.is_superadmin ? 'Revoke superadmin from' : 'Grant superadmin to';
        if (confirm(`${verb} ${u.name}?`)) router.post(`/admin/users/${u.id}/superadmin`, {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Users" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <h1 className="mb-6 text-2xl font-bold tracking-tight">Users</h1>
                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}
                {flash?.error && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{flash.error}</div>}

                <form onSubmit={(e) => { e.preventDefault(); router.get('/admin/users', q ? { q } : {}, { preserveState: true }); }} className="mb-5 flex max-w-md gap-2">
                    <label className="flex h-10 flex-1 items-center gap-2 rounded-lg border border-input bg-card px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input className="w-full bg-transparent text-sm outline-none" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search name or email" />
                    </label>
                    <Button type="submit">Search</Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr><th className="px-4 py-3 font-medium">User</th><th className="px-4 py-3 font-medium">Roles</th><th className="px-4 py-3 font-medium">Events</th><th className="px-4 py-3 text-right font-medium">Access</th></tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {users.data.map((u) => (
                                <tr key={u.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{u.name}</div>
                                        <div className="text-xs text-muted-foreground">{u.email}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-1">
                                            {u.roles.length ? u.roles.map((r) => <Badge key={r} variant="secondary" className="capitalize">{r}</Badge>) : <span className="text-xs text-muted-foreground">—</span>}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">{u.events}</td>
                                    <td className="px-4 py-3 text-right">
                                        <Button variant={u.is_superadmin ? 'outline' : 'default'} size="sm" onClick={() => toggle(u)}>
                                            <ShieldCheck className="size-3.5" /> {u.is_superadmin ? 'Revoke admin' : 'Make admin'}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {(users.prev_page_url || users.next_page_url) && (
                    <div className="mt-6 flex justify-between">
                        <Button asChild variant="outline" disabled={!users.prev_page_url}>{users.prev_page_url ? <Link href={users.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                        <Button asChild variant="outline" disabled={!users.next_page_url}>{users.next_page_url ? <Link href={users.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

AdminUsers.layout = {
    breadcrumbs: [{ title: 'Users', href: '/admin/users' }],
};
