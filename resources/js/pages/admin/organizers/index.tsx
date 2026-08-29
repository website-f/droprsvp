import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, ExternalLink, Globe, Phone, X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Application {
    id: number; name: string | null; email: string | null; business_name: string | null; website: string | null;
    phone: string | null; bio: string | null; poster: string | null; gallery: string[]; status: string; reason: string | null; submitted_at: string | null;
}
interface Paginated { data: Application[]; prev_page_url: string | null; next_page_url: string | null }
interface Props { applications: Paginated; filters: { status: string }; counts: { pending: number; approved: number; rejected: number } }

const STATUS_TONE: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = { pending: 'secondary', approved: 'default', rejected: 'destructive', incomplete: 'outline' };

function AppCard({ app }: { app: Application }) {
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');

    const approve = () => router.post(`/admin/organizers/${app.id}/approve`, {}, { preserveScroll: true });
    const reject = () => reason.trim() && router.post(`/admin/organizers/${app.id}/reject`, { reason }, { preserveScroll: true, onSuccess: () => setRejecting(false) });

    return (
        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold">{app.business_name || app.name}</span>
                        <Badge variant={STATUS_TONE[app.status] ?? 'outline'} className="capitalize">{app.status}</Badge>
                        {app.submitted_at && <span className="text-xs text-muted-foreground">· {app.submitted_at}</span>}
                    </div>
                    <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span>{app.name} · <a href={`mailto:${app.email}`} className="hover:text-foreground">{app.email}</a></span>
                        {app.phone && <a href={`tel:${app.phone}`} className="flex items-center gap-1 hover:text-foreground"><Phone className="size-3" /> {app.phone}</a>}
                        {app.website && <a href={app.website} target="_blank" rel="noopener" className="flex items-center gap-1 hover:text-foreground"><Globe className="size-3" /> Website <ExternalLink className="size-3" /></a>}
                    </div>
                </div>
                {app.status !== 'approved' && (
                    <div className="flex shrink-0 items-center gap-2">
                        <Button size="sm" onClick={approve}><Check className="size-3.5" /> Approve</Button>
                        <Button size="sm" variant="outline" onClick={() => setRejecting((v) => !v)}><X className="size-3.5" /> Reject</Button>
                    </div>
                )}
            </div>

            {app.bio && <p className="mt-3 whitespace-pre-line text-sm text-foreground/80">{app.bio}</p>}

            {(app.poster || app.gallery.length > 0) && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {app.poster && <img src={app.poster} alt="Poster" className="h-24 w-auto rounded-lg border border-border object-cover" />}
                    {app.gallery.map((g, i) => <img key={i} src={g} alt="" className="size-16 rounded-lg border border-border object-cover" />)}
                </div>
            )}

            {app.status === 'rejected' && app.reason && (
                <p className="mt-3 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive"><span className="font-medium">Rejected:</span> {app.reason}</p>
            )}

            {rejecting && (
                <div className="mt-3 grid gap-2 rounded-xl border border-border p-3">
                    <textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason (shown to the applicant so they can re-apply)…" className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                    <div className="flex gap-2">
                        <Button size="sm" variant="destructive" onClick={reject} disabled={!reason.trim()}>Confirm reject</Button>
                        <Button size="sm" variant="ghost" onClick={() => setRejecting(false)}>Cancel</Button>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function OrganizersIndex({ applications, filters, counts }: Props) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const go = (status: string) => router.get('/admin/organizers', status === 'all' ? {} : { status }, { preserveScroll: true, preserveState: true });

    const tabs = [
        { v: 'pending', l: `Pending (${counts.pending})` },
        { v: 'approved', l: `Approved (${counts.approved})` },
        { v: 'rejected', l: `Rejected (${counts.rejected})` },
        { v: 'all', l: 'All' },
    ];

    return (
        <>
            <Head title="Organizers" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <h1 className="mb-1 text-2xl font-bold tracking-tight">Organizer applications</h1>
                <p className="mb-5 text-sm text-muted-foreground">Review vendors applying to host events. Approving lets them into the host area.</p>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <div className="mb-5 flex flex-wrap gap-2">
                    {tabs.map((t) => (
                        <button key={t.v} onClick={() => go(t.v)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.status === t.v ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{t.l}</button>
                    ))}
                </div>

                {applications.data.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">No applications here.</p>
                ) : (
                    <div className="grid gap-3">
                        {applications.data.map((a) => <AppCard key={a.id} app={a} />)}
                    </div>
                )}

                {(applications.prev_page_url || applications.next_page_url) && (
                    <div className="mt-8 flex justify-between">
                        <Button asChild variant="outline" disabled={!applications.prev_page_url}>{applications.prev_page_url ? <Link href={applications.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                        <Button asChild variant="outline" disabled={!applications.next_page_url}>{applications.next_page_url ? <Link href={applications.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

OrganizersIndex.layout = { breadcrumbs: [{ title: 'Organizers', href: '/admin/organizers' }] };
