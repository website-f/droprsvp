import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Globe, Phone } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Application {
    id: number; name: string | null; email: string | null; business_name: string | null; website: string | null;
    phone: string | null; status: string; submitted_at: string | null;
}
interface Paginated { data: Application[]; prev_page_url: string | null; next_page_url: string | null }
interface Props { applications: Paginated; filters: { status: string }; counts: { pending: number; approved: number; rejected: number } }

const STATUS_TONE: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = { pending: 'secondary', approved: 'default', rejected: 'destructive', incomplete: 'outline' };

function Row({ app }: { app: Application }) {
    return (
        <Link href={`/admin/organizers/${app.id}`} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-foreground/30">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate font-semibold">{app.business_name || app.name}</span>
                    <Badge variant={STATUS_TONE[app.status] ?? 'outline'} className="capitalize">{app.status}</Badge>
                    {app.submitted_at && <span className="text-xs text-muted-foreground">· {app.submitted_at}</span>}
                </div>
                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span className="truncate">{app.name} · {app.email}</span>
                    {app.phone && <span className="flex items-center gap-1"><Phone className="size-3" /> {app.phone}</span>}
                    {app.website && <span className="flex items-center gap-1"><Globe className="size-3" /> Website</span>}
                </div>
            </div>
            <span className="flex shrink-0 items-center gap-1 text-sm font-medium">View application <ArrowRight className="size-4" /></span>
        </Link>
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
                <p className="mb-5 text-sm text-muted-foreground">Review vendors applying to host events. Open an application to see everything before you decide.</p>
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
                        {applications.data.map((a) => <Row key={a.id} app={a} />)}
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
