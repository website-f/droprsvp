import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Download, Eye, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

interface Row {
    id: number; name: string; email: string; phone: string | null; gender: string | null; age_band: string | null;
    city: string | null; country: string | null; roles: string[]; events: number; profile_complete: boolean; is_superadmin: boolean;
}
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null }
interface Filters { q: string; role: string; country: string; age: string }
interface Props { users: Paginated; filters: Filters; countries: string[]; ageBands: string[] }

const AGE_LABEL: Record<string, string> = { 'under-18': 'Under 18', '18-24': '18–24', '25-34': '25–34', '35-44': '35–44', '45-54': '45–54', '55+': '55+' };
const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/** The single "primary" role of a user, for the role selector. */
const roleOf = (u: Row): string => (u.roles.includes('superadmin') ? 'superadmin' : u.roles.includes('staff') ? 'staff' : u.roles.includes('organizer') ? 'organizer' : 'normal');

export default function AdminUsers({ users, filters, countries, ageBands }: Props) {
    const [q, setQ] = useState(filters.q);
    const page = usePage().props;
    const flash = page.flash as { success?: string; error?: string; temp_credentials?: { email: string; password: string } } | undefined;
    const isSuperadmin = !!page.auth?.is_superadmin;
    const [addOpen, setAddOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const add = useForm({ name: '', email: '', role: 'normal' });

    const ROLE_TABS = [
        { v: 'all', l: 'All' }, { v: 'normal', l: 'Normal users' }, { v: 'organizer', l: 'Organizers' },
        { v: 'staff', l: 'Staff' }, { v: 'superadmin', l: 'Superadmins' },
    ];
    // Roles the current admin may assign (only a superadmin can mint staff/superadmins).
    const roleOptions = [
        { value: 'normal', label: 'Normal user' }, { value: 'organizer', label: 'Organizer' },
        ...(isSuperadmin ? [{ value: 'staff', label: 'Staff (admin)' }, { value: 'superadmin', label: 'Superadmin' }] : []),
    ];

    const submitAdd = (e: React.FormEvent) => {
        e.preventDefault();
        add.post('/admin/users', { preserveScroll: true, onSuccess: () => {
 add.reset(); setAddOpen(false); 
} });
    };
    const changeRole = (u: Row, role: string) => {
        if (role === roleOf(u)) {
            return;
        }

        router.post(`/admin/users/${u.id}/role`, { role }, { preserveScroll: true });
    };
    const copyTemp = () => {
        if (flash?.temp_credentials) {
            void navigator.clipboard.writeText(`Email: ${flash.temp_credentials.email}\nTemporary password: ${flash.temp_credentials.password}`);
            setCopied(true);
        }
    };

    const go = (patch: Partial<Filters>) => {
        const next = { ...filters, q, ...patch };
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v && v !== 'all'));
        router.get('/admin/users', params, { preserveState: true, preserveScroll: true });
    };
    const exportUrl = '/admin/users/export?' + new URLSearchParams(Object.entries({ ...filters, q }).filter(([, v]) => v && v !== 'all') as [string, string][]).toString();

    return (
        <>
            <Head title="Users" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-bold tracking-tight">Users</h1>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => {
 add.reset(); add.clearErrors(); setAddOpen(true); 
}}><Plus className="size-4" /> Add user</Button>
                        <Button asChild variant="outline"><a href={exportUrl}><Download className="size-4" /> Export CSV</a></Button>
                    </div>
                </div>
                {flash?.success && !flash?.temp_credentials && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}
                {flash?.error && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{flash.error}</div>}

                {/* One-time temp-password display for a just-created account. */}
                {flash?.temp_credentials && (
                    <div className="mb-4 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-4">
                        <div className="text-sm font-semibold text-emerald-700 dark:text-emerald-300">User added — share these credentials securely</div>
                        <p className="mt-0.5 text-xs text-muted-foreground">They’ll be asked to set their own password the first time they log in. This password won’t be shown again.</p>
                        <div className="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card px-3 py-2 font-mono text-sm">
                            <span>{flash.temp_credentials.email}</span>
                            <span className="text-muted-foreground">·</span>
                            <span className="font-semibold">{flash.temp_credentials.password}</span>
                            <Button type="button" size="sm" variant="outline" className="ml-auto" onClick={copyTemp}>{copied ? <><Check className="size-3.5" /> Copied</> : <><Copy className="size-3.5" /> Copy</>}</Button>
                        </div>
                    </div>
                )}

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
                                            {/* Role selector — self is view-only (can't self-revoke); staff can't
                                                touch privileged accounts (server enforces this too). */}
                                            <div className="w-40">
                                                <AppSelect
                                                    aria-label={`Role for ${u.name}`}
                                                    value={roleOf(u)}
                                                    onChange={(v) => changeRole(u, v)}
                                                    disabled={u.id === page.auth?.user?.id || (!isSuperadmin && (u.roles.includes('superadmin') || u.roles.includes('staff')))}
                                                    options={roleOptions.some((o) => o.value === roleOf(u)) ? roleOptions : [...roleOptions, { value: roleOf(u), label: roleOf(u) }]}
                                                />
                                            </div>
                                            <Button asChild variant="ghost" size="icon" className="size-8" title="View details">
                                                <Link href={`/admin/users/${u.id}`} aria-label={`View ${u.name}`}><Eye className="size-4" /></Link>
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

            {/* Add user — creates an account with a temporary password they must change on first login. */}
            <Dialog open={addOpen} onOpenChange={setAddOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Add a user</DialogTitle>
                        <DialogDescription>We’ll generate a temporary password. They’ll be forced to set their own the first time they log in.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitAdd} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="u-name">Full name</Label>
                            <input id="u-name" className={input} value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} />
                            {add.errors.name && <p className="text-xs text-destructive">{add.errors.name}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="u-email">Email</Label>
                            <input id="u-email" type="email" className={input} value={add.data.email} onChange={(e) => add.setData('email', e.target.value)} />
                            {add.errors.email && <p className="text-xs text-destructive">{add.errors.email}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Role</Label>
                            <AppSelect value={add.data.role} onChange={(v) => add.setData('role', v)} options={roleOptions} />
                            {add.errors.role && <p className="text-xs text-destructive">{add.errors.role}</p>}
                        </div>
                        <DialogFooter className="mt-2 gap-2">
                            <Button type="button" variant="ghost" onClick={() => setAddOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={add.processing}>{add.processing ? 'Adding…' : 'Add user'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

AdminUsers.layout = {
    breadcrumbs: [{ title: 'Users', href: '/admin/users' }],
};
