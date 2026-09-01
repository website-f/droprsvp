import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Calendar, Check, ExternalLink, Globe, Mail, Phone, X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Application {
    id: number; name: string | null; email: string | null; member_since: string | null;
    business_name: string | null; website: string | null; phone: string | null; bio: string | null;
    poster: string | null; gallery: string[]; status: string; reason: string | null;
    submitted_at: string | null; reviewed_at: string | null;
}

const STATUS_TONE: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = { pending: 'secondary', approved: 'default', rejected: 'destructive', incomplete: 'outline' };

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
            <div className="mt-1 text-sm">{children}</div>
        </div>
    );
}

export default function OrganizerShow({ application: app }: { application: Application }) {
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');

    const approve = () => router.post(`/admin/organizers/${app.id}/approve`);
    const reject = () => reason.trim() && router.post(`/admin/organizers/${app.id}/reject`, { reason });

    return (
        <>
            <Head title={`${app.business_name || app.name} — application`} />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <Link href="/admin/organizers" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> All applications</Link>

                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight">{app.business_name || app.name}</h1>
                            <Badge variant={STATUS_TONE[app.status] ?? 'outline'} className="capitalize">{app.status}</Badge>
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">Applied by {app.name}{app.submitted_at ? ` · ${app.submitted_at}` : ''}</p>
                    </div>
                    {app.status !== 'approved' && (
                        <div className="flex shrink-0 items-center gap-2">
                            <Button onClick={approve}><Check className="size-4" /> Approve</Button>
                            <Button variant="outline" onClick={() => setRejecting((v) => !v)}><X className="size-4" /> Reject</Button>
                        </div>
                    )}
                </div>

                {app.status === 'rejected' && app.reason && (
                    <p className="mt-4 rounded-xl bg-destructive/10 px-4 py-3 text-sm text-destructive"><span className="font-medium">Previously rejected:</span> {app.reason}</p>
                )}

                {rejecting && (
                    <div className="mt-4 grid gap-2 rounded-xl border border-border p-4">
                        <label className="text-sm font-medium">Reason for rejection</label>
                        <textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Shown to the applicant so they can fix it and re-apply…" className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                        <div className="flex gap-2">
                            <Button variant="destructive" onClick={reject} disabled={!reason.trim()}>Confirm rejection</Button>
                            <Button variant="ghost" onClick={() => setRejecting(false)}>Cancel</Button>
                        </div>
                    </div>
                )}

                {/* Contact + business */}
                <div className="mt-6 grid gap-5 rounded-2xl border border-border bg-card p-5 shadow-sm sm:grid-cols-2">
                    <Field label="Contact person">{app.name}</Field>
                    <Field label="Email"><a href={`mailto:${app.email}`} className="inline-flex items-center gap-1.5 hover:underline"><Mail className="size-3.5" /> {app.email}</a></Field>
                    <Field label="Phone">{app.phone ? <a href={`tel:${app.phone}`} className="inline-flex items-center gap-1.5 hover:underline"><Phone className="size-3.5" /> {app.phone}</a> : <span className="text-muted-foreground">—</span>}</Field>
                    <Field label="Website">{app.website ? <a href={app.website} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 hover:underline"><Globe className="size-3.5" /> {app.website} <ExternalLink className="size-3" /></a> : <span className="text-muted-foreground">—</span>}</Field>
                    <Field label="Member since"><span className="inline-flex items-center gap-1.5"><Calendar className="size-3.5" /> {app.member_since ?? '—'}</span></Field>
                    {app.reviewed_at && <Field label="Last reviewed">{app.reviewed_at}</Field>}
                </div>

                {/* Bio */}
                {app.bio && (
                    <div className="mt-4 rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">About their events</div>
                        <p className="mt-2 whitespace-pre-line text-sm text-foreground/90">{app.bio}</p>
                    </div>
                )}

                {/* Poster + gallery */}
                {(app.poster || app.gallery.length > 0) && (
                    <div className="mt-4 rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Poster &amp; gallery</div>
                        <div className="mt-3 flex flex-wrap gap-3">
                            {app.poster && <img src={app.poster} alt="Poster" className="h-40 w-auto rounded-xl border border-border object-cover" />}
                            {app.gallery.map((g, i) => <img key={i} src={g} alt="" className="size-28 rounded-xl border border-border object-cover" />)}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

OrganizerShow.layout = { breadcrumbs: [{ title: 'Organizers', href: '/admin/organizers' }, { title: 'Application', href: '#' }] };
