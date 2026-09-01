import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Eye, Search, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Row {
    id: number; name: string; email: string; phone: string | null; gender: string | null; age_band: string | null;
    city: string | null; country: string | null; roles: string[]; events: number; profile_complete: boolean; is_superadmin: boolean;
}
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null }
interface Filters { q: string; role: string; country: string; age: string }
interface Props { users: Paginated; filters: Filters; countries: string[]; ageBands: string[] }

const ROLE_TABS = [{ v: 'all', l: 'All' }, { v: 'normal', l: 'Normal users' }, { v: 'organizer', l: 'Organizers' }];
const AGE_LABEL: Record<string, string> = { 'under-18': 'Under 18', '18-24': '18–24', '25-34': '25–34', '35-44': '35–44', '45-54': '45–54', '55+': '55+' };

export default function AdminUsers({ users, filters, countries, ageBands }: Props) {
    const [q, setQ] = useState(filters.q);
    const flash = usePage().props.flash as { success?: string; error?: string } | undefined;
    const confirm = useConfirm();

    const go = (patch: Partial<Filters>) => {
        const next = { ...filters, q, ...patch };
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v && v !== 'all'));
        router.get('/admin/users', params, { preserveState: true, preserveScroll: true });
    };
    const exportUrl = '/admin/users/export?' + new URLSearchParams(Object.entries({ ...filters, q }).filter(([, v]) => v && v !== 'all') as [string, string][]).toString();

    const toggle = async (u: Row) => {
        const verb = u.is_superadmin ? 'Revoke superadmin from' : 'Grant superadmin to';

        if (await confirm({ title: `${verb} ${u.name}?`, confirmText: u.is_superadmin ? 'Revoke' : 'Grant', destructive: u.is_superadmin })) {
            router.post(`/admin/users/${u.id}/superadmin`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Users" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-bold tracking-tight">Users</h1>
                    <Button asChild variant="outline"><a href={exportUrl}><Download className="size-4" /> Export CSV</a></Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}
                {flash?.error && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{flash.error}</div>}

                {/* Role tabs */}
                <div className="mb-4 flex flex-wrap gap-2">
                    {ROLE_TABS.map((t) => (
                        <button key={t.v} onClick={() => go({ role: t.v })} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.role === t.v ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{t.l}</button>
                    ))}
                </div>

                {/* Search + demographic filters */}
                <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <form onSubmit={(e) => {
 e.preventDefault(); go({}); 
}} className="flex flex-1 gap-2">
                        <label className="flex h-10 flex-1 items-center gap-2 rounded-lg border border-input bg-card px-3">
                            <Search className="size-4 shrink-0 text-muted-foreground" />
                            <input className="w-full bg-transparent text-sm outline-none" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search name, email or phone" />
                        </label>
                        <Button type="submit">Search</Button>
                    </form>
                    <div className="sm:w-44"><AppSelect aria-label="Country" value={filters.country || 'all'} onChange={(v) => go({ country: v === 'all' ? '' : v })} options={[{ value: 'all', label: 'All countries' }, ...countries.map((c) => ({ value: c, label: c }))]} /></div>
                    <div className="sm:w-36"><AppSelect aria-label="Age" value={filters.age || 'all'} onChange={(v) => go({ age: v === 'all' ? '' : v })} options={[{ value: 'all', label: 'All ages' }, ...ageBands.map((a) => ({ value: a, label: AGE_LABEL[a] ?? a }))]} /></div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">User</th>
                                <th className="px-4 py-3 font-medium">Location</th>
                                <th className="px-4 py-3 font-medium">Audience</th>
                                <th className="px-4 py-3 font-medium">Roles</th>
                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {users.data.length === 0 ? (
                                <tr><td colSpan={5} className="px-4 py-10 text-center text-sm text-muted-foreground">No users match these filters.</td></tr>
                            ) : users.data.map((u) => (
                                <tr key={u.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{u.name}{!u.profile_complete && <span className="ml-2 text-[11px] font-normal text-muted-foreground">(profile incomplete)</span>}</div>
                                        <div className="text-xs text-muted-foreground">{u.email}{u.phone ? ` · ${u.phone}` : ''}</div>
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">{[u.city, u.country].filter(Boolean).join(', ') || '—'}</td>
                                    <td className="px-4 py-3 text-muted-foreground capitalize">{[u.gender && u.gender !== 'na' ? u.gender : null, u.age_band ? (AGE_LABEL[u.age_band] ?? u.age_band) : null].filter(Boolean).join(' · ') || '—'}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-1">
                                            {u.roles.length ? u.roles.map((r) => <Badge key={r} variant="secondary" className="capitalize">{r}</Badge>) : <span className="text-xs text-muted-foreground">—</span>}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-2">
                                            <Button asChild variant="ghost" size="icon" className="size-8" title="View details">
                                                <Link href={`/admin/users/${u.id}`} aria-label={`View ${u.name}`}><Eye className="size-4" /></Link>
                                            </Button>
                                            <Button variant={u.is_superadmin ? 'outline' : 'default'} size="sm" onClick={() => toggle(u)}>
                                                <ShieldCheck className="size-3.5" /> {u.is_superadmin ? 'Revoke admin' : 'Make admin'}
                                            </Button>
                                        </div>
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
