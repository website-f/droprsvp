import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Globe, Phone } from 'lucide-react';
import { Pagination } from '@/components/pagination';
import type { Paginator } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';

interface Application {
    id: number; name: string | null; email: string | null; business_name: string | null; website: string | null;
    phone: string | null; status: string; is_appeal?: boolean; submitted_at: string | null;
    country?: { code: string; name: string; flag: string } | null;
}
interface CountryCount { code: string; name: string; flag: string; total: number }
interface Paginated extends Paginator { data: Application[]; prev_page_url: string | null; next_page_url: string | null }
interface Props {
    applications: Paginated; filters: { status: string; country?: string };
    counts: { pending: number; appealed: number; approved: number; rejected: number }; countries?: CountryCount[];
}

const STATUS_TONE: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = { pending: 'secondary', approved: 'default', rejected: 'destructive', incomplete: 'outline' };

function Row({ app }: { app: Application }) {
    return (
        <Link href={`/admin/organizers/${app.id}`} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-foreground/30">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate font-semibold">{app.business_name || app.name}</span>
                    {app.is_appeal
                        ? <Badge className="bg-amber-500 text-white hover:bg-amber-500">Appeal</Badge>
                        : <Badge variant={STATUS_TONE[app.status] ?? 'outline'} className="capitalize">{app.status}</Badge>}
                    {app.country && <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs" title={app.country.name}><span aria-hidden>{app.country.flag}</span> {app.country.name}</span>}
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

export default function OrganizersIndex({ applications, filters, counts, countries = [] }: Props) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const country = filters.country ?? '';
    const go = (status: string, inCountry = country) =>
        router.get('/admin/organizers', { status, ...(inCountry ? { country: inCountry } : {}) }, { preserveScroll: true, preserveState: true });

    const tabs = [
        { v: 'pending', l: `Pending (${counts.pending})` },
        { v: 'appealed', l: `Appeals (${counts.appealed})` },
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

                <div className="mb-5 flex flex-wrap items-center gap-2">
                    {tabs.map((t) => (
                        <button key={t.v} onClick={() => go(t.v)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.status === t.v ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{t.l}</button>
                    ))}
                    {countries.length > 0 && (
                        <select aria-label="Country" value={country} onChange={(e) => go(filters.status, e.target.value)}
                            className="ml-auto h-9 rounded-full border border-border bg-background px-3 text-sm">
                            <option value="">All countries</option>
                            {countries.map((c) => <option key={c.code} value={c.code}>{c.flag} {c.name} ({c.total})</option>)}
                        </select>
                    )}
                </div>

                {applications.data.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">No applications here.</p>
                ) : (
                    <div className="grid gap-3">
                        {applications.data.map((a) => <Row key={a.id} app={a} />)}
                    </div>
                )}

                <Pagination paginator={applications} noun="applications" preserveScroll={false} className="mt-8" />
            </div>
        </>
    );
}

OrganizersIndex.layout = { breadcrumbs: [{ title: 'Organizers', href: '/admin/organizers' }] };
